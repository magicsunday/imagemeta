<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Curate\Exif\SubFactory;

use MagicSunday\ImageMeta\Curate\Exif\SubFactory\MultiPictureFactory;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;
use MagicSunday\ImageMeta\Value\MultiPicture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MultiPictureFactory::class)]
final class MultiPictureFactoryTest extends TestCase
{
    #[Test]
    public function createsFromMpfDocument(): void
    {
        $mpfDocument             = $this->createMock(MpfDocument::class);
        $mpfDocument->version    = '0100';
        $mpfDocument->imageCount = 2;
        $mpfDocument->entries    = [];
        $mpfDocument->attributes = null;

        $metadata              = new Metadata();
        $metadata->mpfDocument = $mpfDocument;

        $factory      = new MultiPictureFactory();
        $multiPicture = $factory->create($metadata);

        self::assertInstanceOf(MultiPicture::class, $multiPicture);
        self::assertSame('0100', $multiPicture->version);
        self::assertSame(2, $multiPicture->imageCount);
        self::assertSame([], $multiPicture->entries);
    }

    #[Test]
    public function createsWithNullMpfDocument(): void
    {
        $metadata = new Metadata();

        $factory      = new MultiPictureFactory();
        $multiPicture = $factory->create($metadata);

        self::assertInstanceOf(MultiPicture::class, $multiPicture);
        self::assertNull($multiPicture->version);
        self::assertSame(0, $multiPicture->imageCount);
        self::assertSame([], $multiPicture->entries);
    }
}
