<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Canon\Tests;

use MagicSunday\ImageMeta\MakerNotes\Canon\CanonMakerNotesDecoder;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesDecoderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    #[Test]
    public function createsDecoder(): void
    {
        $decoder = new CanonMakerNotesDecoder();

        self::assertInstanceOf(MakerNotesDecoderInterface::class, $decoder);
    }
}
