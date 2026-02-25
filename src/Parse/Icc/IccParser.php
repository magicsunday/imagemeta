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
use MagicSunday\ImageMeta\Model\Icc\IccTag;

use function array_key_exists;
use function bin2hex;
use function in_array;
use function ord;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strtoupper;
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

    /**
     * ICC.1:2022 Table 18: Allowed profile/device class signatures.
     *
     * @var list<string>
     */
    private const array ALLOWED_PROFILE_CLASSES = [
        'scnr', // Input device profile
        'mntr', // Display device profile
        'prtr', // Output device profile
        'link', // DeviceLink profile
        'spac', // ColorSpace profile
        'abst', // Abstract profile
        'nmcl', // NamedColor profile
    ];

    /**
     * ICC.1:2022 Table 19: Allowed data colour space signatures.
     *
     * @var list<string>
     */
    private const array ALLOWED_COLOR_SPACES = [
        'XYZ ', 'Lab ', 'Luv ', 'YCbr', 'Yxy ', 'RGB ', 'GRAY',
        'HSV ', 'HLS ', 'CMYK', 'CMY ', '2CLR', '3CLR', '4CLR',
        '5CLR', '6CLR', '7CLR', '8CLR', '9CLR', 'ACLR', 'BCLR',
        'CCLR', 'DCLR', 'ECLR', 'FCLR',
    ];

    /**
     * ICC.1:2022 §7.2.7: Allowed PCS signatures.
     *
     * @var list<string>
     */
    private const array ALLOWED_PCS = [
        'XYZ ',
        'Lab ',
    ];

    /**
     * ICC.1:2022 Table 20: Allowed primary platform signatures.
     *
     * @var list<string>
     */
    private const array ALLOWED_PLATFORMS = [
        'APPL', // Apple Computer, Inc.
        'MSFT', // Microsoft Corporation
        'SGI ', // Silicon Graphics, Inc.
        'SUNW', // Sun Microsystems, Inc.
    ];

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
     * @return array{
     *     description: string|null,
     *     copyright: string|null,
     *     whitePoint: array{x: float, y: float, z: float}|null,
     *     version: string|null,
     *     pcs: string|null,
     *     renderingIntent: string|null,
     *     profileId: string|null,
     *     cmmType: string|null,
     *     profileClass: string|null,
     *     colorSpace: string|null,
     *     profileDateTime: string|null,
     *     profileDateTimeUtc: string|null,
     *     profileSignature: string|null,
     *     profileFlags: string|null,
     *     primaryPlatform: string|null,
     *     deviceManufacturer: string|null,
     *     deviceModel: string|null,
     *     deviceAttributes: string|null,
     *     profileCreator: string|null,
     *     illuminant: array{x: float, y: float, z: float}|null,
     * }|null
     */
    public function decode(?string $profileData, array $segments = []): ?array
    {
        $data = $profileData;
        if ($data === null || strlen($data) < self::HEADER_LENGTH) {
            $combined = $this->combineSegments($segments);
            if ($combined !== null) {
                $data = $combined;
            }
        }

        // No ICC data at all — return null (absence, not error)
        if ($data === null) {
            return null;
        }

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

        // ICC.1:2022 §7.2.9: Validate 'acsp' signature at bytes 36-39
        $signature = substr($data, 36, 4);
        if ($signature !== self::PROFILE_SIGNATURE) {
            throw new ParseError(
                sprintf('ICC profile signature "%s" at offset 36 is not the required "acsp"', $signature),
                1446,
            );
        }

        // Postel's Law: ICC.1:2022 §7.2.19 requires bytes 100-127 to be zero,
        // but many widely deployed profiles (e.g. the ubiquitous Heidelberger
        // "Lino" sRGB profile) have non-zero reserved bytes.  Tolerate silently
        // — the reserved field has no semantic impact on tag extraction.

        // Postel's Law: ICC.1:2022 §7.1 requires contiguous tag data with NULL
        // padding after the tag table.  Many real-world profiles have padding
        // gaps, overlapping tags, or non-zero padding bytes.  Since tags are
        // accessed by their individual offset+size, layout deviations are
        // harmless for data extraction.  Skip the layout check.

        // Tolerate non-zero reserved bytes in version field — extract major.minor only.
        $version = $this->headerDecoder->extractVersion($data);

        $profileClass = $this->headerDecoder->extractSignature(substr($data, IccTag::PROFILE_CLASS, 4));
        $colorSpace   = $this->headerDecoder->extractSignature(substr($data, IccTag::COLOR_SPACE, 4));
        $pcs          = $this->headerDecoder->extractSignature(substr($data, IccTag::PCS, 4));

        // Validate constrained header signatures
        if ($profileClass !== null && !in_array($profileClass, self::ALLOWED_PROFILE_CLASSES, true)) {
            throw new ParseError(
                sprintf('ICC profile class signature "%s" is not in the allowed set', $profileClass),
                1134,
            );
        }

        if ($colorSpace !== null && !in_array($colorSpace, self::ALLOWED_COLOR_SPACES, true)) {
            throw new ParseError(
                sprintf('ICC data colour space signature "%s" is not in the allowed set', $colorSpace),
                1135,
            );
        }

        if ($pcs !== null && !in_array($pcs, self::ALLOWED_PCS, true)) {
            throw new ParseError(
                sprintf('ICC PCS signature "%s" is not XYZ or Lab', $pcs),
                1136,
            );
        }

        $renderingIntent    = $this->headerDecoder->extractRenderingIntent($data);
        $profileId          = $this->headerDecoder->extractProfileId($data);
        $majorVersion       = ord($data[8]);
        $description        = $this->tagDecoder->extractTag($data, $profileSize, 'desc', $majorVersion);
        $copyright          = $this->tagDecoder->extractTag($data, $profileSize, 'cprt', $majorVersion);
        $whitePoint         = $this->tagDecoder->extractWhitePoint($data, $profileSize);
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

        // Validate primary platform against ICC.1:2022 Table 20
        if ($primaryPlatform !== null && !in_array($primaryPlatform, self::ALLOWED_PLATFORMS, true)) {
            throw new ParseError(
                sprintf('ICC primary platform signature "%s" is not in the allowed set', $primaryPlatform),
                1143,
            );
        }

        // Validate profile creator as printable ASCII signature
        if ($profileCreator !== null && !$this->headerDecoder->isPrintableAsciiSignature($profileCreator)) {
            throw new ParseError(
                sprintf(
                    'ICC profile creator signature contains non-printable bytes: %s',
                    strtoupper(bin2hex($profileCreator)),
                ),
                1144,
            );
        }

        // Validate CMM type as printable ASCII signature
        if ($cmmType !== null && !$this->headerDecoder->isPrintableAsciiSignature($cmmType)) {
            throw new ParseError(
                sprintf(
                    'ICC CMM type signature contains non-printable bytes: %s',
                    strtoupper(bin2hex($cmmType)),
                ),
                1145,
            );
        }

        // Validate device manufacturer as printable ASCII signature
        if ($deviceManufacturer !== null && !$this->headerDecoder->isPrintableAsciiSignature($deviceManufacturer)) {
            throw new ParseError(
                sprintf(
                    'ICC device manufacturer signature contains non-printable bytes: %s',
                    strtoupper(bin2hex($deviceManufacturer)),
                ),
                1146,
            );
        }

        // Validate device model as printable ASCII signature
        if ($deviceModel !== null && !$this->headerDecoder->isPrintableAsciiSignature($deviceModel)) {
            throw new ParseError(
                sprintf(
                    'ICC device model signature contains non-printable bytes: %s',
                    strtoupper(bin2hex($deviceModel)),
                ),
                1147,
            );
        }

        // Validate profileFlags per ICC.1:2022 §7.2.11 / Table 21
        $this->headerDecoder->validateProfileFlags();

        // Validate deviceAttributes per ICC.1:2022 §7.2.14 / Table 22
        $this->headerDecoder->validateDeviceAttributes();

        $illuminant = $this->headerDecoder->extractIlluminant($data);

        return [
            'description'        => $description,
            'copyright'          => $copyright,
            'whitePoint'         => $whitePoint,
            'version'            => $version,
            'pcs'                => $pcs,
            'renderingIntent'    => $renderingIntent,
            'profileId'          => $profileId,
            'cmmType'            => $cmmType,
            'profileClass'       => $profileClass,
            'colorSpace'         => $colorSpace,
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
