<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Acceptance;

use MagicSunday\ImageMeta\MetadataReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExifFallbacksTest extends TestCase
{
    private const string SAMPLE = __DIR__ . '/../../test-images/Images/gps_exif_example.jpg';

    #[Test]
    public function readsBestEffortExposureAndTemporalData(): void
    {
        $structured = (new MetadataReader())
            ->read(self::SAMPLE)
            ->structured();

        self::assertSame(80, $structured->exposure()->iso);
        self::assertSame(
            '2011-12-06T11:08:37+00:00',
            $structured->temporal()->original?->format(DATE_ATOM),
        );
        self::assertSame(
            '400 N Michigan Ave, Chicago, IL 60611, USA',
            $structured->image()->userComment,
        );
        self::assertSame('ASCII', $structured->image()->userCommentEncoding);

        $interop = $structured->interop();
        self::assertSame('R98', $interop->index);
        self::assertSame('0100', $interop->version);
        self::assertSame(4000, $interop->relatedImageWidth);
        self::assertSame(3000, $interop->relatedImageLength);
    }
}
