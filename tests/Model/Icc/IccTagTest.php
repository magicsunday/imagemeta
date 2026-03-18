<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Icc;

use MagicSunday\ImageMeta\Model\Icc\IccTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function sprintf;

/**
 * Verifies IccTag offset constants match ICC.1:2022 profile header layout.
 */
#[CoversClass(IccTag::class)]
final class IccTagTest extends TestCase
{
    /**
     * Header field offsets match ICC.1:2022 §7.2 layout.
     */
    #[Test]
    public function headerOffsetsMatchIccSpec(): void
    {
        $reflection = new ReflectionClass(IccTag::class);

        // ICC.1:2022 §7.2.2–§7.2.19
        $expected   = [
            'PROFILE_SIZE'                => 0x0000,
            'CMM_TYPE'                    => 0x0004,
            'PROFILE_VERSION'             => 0x0008,
            'PROFILE_CLASS'               => 0x000C,
            'COLOR_SPACE'                 => 0x0010,
            'PCS'                         => 0x0014,
            'PROFILE_DATE_TIME'           => 0x0018,
            'PROFILE_SIGNATURE'           => 0x0024,
            'PRIMARY_PLATFORM'            => 0x0028,
            'PROFILE_FLAGS'               => 0x002C,
            'DEVICE_MANUFACTURER'         => 0x0030,
            'DEVICE_MODEL'                => 0x0034,
            'DEVICE_ATTRIBUTES'           => 0x0038,
            'RENDERING_INTENT'            => 0x0040,
            'CONNECTION_SPACE_ILLUMINANT' => 0x0044,
            'PROFILE_CREATOR'             => 0x0050,
            'PROFILE_ID'                  => 0x0054,
            'RESERVED'                    => 0x0064,
        ];

        foreach ($expected as $name => $offset) {
            self::assertSame(
                $offset,
                $reflection->getConstant($name),
                sprintf('Offset mismatch for %s.', $name),
            );
        }
    }

    /**
     * IccTag cannot be instantiated (private constructor).
     * ICC.1:2022 — constants-only utility class.
     */
    #[Test]
    public function cannotBeInstantiated(): void
    {
        $reflection = new ReflectionClass(IccTag::class);

        self::assertFalse($reflection->isInstantiable());
    }
}
