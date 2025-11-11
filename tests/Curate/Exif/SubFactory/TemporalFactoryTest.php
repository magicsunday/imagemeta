<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Curate\Exif\SubFactory;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\TemporalFactory;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Temporal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TemporalFactory::class)]
final class TemporalFactoryTest extends TestCase
{
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $dateTime = new DateTimeImmutable('2023-06-15 14:30:00');

        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('dateTimeDigitized')->willReturn($dateTime);
        $exifDoc->method('dateTime')->willReturn($dateTime);
        $exifDoc->method('dateTimeOriginalBestEffort')->willReturn($dateTime);
        $exifDoc->method('offsetTime')->willReturn('+02:00');
        $exifDoc->method('offsetTimeOriginal')->willReturn('+02:00');
        $exifDoc->method('offsetTimeDigitized')->willReturn('+02:00');
        $exifDoc->method('subSecTime')->willReturn('500');
        $exifDoc->method('subSecTimeDigitized')->willReturn('500');
        $exifDoc->method('subSecTimeOriginal')->willReturn('500');
        $exifDoc->method('dateTimeOriginalRaw')->willReturn('2023:06:15 14:30:00');
        $exifDoc->method('dateTimeDigitizedRaw')->willReturn('2023:06:15 14:30:00');

        $metadata          = new Metadata();
        $metadata->exifDoc = $exifDoc;

        $factory  = new TemporalFactory();
        $temporal = $factory->create($metadata);

        self::assertInstanceOf(Temporal::class, $temporal);
        self::assertInstanceOf(DateTimeImmutable::class, $temporal->create);
        self::assertInstanceOf(DateTimeImmutable::class, $temporal->modify);
        self::assertInstanceOf(DateTimeImmutable::class, $temporal->original);
        self::assertInstanceOf(DateTimeZone::class, $temporal->tz);
        self::assertSame('500', $temporal->subSecTime);
        self::assertSame('500', $temporal->subSecTimeOriginal);
        self::assertSame('500', $temporal->subSecTimeDigitized);
    }

    #[Test]
    public function fallsBackToXmpTimestamps(): void
    {
        $xmpDoc = $this->createMock(XmpDocument::class);
        $xmpDoc->method('string')->willReturnCallback(
            static fn (string $ns, string $name): ?string => match ($name) {
                'CreateDate' => '2023-06-15T14:30:00+02:00',
                'ModifyDate' => '2023-06-16T10:00:00+02:00',
                default      => null,
            },
        );

        $metadata         = new Metadata();
        $metadata->xmpDoc = $xmpDoc;

        $factory  = new TemporalFactory();
        $temporal = $factory->create($metadata);

        self::assertInstanceOf(Temporal::class, $temporal);
        self::assertInstanceOf(DateTimeImmutable::class, $temporal->create);
        self::assertInstanceOf(DateTimeImmutable::class, $temporal->modify);
    }

    #[Test]
    public function fallsBackToQuickTimeTimestamps(): void
    {
        $quickTime           = new QuickTimeMeta();
        $quickTime->metadata = [
            'CreationDate' => '2023-06-15T14:30:00+02:00',
            'ModifyDate'   => '2023-06-16T10:00:00+02:00',
        ];

        $metadata            = new Metadata();
        $metadata->quickTime = $quickTime;

        $factory  = new TemporalFactory();
        $temporal = $factory->create($metadata);

        self::assertInstanceOf(Temporal::class, $temporal);
        self::assertInstanceOf(DateTimeImmutable::class, $temporal->create);
        self::assertInstanceOf(DateTimeImmutable::class, $temporal->modify);
    }

    #[Test]
    public function sanitizesSubSeconds(): void
    {
        $dateTime = new DateTimeImmutable('2023-06-15 14:30:00');

        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('dateTimeDigitized')->willReturn($dateTime);
        $exifDoc->method('dateTime')->willReturn($dateTime);
        $exifDoc->method('dateTimeOriginalBestEffort')->willReturn($dateTime);
        $exifDoc->method('offsetTime')->willReturn(null);
        $exifDoc->method('offsetTimeOriginal')->willReturn(null);
        $exifDoc->method('offsetTimeDigitized')->willReturn(null);
        $exifDoc->method('subSecTime')->willReturn('5');
        $exifDoc->method('subSecTimeDigitized')->willReturn('50');
        $exifDoc->method('subSecTimeOriginal')->willReturn('500');
        $exifDoc->method('dateTimeOriginalRaw')->willReturn('2023:06:15 14:30:00');
        $exifDoc->method('dateTimeDigitizedRaw')->willReturn('2023:06:15 14:30:00');

        $metadata          = new Metadata();
        $metadata->exifDoc = $exifDoc;

        $factory  = new TemporalFactory();
        $temporal = $factory->create($metadata);

        self::assertInstanceOf(Temporal::class, $temporal);
        self::assertSame('500', $temporal->subSecTime);
        self::assertSame('500', $temporal->subSecTimeOriginal);
        self::assertSame('050', $temporal->subSecTimeDigitized);
    }

    #[Test]
    public function createsWithNullMetadata(): void
    {
        $metadata = new Metadata();

        $factory  = new TemporalFactory();
        $temporal = $factory->create($metadata);

        self::assertInstanceOf(Temporal::class, $temporal);
        self::assertNull($temporal->create);
        self::assertNull($temporal->modify);
        self::assertNull($temporal->original);
        self::assertNull($temporal->tz);
    }
}
