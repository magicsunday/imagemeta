<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Model\Dng\DngTag;

use function array_any;
use function is_int;
use function sprintf;

/**
 * Validates DNG version constraints: backward-version gating, version validity,
 * consistency checks, required-version enforcement, and version-floor rules.
 *
 * DNG 1.7.1.0 defines version-gating rules that determine which tags and features
 * a file may use based on its declared DNG version and backward version.
 */
final readonly class DngVersionValidator
{
    /**
     * Minimum DNGBackwardVersion required for non-default interleave factor tags.
     *
     * @var array<int, list<int>>
     */
    private const array DNG_INTERLEAVE_MIN_VERSIONS = [
        DngTag::ROW_INTERLEAVE_FACTOR    => [1, 2, 0, 0],
        DngTag::COLUMN_INTERLEAVE_FACTOR => [1, 7, 1, 0],
    ];

    /**
     * Third-illuminant tags requiring DNGBackwardVersion >= 1.6.0.0.
     *
     * @var list<int>
     */
    private const array DNG_THIRD_ILLUMINANT_TAGS = [
        DngTag::CALIBRATION_ILLUMINANT_3,
        DngTag::COLOR_MATRIX_3,
        DngTag::FORWARD_MATRIX_3,
        DngTag::ILLUMINANT_DATA_3,
    ];

    /**
     * DNG sentinel tags whose presence implies the file is a DNG document.
     *
     * @var list<int>
     */
    private const array DNG_SENTINEL_TAGS = [
        DngTag::UNIQUE_CAMERA_MODEL,
    ];

    public function __construct(
        private DngValidationSupport $support,
    ) {
    }

    /**
     * Rejects DNG files whose DNGBackwardVersion exceeds the supported reader version.
     */
    public function validateDngBackwardVersionGate(Ifd $ifd): void
    {
        $bwVer = $this->support->getEffectiveDngBackwardVersion($ifd);

        if ($bwVer === null) {
            return;
        }

        if ($this->support->dngVersionLessThan(DngValidationSupport::SUPPORTED_DNG_VERSION, $bwVer)) {
            throw new ParseError(
                sprintf(
                    'DNGBackwardVersion %d.%d.%d.%d exceeds supported reader version %d.%d.%d.%d.',
                    $bwVer[0],
                    $bwVer[1],
                    $bwVer[2],
                    $bwVer[3],
                    ...DngValidationSupport::SUPPORTED_DNG_VERSION,
                ),
                1496,
            );
        }
    }

    /**
     * Validates the semantic contents of DNGVersion.
     *
     * Rejects zero tuples (e.g. 0.0.0.0) and versions beyond this library's
     * supported range per DNG 1.7.1.0.
     */
    public function validateDngVersionValidity(Ifd $ifd): void
    {
        $dngVer = $this->support->extractDngVersionTuple($ifd, DngTag::DNG_VERSION);

        if ($dngVer === null) {
            return;
        }

        if ($dngVer[0] === 0) {
            throw new ParseError(
                sprintf(
                    'DNGVersion %d.%d.%d.%d is invalid (zero major version).',
                    $dngVer[0],
                    $dngVer[1],
                    $dngVer[2],
                    $dngVer[3],
                ),
                1498,
            );
        }

        if ($this->support->dngVersionLessThan(DngValidationSupport::SUPPORTED_DNG_VERSION, $dngVer)) {
            throw new ParseError(
                sprintf(
                    'DNGVersion %d.%d.%d.%d exceeds supported version %d.%d.%d.%d.',
                    $dngVer[0],
                    $dngVer[1],
                    $dngVer[2],
                    $dngVer[3],
                    ...DngValidationSupport::SUPPORTED_DNG_VERSION,
                ),
                1499,
            );
        }
    }

    /**
     * Rejects DNG files where DNGBackwardVersion is higher than DNGVersion.
     */
    public function validateDngBackwardVersionConsistency(Ifd $ifd): void
    {
        $dngVer = $this->support->extractDngVersionTuple($ifd, DngTag::DNG_VERSION);

        if ($dngVer === null) {
            return;
        }

        $bwVer = $this->support->extractDngVersionTuple($ifd, DngTag::DNG_BACKWARD_VERSION);

        if ($bwVer === null) {
            return;
        }

        if ($this->support->dngVersionLessThan($dngVer, $bwVer)) {
            throw new ParseError(
                sprintf(
                    'DNGBackwardVersion %d.%d.%d.%d exceeds DNGVersion %d.%d.%d.%d.',
                    $bwVer[0],
                    $bwVer[1],
                    $bwVer[2],
                    $bwVer[3],
                    $dngVer[0],
                    $dngVer[1],
                    $dngVer[2],
                    $dngVer[3],
                ),
                1497,
            );
        }
    }

    /**
     * Requires DNGVersion in IFD0 when DNG-specific tags are present.
     */
    public function validateDngRequiredVersion(Ifd $ifd): void
    {
        if ($ifd->get(DngTag::DNG_VERSION) instanceof IfdEntry) {
            return;
        }

        foreach (self::DNG_SENTINEL_TAGS as $tag) {
            if ($ifd->get($tag) instanceof IfdEntry) {
                throw new ParseError(
                    sprintf(
                        'DNG tag 0x%04X found in IFD 0 but required DNGVersion tag is missing.',
                        $tag,
                    ),
                    1498,
                );
            }
        }
    }

    /**
     * Validates that non-default interleave factors have a sufficient DNGBackwardVersion.
     */
    public function validateDngInterleaveVersionFloors(Ifd $ifd): void
    {
        $bwVer = $this->support->getEffectiveDngBackwardVersion($ifd);

        if ($bwVer === null) {
            return;
        }

        foreach (self::DNG_INTERLEAVE_MIN_VERSIONS as $tag => $minVer) {
            $entry = $ifd->get($tag);

            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if (!is_int($entry->value)) {
                continue;
            }

            if ($entry->value <= 1) {
                continue;
            }

            if ($this->support->dngVersionLessThan($bwVer, $minVer)) {
                throw new ParseError(
                    sprintf(
                        'DNG tag 0x%04X with non-default value %d requires DNGBackwardVersion >= %d.%d.%d.%d, got %d.%d.%d.%d per DNG 1.7.1.0.',
                        $tag,
                        $entry->value,
                        $minVer[0],
                        $minVer[1],
                        $minVer[2],
                        $minVer[3],
                        $bwVer[0],
                        $bwVer[1],
                        $bwVer[2],
                        $bwVer[3],
                    ),
                    1478,
                );
            }
        }
    }

    /**
     * Rejects third-illuminant tags when DNGBackwardVersion < 1.6.0.0.
     *
     * DNG 1.7.1.0 Appendix A: third calibration set requires version >= 1.6.0.0.
     */
    public function validateDngThirdIlluminantVersionFloor(Ifd $ifd): void
    {
        $hasThird = array_any(self::DNG_THIRD_ILLUMINANT_TAGS, fn (int $tag): bool => $ifd->get($tag) instanceof IfdEntry);

        if (!$hasThird) {
            return;
        }

        $bwVer = $this->support->getEffectiveDngBackwardVersion($ifd);

        if ($bwVer === null) {
            return;
        }

        if ($this->support->dngVersionLessThan($bwVer, [1, 6, 0, 0])) {
            throw new ParseError(
                sprintf(
                    'Third-illuminant tags require DNGBackwardVersion >= 1.6.0.0, got %d.%d.%d.%d.',
                    $bwVer[0],
                    $bwVer[1],
                    $bwVer[2],
                    $bwVer[3],
                ),
                1500,
            );
        }
    }
}
