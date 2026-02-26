<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jxl;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\PayloadGuard;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxDescriptor;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxNavigator;

use function strlen;
use function substr;

/**
 * Streaming JPEG XL container reader that extracts EXIF and XMP payloads.
 *
 * ISO/IEC 18181-2 defines the JXL file format as an ISO BMFF-compatible container
 * with a 12-byte JXL signature box followed by standard top-level boxes including
 * `Exif` for EXIF metadata and `xml ` for XMP.
 */
final readonly class JxlParser implements JxlParserInterface
{
    /**
     * Four-character code for the Exif box.
     */
    private const string BOX_EXIF = 'Exif';

    /**
     * Four-character code for the XMP box in JXL containers.
     *
     * ISO/IEC 18181-2 §A.3.3 uses lowercase `xml ` (with trailing space).
     */
    private const string BOX_XML = 'xml ';

    /**
     * Maximum allowed size for a single metadata payload in bytes (16 MiB).
     */
    private const int MAX_PAYLOAD_SIZE = 16 * 1024 * 1024;

    /**
     * Maximum combined size of all extracted metadata payloads in bytes (64 MiB).
     */
    private const int MAX_TOTAL_METADATA_BYTES = 64 * 1024 * 1024;

    /**
     * Maximum number of metadata boxes (`Exif` + `xml `) extracted from one JXL container.
     */
    private const int MAX_METADATA_BOX_COUNT = 64;

    private BoxNavigator $boxNavigator;

    /**
     * @param Stream $stream                Stream positioned at the beginning of the JXL container.
     * @param int    $maxPayloadSize        Maximum allowed size for a single metadata payload in bytes.
     * @param int    $maxTotalMetadataBytes Maximum combined size of all metadata payloads in bytes.
     * @param int    $maxMetadataBoxCount   Maximum number of metadata boxes (`Exif` + `xml `) to process.
     */
    public function __construct(
        private Stream $stream,
        private int $maxPayloadSize = self::MAX_PAYLOAD_SIZE,
        private int $maxTotalMetadataBytes = self::MAX_TOTAL_METADATA_BYTES,
        private int $maxMetadataBoxCount = self::MAX_METADATA_BOX_COUNT,
    ) {
        $this->boxNavigator = new BoxNavigator($stream);
    }

    /**
     * Extracts EXIF blobs and XMP packets from the JXL container.
     *
     * @return array{0: list<string>, 1: list<string>} Tuple of [EXIF blobs, XMP packets].
     */
    public function extract(): array
    {
        /** @var list<string> $exifBlobs */
        $exifBlobs = [];
        /** @var list<string> $xmpBlobs */
        $xmpBlobs = [];
        $totalMetadataBytes = 0;
        $metadataBoxCount   = 0;

        foreach ($this->walkTopLevelBoxes() as $box) {
            if ($box->type !== self::BOX_EXIF && $box->type !== self::BOX_XML) {
                continue;
            }

            if ($box->contentSize > $this->maxPayloadSize) {
                if ($box->type === self::BOX_EXIF) {
                    throw new ParseError('JXL Exif box payload exceeds maximum allowed size', 1560);
                }

                throw new ParseError('JXL xml box payload exceeds maximum allowed size', 1561);
            }

            // AGENTS.md §4 requires explicit limits for metadata boxes and packets.
            if ($metadataBoxCount >= $this->maxMetadataBoxCount) {
                throw new ParseError('JXL metadata box count exceeds maximum allowed value', 2084);
            }

            if ($box->contentSize > ($this->maxTotalMetadataBytes - $totalMetadataBytes)) {
                throw new ParseError('JXL combined metadata payload exceeds maximum allowed size', 2083);
            }

            ++$metadataBoxCount;
            $totalMetadataBytes += $box->contentSize;

            if ($box->type === self::BOX_EXIF) {
                $blob        = $this->boxNavigator->readAll($box->window);
                $exifBlobs[] = $this->normalizeExifBlob($blob);
            } else {
                $xmpBlobs[] = $this->boxNavigator->readAll($box->window);
            }
        }

        return [$exifBlobs, $xmpBlobs];
    }

    /**
     * Walks each top-level box in the container and yields a descriptor object.
     *
     * @return iterable<BoxDescriptor>
     */
    private function walkTopLevelBoxes(): iterable
    {
        $fileSize = $this->stream->size();
        $offset   = 0;

        while ($offset + 8 <= $fileSize) {
            $box = $this->boxNavigator->readBoxAt($offset, $fileSize, allowImplicitSize: true);
            yield $box;
            $offset += $box->size;
        }
    }

    /**
     * Strips the EXIF offset prefix so downstream parsers receive a clean TIFF header.
     *
     * ISO/IEC 18181-2 §A.3.2: the Exif box payload starts with a 4-byte big-endian
     * unsigned integer indicating the offset from the end of this field to the TIFF
     * header. This is the same encoding used in ISO 14496-12 (HEIF) Exif items.
     *
     * @param string $blob Raw EXIF payload from the JXL Exif box.
     *
     * @return string EXIF payload trimmed to the TIFF header.
     */
    private function normalizeExifBlob(string $blob): string
    {
        PayloadGuard::ensureMinimumLength($blob, 4, 'JXL Exif box payload', 1562);

        $offset = Unpack::int('N', substr($blob, 0, 4), 'JXL Exif TIFF-header offset');

        if ($offset < 0 || (4 + $offset + 2) > strlen($blob)) {
            throw new ParseError('JXL Exif TIFF-header offset out of range', 1563);
        }

        $tiffSig = substr($blob, 4 + $offset, 2);
        if ($tiffSig !== 'II' && $tiffSig !== 'MM') {
            throw new ParseError('JXL Exif TIFF-header offset does not point to valid TIFF signature', 1564);
        }

        return substr($blob, 4 + $offset);
    }
}
