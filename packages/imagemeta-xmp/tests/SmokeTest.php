<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Xmp\Tests;

use MagicSunday\ImageMeta\Extensions;
use MagicSunday\ImageMeta\Registry;
use MagicSunday\ImageMeta\Xmp\XmpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    #[Test]
    public function registersExtensionWithoutErrors(): void
    {
        $registry = Extensions::boot();

        Extensions::register(new XmpExtension());

        self::assertInstanceOf(Registry::class, $registry);
    }
}
