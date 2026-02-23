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
use MagicSunday\ImageMeta\Value\CreatorContact;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Author value object that aggregates creator and rights-holder details.
 * It verifies artist and owner name fields are stored verbatim.
 * The suite checks creator contact fields (email, phone, address) are preserved together.
 * This ensures author metadata remains consistent across structured outputs.
 */
#[UsesClass(CreatorContact::class)]
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
        $contact = new CreatorContact(
            email: 'jane@example.com',
            phone: '+49 30 555',
            address: 'Main Street 1',
            city: 'Berlin',
            region: 'BE',
            postalCode: '10115',
            country: 'DE',
            url: 'https://example.com',
        );

        $author = new Author(
            artist: 'Jane Smith',
            ownerName: 'Camera Owner',
            creator: 'J. Smith',
            contact: $contact,
            photographer: 'Jane Smith Photography',
            imageEditor: 'Jane Smith',
        );

        self::assertSame('Jane Smith', $author->artist);
        self::assertSame('Camera Owner', $author->ownerName);
        self::assertSame('J. Smith', $author->creator);
        self::assertNotNull($author->contact);
        self::assertSame('jane@example.com', $author->contact->email);
        self::assertSame('+49 30 555', $author->contact->phone);
        self::assertSame('Main Street 1', $author->contact->address);
        self::assertSame('Berlin', $author->contact->city);
        self::assertSame('BE', $author->contact->region);
        self::assertSame('10115', $author->contact->postalCode);
        self::assertSame('DE', $author->contact->country);
        self::assertSame('https://example.com', $author->contact->url);
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
        $author = new Author();

        self::assertNull($author->artist);
        self::assertNull($author->ownerName);
        self::assertNull($author->creator);
        self::assertNull($author->contact);
        self::assertNull($author->photographer);
        self::assertNull($author->imageEditor);
    }
}
