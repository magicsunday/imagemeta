<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Reader;

use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Value\Enum\CompositeImage;
use MagicSunday\ImageMeta\Value\Oecf;
use MagicSunday\ImageMeta\Value\SourceExposureTimes;
use MagicSunday\ImageMeta\Value\SpatialFrequencyResponse;

use function count;
use function strlen;
use function substr;

/**
 * Reads composite image, OECF and spatial frequency response metadata
 * from EXIF IFDs.
 *
 * EXIF 3.0 §4.6.6.7 defines the picture-taking condition tags decoded by this reader.
 */
final readonly class SensorDataReader
{
    /**
     * @param IfdValueReader  $reader     Value reader for IFD tag extraction.
     * @param ValueConverters $converters Value converter facade for EXIF type normalization.
     * @param Ifd|null        $exifIfd    Sub IFD containing EXIF-specific tags.
     * @param Endian          $byteOrder  TIFF byte order.
     */
    public function __construct(
        private IfdValueReader $reader,
        private ValueConverters $converters,
        private ?Ifd $exifIfd,
        private Endian $byteOrder,
    ) {
    }

    // ========================================================================
    // Composite image
    // ========================================================================

    /**
     * Returns the composite image classification when available.
     *
     * EXIF 3.0 §4.6.6.7.47 defines the CompositeImage tag with four enumerated
     * states, reserving all others.
     */
    public function compositeImage(): ?CompositeImage
    {
        $value = $this->reader->int($this->exifIfd, ExifTag::COMPOSITE_IMAGE);

        return $value !== null ? CompositeImage::fromExifValue($value) : null;
    }

    /**
     * Returns the number of source images contributing to the composite result.
     *
     * EXIF 3.0 §4.6.6.7.48 records both the total number of captured source images
     * and how many were actually used to assemble the
     * composite. Figure 24 requires two SHORT values where both counters are at
     * least two and the used count cannot exceed the captured total.
     *
     * @return array{0:int,1:int}|null
     */
    public function sourceImageNumberOfCompositeImage(): ?array
    {
        $values = $this->reader->numericList($this->exifIfd, ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE);

        if (($values === null) || (count($values) !== 2)) {
            return null;
        }

        [$capturedCount, $usedCount] = $values;

        if (($capturedCount < 2) || ($usedCount < 2)) {
            return null;
        }

        if ($usedCount > $capturedCount) {
            return null;
        }

        return [$capturedCount, $usedCount];
    }

    /**
     * Decodes the SourceExposureTimesOfCompositeImage payload.
     *
     * EXIF 3.0 §4.6.6.7.49 Figure 25 stores eight summary RATIONAL values
     * followed by one or more sequences of SHORT counts and RATIONAL exposure
     * times representing the contributing source images.
     */
    public function sourceExposureTimesOfCompositeImage(): ?SourceExposureTimes
    {
        $payload = $this->reader->rawString($this->exifIfd, ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE);

        if ($payload === null || $payload === '') {
            return null;
        }

        return $this->decodeSourceExposureTimes($payload);
    }

    // ========================================================================
    // OECF / spatial frequency response
    // ========================================================================

    /**
     * Returns the opto-electronic conversion function data.
     *
     * EXIF 3.0 §4.6.6.7.6 (Figure 16, Table 11) describes the relationship between
     * the camera's optical input and the image file values.
     */
    public function oecf(): ?Oecf
    {
        $payload = $this->oecfPayload();

        if ($payload === null) {
            return null;
        }

        $matrix = $this->converters->decodeOecf($payload, $this->byteOrder);

        return Oecf::fromMatrix($matrix);
    }

    /**
     * Returns the raw opto-electronic conversion function payload.
     */
    public function oecfPayload(): ?string
    {
        return $this->reader->rawString($this->exifIfd, ExifTag::OECF);
    }

    /**
     * Returns the decoded spatial frequency response table.
     *
     * EXIF 3.0 §4.6.3 Table 16: SFR records camera and optical system's spatial frequency
     * response characteristics.
     */
    public function spatialFrequencyResponse(): ?SpatialFrequencyResponse
    {
        $payload = $this->reader->rawString($this->exifIfd, ExifTag::SPATIAL_FREQUENCY_RESPONSE);
        $matrix  = $this->converters->decodeSpatialFrequencyResponse($payload, $this->byteOrder);

        return SpatialFrequencyResponse::fromMatrix($matrix);
    }

    // ========================================================================
    // Private helpers
    // ========================================================================

    /**
     * Parses the binary layout defined for SourceExposureTimesOfCompositeImage.
     *
     * @param string $payload Raw tag payload stored as an UNDEFINED value.
     */
    private function decodeSourceExposureTimes(string $payload): ?SourceExposureTimes
    {
        $payloadLength = strlen($payload);
        $offset        = 0;

        $summary = [];

        for ($i = 0; $i < 8; ++$i) {
            if (($offset + IfdValueReader::RATIONAL_BYTE_LENGTH) > $payloadLength) {
                return null;
            }

            $summaryValue = $this->decodeRationalFromBytes(substr($payload, $offset, IfdValueReader::RATIONAL_BYTE_LENGTH));

            if ($summaryValue === null) {
                return null;
            }

            $summary[] = $summaryValue;
            $offset += IfdValueReader::RATIONAL_BYTE_LENGTH;
        }

        $sequenceCount = $this->decodeShort($payload, $offset);

        if ($sequenceCount === null) {
            return null;
        }

        $offset += IfdValueReader::SHORT_BYTE_LENGTH;

        $sequences = [];

        for ($i = 0; $i < $sequenceCount; ++$i) {
            $imageCount = $this->decodeShort($payload, $offset);

            if ($imageCount === null) {
                return null;
            }

            $offset += IfdValueReader::SHORT_BYTE_LENGTH;

            $sequence = [];

            for ($image = 0; $image < $imageCount; ++$image) {
                if (($offset + IfdValueReader::RATIONAL_BYTE_LENGTH) > $payloadLength) {
                    return null;
                }

                $value = $this->decodeRationalFromBytes(substr($payload, $offset, IfdValueReader::RATIONAL_BYTE_LENGTH));

                if ($value === null) {
                    return null;
                }

                $offset += IfdValueReader::RATIONAL_BYTE_LENGTH;
                $sequence[] = $value;
            }

            $sequences[] = $sequence;
        }

        if ($offset !== $payloadLength) {
            return null;
        }

        return new SourceExposureTimes(
            totalExposurePeriod: $summary[0],
            usedExposureTimeSum: $summary[1],
            allExposureTimeSum: $summary[2],
            sourceImageCount: $summary[3],
            maxUsedExposureTime: $summary[4],
            minUsedExposureTime: $summary[5],
            longestSourceExposureTime: $summary[6],
            shortestSourceExposureTime: $summary[7],
            sequences: $sequences,
        );
    }

    /**
     * Reads a SHORT value from a composite exposure payload.
     *
     * @param string $payload Raw payload bytes.
     * @param int    $offset  Offset within the payload.
     *
     * @return int|null Decoded value or null when out of range.
     */
    private function decodeShort(string $payload, int $offset): ?int
    {
        if (($offset + IfdValueReader::SHORT_BYTE_LENGTH) > strlen($payload)) {
            return null;
        }

        $format = $this->byteOrder === Endian::Little ? 'v' : 'n';

        return Unpack::int($format, substr($payload, $offset, IfdValueReader::SHORT_BYTE_LENGTH), 'EXIF composite exposure short');
    }

    /**
     * Decodes a RATIONAL value from an 8-byte payload.
     *
     * @param string $bytes Raw 8-byte rational value.
     *
     * @return float|null Decoded float value or null when invalid.
     */
    private function decodeRationalFromBytes(string $bytes): ?float
    {
        if (strlen($bytes) !== IfdValueReader::RATIONAL_BYTE_LENGTH) {
            return null;
        }

        // RATIONAL values are stored as numerator/denominator pairs.
        $format    = $this->byteOrder === Endian::Little ? 'V' : 'N';
        $numerator = Unpack::int($format, substr($bytes, 0, 4), 'EXIF composite exposure numerator');
        $denom     = Unpack::int($format, substr($bytes, 4, 4), 'EXIF composite exposure denominator');

        if ($denom === 0) {
            return null;
        }

        return $numerator / $denom;
    }
}
