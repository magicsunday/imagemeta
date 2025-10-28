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
use MagicSunday\ImageMeta\Model\Exif\ExifDocument as ModelExifDocument;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExifDocumentFallbackTest extends TestCase
{
    #[Test]
    public function exposesBestEffortIsoAndUserCommentEncoding(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::SENSITIVITY_TYPE => new IfdEntry(ExifTag::SENSITIVITY_TYPE, 3, 1, 2),
            ExifTag::RECOMMENDED_EXPOSURE_INDEX => new IfdEntry(
                ExifTag::RECOMMENDED_EXPOSURE_INDEX,
                5,
                1,
                new ExifRational(400, 1),
            ),
            ExifTag::USER_COMMENT => new IfdEntry(ExifTag::USER_COMMENT, 7, 1, 'Shot with ND filter'),
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, 'invalid'),
            ExifTag::DATETIME_DIGITIZED => new IfdEntry(
                ExifTag::DATETIME_DIGITIZED,
                2,
                1,
                '2024:05:06 07:08:09',
            ),
            ExifTag::OFFSET_TIME_DIGITIZED => new IfdEntry(
                ExifTag::OFFSET_TIME_DIGITIZED,
                2,
                1,
                '+02:00',
            ),
        ]);

        $modelDocument = new ModelExifDocument($ifd0, $exifIfd, null, null, null);
        $apiDocument   = new ApiExifDocument($modelDocument);

        $bestEffortOriginal = $modelDocument->dateTimeOriginalBestEffort();
        self::assertNotNull($bestEffortOriginal);
        self::assertSame('2024-05-06T07:08:09+02:00', $bestEffortOriginal->format(DATE_ATOM));

        $exposure = $apiDocument->exposure();
        self::assertSame(400, $exposure->iso);

        $image = $apiDocument->image();
        self::assertSame('Shot with ND filter', $image->userComment);
        self::assertSame('ASCII', $image->userCommentEncoding);
    }
}
