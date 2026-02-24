<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Lens;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Lens value object for make/model, serial, and focal length data.
 * It verifies lens specification arrays and numeric fields are preserved.
 * The suite checks 35mm equivalent values and maximum aperture storage.
 * This ensures lens metadata remains consistent for display and calculations.
 *
 * @internal
 */
#[CoversClass(Lens::class)]
final class LensTest extends TestCase
{
    /**
     * Exposes lens metadata and specification values.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function exposesSpecificationAndHelpers(): void
    {
        $specification = [35.0, 2.8, 105.0, 4.0];

        $lens = new Lens(
            lensMake: 'Canon',
            lensModel: 'EF 35-105mm',
            lensSerialNumber: 'LN123',
            focalLengthMm: 85.0,
            focalLengthIn35mm: 80,
            maxApertureFNumber: 2.0,
            lensSpecification: $specification,
        );

        self::assertSame('Canon', $lens->lensMake);
        self::assertSame('EF 35-105mm', $lens->lensModel);
        self::assertSame('LN123', $lens->lensSerialNumber);
        self::assertSame(85.0, $lens->focalLengthMm);
        self::assertSame(80, $lens->focalLengthIn35mm);
        self::assertSame(2.0, $lens->maxApertureFNumber);
        self::assertSame($specification, $lens->lensSpecification);
    }
}
