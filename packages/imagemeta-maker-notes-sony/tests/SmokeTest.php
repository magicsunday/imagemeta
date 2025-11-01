<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Sony\Tests;

use MagicSunday\ImageMeta\MakerNotes\MakerNotesDecoderInterface;
use MagicSunday\ImageMeta\MakerNotes\Sony\SonyMakerNotesDecoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    #[Test]
    public function createsDecoder(): void
    {
        $decoder = new SonyMakerNotesDecoder();

        self::assertInstanceOf(MakerNotesDecoderInterface::class, $decoder);
    }
}
