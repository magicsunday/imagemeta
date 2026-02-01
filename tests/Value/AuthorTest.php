<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Author;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Author value object that aggregates creator and rights-holder details.
 * It verifies artist and owner name fields are stored verbatim.
 * The suite checks creator contact fields (email, phone, address) are preserved together.
 * This ensures author metadata remains consistent across structured outputs.
 */
#[CoversClass(Author::class)]
final class AuthorTest extends TestCase
{
    /**
     * Stores the provided artist name.
     * It confirms the object preserves the supplied metadata.
     *
     * @return void
     */
    #[Test]
    public function constructsWithArtistName(): void
    {
        $author = new Author(
            artist: 'John Doe',
            ownerName: null,
            creator: null,
            creatorEmail: null,
            creatorPhone: null,
            creatorAddress: null,
            creatorCity: null,
            creatorRegion: null,
            creatorPostalCode: null,
            creatorCountry: null,
            creatorUrl: null,
            photographer: null,
            imageEditor: null,
        );

        self::assertSame('John Doe', $author->artist);
    }

    /**
     * Stores all author metadata fields when provided.
     * It confirms the object preserves the supplied metadata.
     *
     * @return void
     */
    #[Test]
    public function constructsWithAllAuthorInfo(): void
    {
        $author = new Author(
            artist: 'Jane Smith',
            ownerName: 'Camera Owner',
            creator: 'J. Smith',
            creatorEmail: 'jane@example.com',
            creatorPhone: '+49 30 555',
            creatorAddress: 'Main Street 1',
            creatorCity: 'Berlin',
            creatorRegion: 'BE',
            creatorPostalCode: '10115',
            creatorCountry: 'DE',
            creatorUrl: 'https://example.com',
            photographer: 'Jane Smith Photography',
            imageEditor: 'Jane Smith',
        );

        self::assertSame('Jane Smith', $author->artist);
        self::assertSame('Camera Owner', $author->ownerName);
        self::assertSame('J. Smith', $author->creator);
        self::assertSame('jane@example.com', $author->creatorEmail);
        self::assertSame('+49 30 555', $author->creatorPhone);
        self::assertSame('Main Street 1', $author->creatorAddress);
        self::assertSame('Berlin', $author->creatorCity);
        self::assertSame('BE', $author->creatorRegion);
        self::assertSame('10115', $author->creatorPostalCode);
        self::assertSame('DE', $author->creatorCountry);
        self::assertSame('https://example.com', $author->creatorUrl);
        self::assertSame('Jane Smith Photography', $author->photographer);
        self::assertSame('Jane Smith', $author->imageEditor);
    }

    /**
     * Accepts null author fields without errors.
     * It ensures missing or invalid inputs yield no value.
     *
     * @return void
     */
    #[Test]
    public function allowsNullValues(): void
    {
        $author = new Author(
            artist: null,
            ownerName: null,
            creator: null,
            creatorEmail: null,
            creatorPhone: null,
            creatorAddress: null,
            creatorCity: null,
            creatorRegion: null,
            creatorPostalCode: null,
            creatorCountry: null,
            creatorUrl: null,
            photographer: null,
            imageEditor: null,
        );

        self::assertNull($author->artist);
        self::assertNull($author->ownerName);
        self::assertNull($author->creator);
        self::assertNull($author->creatorEmail);
        self::assertNull($author->creatorPhone);
        self::assertNull($author->creatorAddress);
        self::assertNull($author->creatorCity);
        self::assertNull($author->creatorRegion);
        self::assertNull($author->creatorPostalCode);
        self::assertNull($author->creatorCountry);
        self::assertNull($author->creatorUrl);
        self::assertNull($author->photographer);
        self::assertNull($author->imageEditor);
    }
}
