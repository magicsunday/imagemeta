<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Camera;
use MagicSunday\ImageMeta\Value\Enum\FileSource;
use MagicSunday\ImageMeta\Value\Enum\SensingMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Camera value object with make/model and capture-source metadata.
 * It verifies owner name and firmware fields are stored and exposed correctly.
 * The suite checks enum-backed fields like FileSource and SensingMethod.
 * This ensures camera identity and source data remain consistent for consumers.
 *
 * @internal
 */
#[CoversClass(Camera::class)]
final class CameraTest extends TestCase
{
    /**
     * Exposes camera metadata provided via the constructor.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function exposesConstructorState(): void
    {
        $camera = new Camera(
            make: 'Canon',
            model: 'EOS R6',
            ownerName: 'Jane Doe',
            firmware: '1.2.3',
            fileSource: FileSource::DigitalCamera,
            sensingMethod: SensingMethod::OneChipColorArea,
        );

        self::assertSame('Canon', $camera->make);
        self::assertSame('EOS R6', $camera->model);
        self::assertSame('Jane Doe', $camera->ownerName);
        self::assertSame('1.2.3', $camera->firmware);
        self::assertSame(FileSource::DigitalCamera, $camera->fileSource);
        self::assertSame(SensingMethod::OneChipColorArea, $camera->sensingMethod);
    }
}
