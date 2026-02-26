<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Icc;

use MagicSunday\ImageMeta\Contract\IccParserInterface;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\PayloadGuard;
use MagicSunday\ImageMeta\Model\Icc\IccProfile;
use MagicSunday\ImageMeta\Model\Icc\IccTag;

use function array_key_exists;
use function ord;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Decodes ICC profiles to expose header information and human readable tags.
 *
 * Orchestrates segment assembly, header decoding, and tag extraction by delegating
 * to focused sub-classes: IccBinaryReader, IccHeaderDecoder, and IccTagDecoder.
 */
final readonly class IccParser implements IccParserInterface
{
    private const int HEADER_LENGTH = 128;

    private const string ICC_SIGNATURE = 'ICC_PROFILE\0';

    /**
     * ICC.1:2022 §7.2.9: Profile file signature field must contain 'acsp' (61637370h).
     */
    private const string PROFILE_SIGNATURE = 'acsp';

    private IccBinaryReader $binaryReader;

    private IccHeaderDecoder $headerDecoder;

    private IccTagDecoder $tagDecoder;

    /**
     * @param int $maxIccProfileSize Maximum combined ICC profile size in bytes.
     */
    public function __construct(
        private int $maxIccProfileSize = 4_194_304,
    ) {
        $this->binaryReader  = new IccBinaryReader();
        $this->headerDecoder = new IccHeaderDecoder($this->binaryReader);
        $this->tagDecoder    = new IccTagDecoder($this->binaryReader);
    }

    /**
     * Decodes the ICC profile payload by extracting header fields and well known tags.
     *
     * ICC.1:2022 §7 defines the profile header structure and §9 defines common tags.
     *
     * @param string|null        $profileData Raw ICC profile data when a complete payload is available.
     * @param array<int, string> $segments    ICC segments collected from APP2 markers ordered by appearance.
     *
     * @throws ParseError
     */
    public function decode(?string $profileData, array $segments = []): ?IccProfile
    {
        $data = $this->selectDecodeInput($profileData, $segments);

        // No ICC data at all — return null (absence, not error)
        if ($data === null) {
            return null;
        }

        ['data' => $data, 'profileSize' => $profileSize] = $this->validateAndNormalizeProfileData($data);

        // Postel's Law: ICC.1:2022 §7.2.19 requires bytes 100-127 to be zero,
        // but many widely deployed profiles (e.g. the ubiquitous Heidelberger
        // "Lino" sRGB profile) have non-zero reserved bytes.  Tolerate silently
        // — the reserved field has no semantic impact on tag extraction.

        // Postel's Law: ICC.1:2022 §7.1 requires contiguous tag data with NULL
        // padding after the tag table.  Many real-world profiles have padding
        // gaps, overlapping tags, or non-zero padding bytes.  Since tags are
        // accessed by their individual offset+size, layout deviations are
        // harmless for data extraction.  Skip the layout check.

        $headerFields = $this->decodeHeaderFields($data);
        $tagFields    = $this->decodeTagFields($data, $profileSize, $headerFields['majorVersion']);

        // Tolerate unknown platform and non-printable signature bytes.

        return new IccProfile(
            description: $tagFields['description'],
            copyright: $tagFields['copyright'],
            whitePoint: $tagFields['whitePoint'],
            blackPoint: $tagFields['blackPoint'],
            redMatrixColumn: $tagFields['redMatrixColumn'],
            greenMatrixColumn: $tagFields['greenMatrixColumn'],
            blueMatrixColumn: $tagFields['blueMatrixColumn'],
            luminance: $tagFields['luminance'],
            redTRC: $tagFields['redTRC'],
            greenTRC: $tagFields['greenTRC'],
            blueTRC: $tagFields['blueTRC'],
            deviceMfgDesc: $tagFields['deviceMfgDesc'],
            deviceModelDesc: $tagFields['deviceModelDesc'],
            technology: $tagFields['technology'],
            viewingConditions: $tagFields['viewingConditions'],
            measurement: $tagFields['measurement'],
            version: $headerFields['version'],
            pcs: $headerFields['pcs'],
            renderingIntent: $headerFields['renderingIntent'],
            profileId: $headerFields['profileId'],
            cmmType: $headerFields['cmmType'],
            profileClass: $headerFields['profileClass'],
            colorSpace: $headerFields['colorSpace'],
            profileDateTime: $headerFields['profileDateTime'],
            profileDateTimeUtc: $headerFields['profileDateTimeUtc'],
            profileSignature: $headerFields['profileSignature'],
            profileFlags: $headerFields['profileFlags'],
            primaryPlatform: $headerFields['primaryPlatform'],
            deviceManufacturer: $headerFields['deviceManufacturer'],
            deviceModel: $headerFields['deviceModel'],
            deviceAttributes: $headerFields['deviceAttributes'],
            profileCreator: $headerFields['profileCreator'],
            illuminant: $headerFields['illuminant'],
        );
    }

    /**
     * Selects profile input by preferring complete payloads and falling back to segment assembly.
     *
     * @param string|null        $profileData Raw ICC payload when available.
     * @param array<int, string> $segments    APP2 ICC segments.
     */
    private function selectDecodeInput(?string $profileData, array $segments): ?string
    {
        $data = $profileData;
        if ($data === null || strlen($data) < self::HEADER_LENGTH) {
            $combined = $this->combineSegments($segments);
            if ($combined !== null) {
                $data = $combined;
            }
        }

        return $data;
    }

    /**
     * Validates and normalizes ICC payload size/signature constraints before field extraction.
     *
     * @param string $data Candidate ICC payload.
     *
     * @return array{data: string, profileSize: int}
     */
    private function validateAndNormalizeProfileData(string $data): array
    {
        // ICC data present but too short — malformed
        PayloadGuard::ensureMinimumLength($data, self::HEADER_LENGTH, 'ICC profile data', 1442);

        $profileSize = $this->binaryReader->uInt32Be(substr($data, IccTag::PROFILE_SIZE, 4));
        $length      = strlen($data);

        // ICC.1:2022 §7.2.2: Profile size must be at least the 128-byte header.
        if ($profileSize < self::HEADER_LENGTH) {
            throw new ParseError(
                sprintf('ICC declared profile size %d is less than the minimum header length %d', $profileSize, self::HEADER_LENGTH),
                1443,
            );
        }

        // Tolerate misaligned profile sizes and trailing bytes beyond declared size.
        // Use the declared size when payload has trailing bytes; use actual length when truncated.
        if ($profileSize < $length) {
            $data = substr($data, 0, $profileSize);
        }

        // ICC.1:2022 §7.2.9: Validate 'acsp' signature at bytes 36-39.
        $signature = substr($data, 36, 4);
        if ($signature !== self::PROFILE_SIGNATURE) {
            throw new ParseError(
                sprintf('ICC profile signature "%s" at offset 36 is not the required "acsp"', $signature),
                1446,
            );
        }

        return ['data' => $data, 'profileSize' => $profileSize];
    }

    /**
     * Decodes ICC header-derived fields from the normalized payload.
     *
     * @param string $data Normalized ICC payload.
     *
     * @return array{
     *   version: string|null,
     *   profileClass: string|null,
     *   colorSpace: string|null,
     *   pcs: string|null,
     *   renderingIntent: string|null,
     *   profileId: string|null,
     *   majorVersion: int,
     *   cmmType: string|null,
     *   profileDateTime: string|null,
     *   profileDateTimeUtc: string|null,
     *   profileSignature: string|null,
     *   profileFlags: string|null,
     *   primaryPlatform: string|null,
     *   deviceManufacturer: string|null,
     *   deviceModel: string|null,
     *   deviceAttributes: string|null,
     *   profileCreator: string|null,
     *   illuminant: array{x: float, y: float, z: float}|null
     * }
     */
    private function decodeHeaderFields(string $data): array
    {
        // Tolerate non-zero reserved bytes in version field — extract major.minor only.
        $version = $this->headerDecoder->extractVersion($data);

        $profileClass = $this->headerDecoder->extractSignature(substr($data, IccTag::PROFILE_CLASS, 4));
        $colorSpace   = $this->headerDecoder->extractSignature(substr($data, IccTag::COLOR_SPACE, 4));
        $pcs          = $this->headerDecoder->extractSignature(substr($data, IccTag::PCS, 4));

        // Tolerate unknown profile class, colour space, and PCS signatures.

        $renderingIntent    = $this->headerDecoder->extractRenderingIntent($data);
        $profileId          = $this->headerDecoder->extractProfileId($data);
        $majorVersion       = ord($data[8]);
        $cmmType            = $this->headerDecoder->extractSignature(substr($data, IccTag::CMM_TYPE, 4));
        $profileDateTime    = $this->headerDecoder->extractProfileDateTime($data);
        $profileDateTimeUtc = $profileDateTime !== null ? ($profileDateTime . 'Z') : null;
        $profileSignature   = $this->headerDecoder->extractSignature(substr($data, IccTag::PROFILE_SIGNATURE, 4));
        $profileFlags       = $this->headerDecoder->extractHexField($data, IccTag::PROFILE_FLAGS, 4, true);
        $primaryPlatform    = $this->headerDecoder->extractSignature(substr($data, IccTag::PRIMARY_PLATFORM, 4));
        $deviceManufacturer = $this->headerDecoder->extractSignature(substr($data, IccTag::DEVICE_MANUFACTURER, 4));
        $deviceModel        = $this->headerDecoder->extractSignature(substr($data, IccTag::DEVICE_MODEL, 4));
        $deviceAttributes   = $this->headerDecoder->extractHexField($data, IccTag::DEVICE_ATTRIBUTES, 8, true);
        $profileCreator     = $this->headerDecoder->extractSignature(substr($data, IccTag::PROFILE_CREATOR, 4));
        $illuminant         = $this->headerDecoder->extractIlluminant($data);

        return [
            'version'            => $version,
            'profileClass'       => $profileClass,
            'colorSpace'         => $colorSpace,
            'pcs'                => $pcs,
            'renderingIntent'    => $renderingIntent,
            'profileId'          => $profileId,
            'majorVersion'       => $majorVersion,
            'cmmType'            => $cmmType,
            'profileDateTime'    => $profileDateTime,
            'profileDateTimeUtc' => $profileDateTimeUtc,
            'profileSignature'   => $profileSignature,
            'profileFlags'       => $profileFlags,
            'primaryPlatform'    => $primaryPlatform,
            'deviceManufacturer' => $deviceManufacturer,
            'deviceModel'        => $deviceModel,
            'deviceAttributes'   => $deviceAttributes,
            'profileCreator'     => $profileCreator,
            'illuminant'         => $illuminant,
        ];
    }

    /**
     * Decodes ICC tag-derived fields from the normalized payload.
     *
     * @param string $data         Normalized ICC payload.
     * @param int    $profileSize  Declared ICC profile size.
     * @param int    $majorVersion Parsed ICC major version.
     *
     * @return array{
     *   description: string|null,
     *   copyright: string|null,
     *   whitePoint: array{x: float, y: float, z: float}|null,
     *   blackPoint: array{x: float, y: float, z: float}|null,
     *   redMatrixColumn: array{x: float, y: float, z: float}|null,
     *   greenMatrixColumn: array{x: float, y: float, z: float}|null,
     *   blueMatrixColumn: array{x: float, y: float, z: float}|null,
     *   luminance: array{x: float, y: float, z: float}|null,
     *   redTRC: array{gamma: float}|array{table: list<int>}|null,
     *   greenTRC: array{gamma: float}|array{table: list<int>}|null,
     *   blueTRC: array{gamma: float}|array{table: list<int>}|null,
     *   deviceMfgDesc: string|null,
     *   deviceModelDesc: string|null,
     *   technology: string|null,
     *   viewingConditions: array{
     *     illuminant: array{x: float, y: float, z: float},
     *     surround: array{x: float, y: float, z: float},
     *     illuminantType: int
     *   }|null,
     *   measurement: array{
     *     observer: int,
     *     backing: array{x: float, y: float, z: float},
     *     geometry: int,
     *     flare: float,
     *     illuminant: int
     *   }|null
     * }
     */
    private function decodeTagFields(string $data, int $profileSize, int $majorVersion): array
    {
        $description       = $this->tagDecoder->extractTag($data, $profileSize, 'desc', $majorVersion);
        $copyright         = $this->tagDecoder->extractTag($data, $profileSize, 'cprt', $majorVersion);
        $whitePoint        = $this->tagDecoder->extractWhitePoint($data, $profileSize);
        $blackPoint        = $this->tagDecoder->extractXyzTag($data, $profileSize, 'bkpt');
        $redMatrixColumn   = $this->tagDecoder->extractXyzTag($data, $profileSize, 'rXYZ');
        $greenMatrixColumn = $this->tagDecoder->extractXyzTag($data, $profileSize, 'gXYZ');
        $blueMatrixColumn  = $this->tagDecoder->extractXyzTag($data, $profileSize, 'bXYZ');
        $luminance         = $this->tagDecoder->extractXyzTag($data, $profileSize, 'lumi');
        $redTRC            = $this->tagDecoder->extractTrcTag($data, $profileSize, 'rTRC');
        $greenTRC          = $this->tagDecoder->extractTrcTag($data, $profileSize, 'gTRC');
        $blueTRC           = $this->tagDecoder->extractTrcTag($data, $profileSize, 'bTRC');
        $deviceMfgDesc     = $this->tagDecoder->extractTag($data, $profileSize, 'dmnd', $majorVersion);
        $deviceModelDesc   = $this->tagDecoder->extractTag($data, $profileSize, 'dmdd', $majorVersion);
        $technology        = $this->tagDecoder->extractSignatureTag($data, $profileSize, 'tech');
        $viewingConditions = $this->tagDecoder->extractViewingConditions($data, $profileSize);
        $measurement       = $this->tagDecoder->extractMeasurement($data, $profileSize);

        return [
            'description'       => $description,
            'copyright'         => $copyright,
            'whitePoint'        => $whitePoint,
            'blackPoint'        => $blackPoint,
            'redMatrixColumn'   => $redMatrixColumn,
            'greenMatrixColumn' => $greenMatrixColumn,
            'blueMatrixColumn'  => $blueMatrixColumn,
            'luminance'         => $luminance,
            'redTRC'            => $redTRC,
            'greenTRC'          => $greenTRC,
            'blueTRC'           => $blueTRC,
            'deviceMfgDesc'     => $deviceMfgDesc,
            'deviceModelDesc'   => $deviceModelDesc,
            'technology'        => $technology,
            'viewingConditions' => $viewingConditions,
            'measurement'       => $measurement,
        ];
    }

    /**
     * Attempts to reconstruct the ICC payload from APP2 ICC segments.
     *
     * @param array<int, string> $segments Ordered ICC segments as extracted from the JPEG stream.
     */
    private function combineSegments(array $segments): ?string
    {
        if ($segments === []) {
            return null;
        }

        $sequence      = [];
        $expectedCount = null;

        foreach ($segments as $payload) {
            if (!str_starts_with($payload, self::ICC_SIGNATURE)) {
                continue;
            }

            $minLength = strlen(self::ICC_SIGNATURE) + 2;
            if (strlen($payload) <= $minLength) {
                continue;
            }

            $sequenceNumber = ord($payload[strlen(self::ICC_SIGNATURE)]);
            $sequenceCount  = ord($payload[strlen(self::ICC_SIGNATURE) + 1]);

            if ($sequenceCount === 0) {
                throw new ParseError('ICC chunk assembly: sequence count is zero', 1126);
            }

            if ($expectedCount === null) {
                $expectedCount = $sequenceCount;
            } elseif ($expectedCount !== $sequenceCount) {
                throw new ParseError(
                    sprintf(
                        'ICC chunk assembly: inconsistent sequence count (%d vs %d)',
                        $expectedCount,
                        $sequenceCount,
                    ),
                    1127,
                );
            }

            // Reject out-of-range sequence numbers
            if ($sequenceNumber === 0 || $sequenceNumber > $sequenceCount) {
                throw new ParseError(
                    sprintf(
                        'ICC chunk assembly: sequence number %d is out of range 1..%d',
                        $sequenceNumber,
                        $sequenceCount,
                    ),
                    1128,
                );
            }

            // Reject duplicate sequence numbers
            if (array_key_exists($sequenceNumber, $sequence)) {
                throw new ParseError(
                    sprintf('ICC chunk assembly: duplicate sequence number %d', $sequenceNumber),
                    1129,
                );
            }

            $sequence[$sequenceNumber] = substr($payload, $minLength);
        }

        if ($expectedCount === null) {
            return null;
        }

        $iccData        = '';
        $cumulativeSize = 0;

        for ($i = 1; $i <= $expectedCount; ++$i) {
            // Missing chunk in assembled sequence is an error, not absence
            if (!array_key_exists($i, $sequence)) {
                throw new ParseError(
                    sprintf(
                        'ICC chunk assembly: missing sequence number %d of %d',
                        $i,
                        $expectedCount,
                    ),
                    1441,
                );
            }

            $cumulativeSize += strlen($sequence[$i]);

            if ($cumulativeSize > $this->maxIccProfileSize) {
                throw new ParseError(
                    sprintf(
                        'ICC chunk assembly: combined profile size %d exceeds limit %d',
                        $cumulativeSize,
                        $this->maxIccProfileSize,
                    ),
                    1949,
                );
            }

            $iccData .= $sequence[$i];
        }

        return $iccData;
    }
}
