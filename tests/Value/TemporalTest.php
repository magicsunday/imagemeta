<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\ImageMeta\Value\Temporal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Temporal value object for create/modify/original timestamps.
 * It verifies timezone, offset time, and sub-second fields are preserved.
 * The suite covers null handling for optional temporal metadata.
 * This keeps date/time metadata consistent across structured output.
 */
#[CoversClass(Temporal::class)]
final class TemporalTest extends TestCase
{
    /**
     * Stores the original capture timestamp.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function constructsWithOriginalDateTime(): void
    {
        $dateTime = new DateTimeImmutable('2024-01-15 10:30:00');

        $temporal = new Temporal(
            create: null,
            modify: null,
            original: $dateTime,
            tz: null,
            tzSource: null,
            offsetTime: null,
            offsetTimeOriginal: null,
            offsetTimeDigitized: null,
            subSecTime: null,
            subSecTimeOriginal: null,
            subSecTimeDigitized: null,
        );

        self::assertSame($dateTime, $temporal->original);
    }

    /**
     * Stores all temporal metadata fields and offsets.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function constructsWithAllDateTimeFields(): void
    {
        $create   = new DateTimeImmutable('2024-01-15 10:29:00');
        $original = new DateTimeImmutable('2024-01-15 10:30:00');
        $modify   = new DateTimeImmutable('2024-01-15 11:00:00');
        $timeZone = new DateTimeZone('Europe/Berlin');

        $temporal = new Temporal(
            create: $create,
            modify: $modify,
            original: $original,
            tz: $timeZone,
            tzSource: 'EXIF',
            offsetTime: '+01:00',
            offsetTimeOriginal: '+01:00',
            offsetTimeDigitized: '+01:00',
            subSecTime: '500',
            subSecTimeOriginal: '250',
            subSecTimeDigitized: '750',
        );

        self::assertSame($create, $temporal->create);
        self::assertSame($original, $temporal->original);
        self::assertSame($modify, $temporal->modify);
        self::assertSame($timeZone, $temporal->tz);
        self::assertSame('EXIF', $temporal->tzSource);
        self::assertSame('+01:00', $temporal->offsetTimeOriginal);
        self::assertSame('500', $temporal->subSecTime);
    }

    /**
     * Accepts null temporal metadata values.
     * It ensures missing or invalid inputs yield no value.
     */
    #[Test]
    public function allowsNullValues(): void
    {
        $temporal = new Temporal(
            create: null,
            modify: null,
            original: null,
            tz: null,
            tzSource: null,
            offsetTime: null,
            offsetTimeOriginal: null,
            offsetTimeDigitized: null,
            subSecTime: null,
            subSecTimeOriginal: null,
            subSecTimeDigitized: null,
        );

        self::assertNull($temporal->create);
        self::assertNull($temporal->original);
        self::assertNull($temporal->modify);
        self::assertNull($temporal->tz);
        self::assertNull($temporal->tzSource);
        self::assertNull($temporal->offsetTimeOriginal);
        self::assertNull($temporal->subSecTime);
    }
}
