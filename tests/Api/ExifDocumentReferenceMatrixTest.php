<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Api;

use MagicSunday\ImageMeta\Api\ExifDocument as ApiExifDocument;
use MagicSunday\ImageMeta\Curate\Exif\Structured\Preview as StructuredPreview;
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExifDocumentReferenceMatrixTest extends TestCase
{
    private const string IMAGE_DIR = __DIR__ . '/../Fixtures/Images/ExifVersions';

    #[Test]
    #[DataProviderExternal(\MagicSunday\ImageMeta\Tests\Acceptance\ExifBackfillMatrixTest::class, 'provideReferenceImages')]
    public function exposesFallbackMetadataFromReferenceImages(
        string $file,
        ?int $expectedIso,
        ?string $expectedDateTimeOriginal,
        ?string $expectedUserComment,
        ?string $expectedUserCommentEncoding,
        array $expectedStandards,
        array $expectedInterop,
        array $expectedPreview,
    ): void {
        $metadata = (new MetadataReader())
            ->read(self::IMAGE_DIR . '/' . $file);

        $modelDocument = $metadata->exifDoc;
        self::assertNotNull($modelDocument, sprintf('Reference EXIF document missing for %s', $file));

        $document = new ApiExifDocument($modelDocument);

        self::assertSame($modelDocument, $document->raw(), sprintf('%s: Raw document reference', $file));
        self::assertSame($expectedStandards['flashpixVersion'], $modelDocument->flashpixVersion(), sprintf('%s: FlashPix version', $file));
        self::assertSame($expectedStandards['tiffEpStandardId'], $modelDocument->tiffEpStandardId(), sprintf('%s: TIFF/EP standard id', $file));
        self::assertSame($expectedStandards['tiffEpStandardString'], $modelDocument->tiffEpStandardIdString(), sprintf('%s: TIFF/EP standard string', $file));

        self::assertSame($expectedIso, $document->iso(), sprintf('%s: ISO fallback', $file));

        $original = $document->dateTimeOriginal();
        if ($expectedDateTimeOriginal === null) {
            self::assertNull($original, sprintf('%s: DateTimeOriginal fallback', $file));
        } else {
            self::assertNotNull($original, sprintf('%s: DateTimeOriginal fallback', $file));
            self::assertSame(
                $expectedDateTimeOriginal,
                $original->format(DATE_ATOM),
                sprintf('%s: DateTimeOriginal fallback', $file),
            );
        }

        self::assertSame(
            $expectedUserComment,
            $document->userComment(),
            sprintf('%s: UserComment fallback', $file),
        );
        self::assertSame(
            $expectedUserCommentEncoding,
            $document->userCommentEncoding(),
            sprintf('%s: UserComment encoding fallback', $file),
        );

        $interop = $document->interop();
        self::assertSame($expectedInterop['index'], $interop->index, sprintf('%s: Interop index', $file));
        self::assertSame($expectedInterop['version'], $interop->version, sprintf('%s: Interop version', $file));
        self::assertSame(
            $expectedInterop['fileFormat'],
            $interop->relatedImageFileFormat,
            sprintf('%s: Interop file format', $file),
        );
        self::assertSame(
            $expectedInterop['width'],
            $interop->relatedImageWidth,
            sprintf('%s: Interop width', $file),
        );
        self::assertSame(
            $expectedInterop['length'],
            $interop->relatedImageLength,
            sprintf('%s: Interop length', $file),
        );

        $preview = $document->preview();
        $this->assertPreviewDescriptor($file, $expectedPreview, $preview);
    }

    /**
     * @param array{
     *     hasPreview:?bool,
     *     offset:?int,
     *     length:?int,
     *     width:?int,
     *     height:?int,
     *     bitDepth:?int,
     *     compression:?int,
     *     scale:?float|null,
     *     encoding:?string,
     *     mimeType:?string,
     * } $expected
     */
    private function assertPreviewDescriptor(string $file, array $expected, StructuredPreview $preview): void
    {
        self::assertSame($expected['hasPreview'], $preview->hasPreview, sprintf('%s: Preview availability', $file));
        self::assertSame($expected['offset'], $preview->previewOffset, sprintf('%s: Preview offset', $file));
        self::assertSame($expected['length'], $preview->previewLength, sprintf('%s: Preview length', $file));
        self::assertSame($expected['width'], $preview->previewWidth, sprintf('%s: Preview width', $file));
        self::assertSame($expected['height'], $preview->previewHeight, sprintf('%s: Preview height', $file));
        self::assertSame($expected['bitDepth'], $preview->previewBitDepth, sprintf('%s: Preview bit depth', $file));
        self::assertSame($expected['encoding'], $preview->previewEncoding, sprintf('%s: Preview encoding', $file));
        self::assertSame($expected['mimeType'], $preview->previewMimeType, sprintf('%s: Preview mime type', $file));

        if ($expected['compression'] === null) {
            self::assertNull($preview->previewCompression, sprintf('%s: Preview compression', $file));
        } else {
            self::assertInstanceOf(Compression::class, $preview->previewCompression, sprintf('%s: Preview compression type', $file));
            self::assertSame(
                $expected['compression'],
                $preview->previewCompression->value,
                sprintf('%s: Preview compression value', $file),
            );
        }

        if ($expected['scale'] === null) {
            self::assertNull($preview->previewScale, sprintf('%s: Preview scale', $file));
        } else {
            self::assertNotNull($preview->previewScale, sprintf('%s: Preview scale', $file));
            self::assertEqualsWithDelta(
                $expected['scale'],
                $preview->previewScale,
                1e-6,
                sprintf('%s: Preview scale value', $file),
            );
        }
    }
}
