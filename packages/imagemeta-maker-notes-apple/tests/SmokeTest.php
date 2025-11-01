<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple\Tests;

use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotesDecoder;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesDecoderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    #[Test]
    public function createsDecoder(): void
    {
        $decoder = new AppleMakerNotesDecoder();

        self::assertInstanceOf(MakerNotesDecoderInterface::class, $decoder);
    }
}
