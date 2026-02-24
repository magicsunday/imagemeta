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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the MakerNotesRecord value object for vendor, length, and digest fields.
 * It verifies valid records store identifiers and optional vendor-specific data.
 * The suite checks invalid argument combinations raise the expected exceptions.
 * This ensures maker note records remain well-formed for downstream use.
 */
#[CoversClass(MakerNotesRecord::class)]
final class MakerNotesRecordTest extends TestCase
{
    /**
     * Constructs a MakerNotesRecord with valid vendor, length, and SHA-1 values.
     * Verifies the fields are stored and optional vendor-specific records remain null.
     */
    #[Test]
    public function constructorAcceptsValidArguments(): void
    {
        $metadata = new MakerNotesRecord('Contoso', 123, '0123456789abcdef0123456789abcdef01234567');

        self::assertSame('Contoso', $metadata->vendor);
        self::assertSame(123, $metadata->length);
        self::assertSame('0123456789abcdef0123456789abcdef01234567', $metadata->sha1);
        self::assertNull($metadata->apple);
        self::assertNull($metadata->samsung);
    }

    /**
     * Provides invalid constructor argument combinations.
     * This checks the behavior for the specific inputs used in the test.
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
     * Raises the expected exception for the error path.
     * This verifies guardrails and error reporting behavior.
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
