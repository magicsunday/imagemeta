<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes;

use InvalidArgumentException;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Validates the maker notes record value object.
 *
 * @covers \MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord
 */
final class MakerNotesRecordTest extends TestCase
{
    /**
     * Ensures the metadata value object accepts valid arguments and exposes the expected values.
     */
    #[Test]
    public function constructorAcceptsValidArguments(): void
    {
        $metadata = new MakerNotesRecord('Contoso', 123, '0123456789abcdef0123456789abcdef01234567');

        self::assertSame('Contoso', $metadata->vendor);
        self::assertSame(123, $metadata->length);
        self::assertSame('0123456789abcdef0123456789abcdef01234567', $metadata->sha1);
        self::assertNull($metadata->apple);
        self::assertNull($metadata->isSafe);

        $safeMetadata = new MakerNotesRecord('Contoso', 123, '0123456789abcdef0123456789abcdef01234567', null, true);

        self::assertTrue($safeMetadata->isSafe);
    }

    /**
     * Provides invalid constructor argument combinations.
     *
     * @return array<string, array{0:string,1:int,2:string}>
     */
    public static function invalidConstructorArguments(): array
    {
        return [
            'empty vendor'    => ['', 10, '0123456789abcdef0123456789abcdef01234567'],
            'negative length' => ['Contoso', -1, '0123456789abcdef0123456789abcdef01234567'],
            'invalid sha1'    => ['Contoso', 10, 'invalid-digest'],
        ];
    }

    /**
     * Ensures invalid constructor arguments raise an InvalidArgumentException.
     *
     * @param string $vendor Vendor name provided to the constructor.
     * @param int    $length Payload length provided to the constructor.
     * @param string $sha1   SHA-1 digest provided to the constructor.
     */
    #[Test]
    #[DataProvider('invalidConstructorArguments')]
    public function constructorRejectsInvalidArguments(string $vendor, int $length, string $sha1): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MakerNotesRecord($vendor, $length, $sha1);
    }
}
