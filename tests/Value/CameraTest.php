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

#[CoversClass(Camera::class)]
final class CameraTest extends TestCase
{
    #[Test]
    public function exposesConstructorStateThroughAccessors(): void
    {
        $camera = new Camera(
            make: 'Canon',
            model: 'EOS R6',
            ownerName: 'Jane Doe',
            serialNumber: '12345',
            firmware: '1.2.3',
            fileSource: FileSource::DIGITAL_CAMERA,
            sensingMethod: SensingMethod::ONE_CHIP_COLOR_AREA,
        );

        self::assertSame('Canon', $camera->make);
        self::assertSame('Canon', $camera->make());
        self::assertSame('EOS R6', $camera->model);
        self::assertSame('EOS R6', $camera->model());
        self::assertSame('Jane Doe', $camera->ownerName);
        self::assertSame('Jane Doe', $camera->ownerName());
        self::assertSame('12345', $camera->serialNumber);
        self::assertSame('12345', $camera->serialNumber());
        self::assertSame('1.2.3', $camera->firmware);
        self::assertSame('1.2.3', $camera->firmware());
        self::assertSame(FileSource::DIGITAL_CAMERA, $camera->fileSource);
        self::assertSame(FileSource::DIGITAL_CAMERA, $camera->fileSource());
        self::assertSame(SensingMethod::ONE_CHIP_COLOR_AREA, $camera->sensingMethod);
        self::assertSame(SensingMethod::ONE_CHIP_COLOR_AREA, $camera->sensingMethod());
    }
}
