<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\QuickTime\Tests;

use MagicSunday\ImageMeta\Extensions;
use MagicSunday\ImageMeta\QuickTime\QuickTimeExtension;
use MagicSunday\ImageMeta\Registry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    #[Test]
    public function registersExtensionWithoutErrors(): void
    {
        $registry = Extensions::boot();

        Extensions::register(new QuickTimeExtension());

        self::assertInstanceOf(Registry::class, $registry);
    }
}
