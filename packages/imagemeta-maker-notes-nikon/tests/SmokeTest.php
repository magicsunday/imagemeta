<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Nikon\Tests;

use MagicSunday\ImageMeta\MakerNotes\MakerNotesDecoderInterface;
use MagicSunday\ImageMeta\MakerNotes\Nikon\NikonMakerNotesDecoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    #[Test]
    public function createsDecoder(): void
    {
        $decoder = new NikonMakerNotesDecoder();

        self::assertInstanceOf(MakerNotesDecoderInterface::class, $decoder);
    }
}
