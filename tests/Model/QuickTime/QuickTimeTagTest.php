<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\QuickTime;

use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function sprintf;
use function strlen;

/**
 * Verifies QuickTimeTag constant catalog completeness and four-character code invariants.
 * QuickTime File Format 2012 requires all atom type codes to be exactly four bytes.
 */
#[CoversClass(QuickTimeTag::class)]
final class QuickTimeTagTest extends TestCase
{
    /**
     * Core container and metadata atom codes match the QuickTime File Format 2012 spec.
     */
    #[Test]
    public function coreAtomCodesMatchSpec(): void
    {
        $reflection = new ReflectionClass(QuickTimeTag::class);

        $expected   = [
            'ATOM_FTYP' => 'ftyp',
            'ATOM_MOOV' => 'moov',
            'ATOM_TRAK' => 'trak',
            'ATOM_UDTA' => 'udta',
            'ATOM_META' => 'meta',
            'ATOM_KEYS' => 'keys',
            'ATOM_ILST' => 'ilst',
            'ATOM_DATA' => 'data',
        ];

        foreach ($expected as $name => $value) {
            self::assertSame(
                $value,
                $reflection->getConstant($name),
                sprintf('Atom code mismatch for %s.', $name),
            );
        }
    }

    /**
     * All atom code constants are exactly four characters as required by the spec.
     * QuickTime File Format 2012 — all atom type codes are four-character codes.
     */
    #[Test]
    public function allAtomCodesAreExactlyFourCharacters(): void
    {
        $reflection = new ReflectionClass(QuickTimeTag::class);

        foreach ($reflection->getReflectionConstants() as $constant) {
            if (!$constant->isPublic()) {
                continue;
            }

            $value = $constant->getValue();
            self::assertIsString($value);

            self::assertSame(
                4,
                strlen($value),
                sprintf('Constant %s has value "%s" which is not 4 characters.', $constant->getName(), $value),
            );
        }
    }
}
