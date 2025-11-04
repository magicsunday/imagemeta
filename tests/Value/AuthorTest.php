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
 * Tests for the Author value object.
 */
#[CoversClass(Author::class)]
final class AuthorTest extends TestCase
{
    #[Test]
    public function constructsWithArtistName(): void
    {
        $author = new Author(
            artist: 'John Doe',
            ownerName: null,
            creator: null,
            creatorEmail: null,
            photographer: null,
            imageEditor: null,
        );

        self::assertSame('John Doe', $author->artist);
    }

    #[Test]
    public function constructsWithAllAuthorInfo(): void
    {
        $author = new Author(
            artist: 'Jane Smith',
            ownerName: 'Camera Owner',
            creator: 'J. Smith',
            creatorEmail: 'jane@example.com',
            photographer: 'Jane Smith Photography',
            imageEditor: 'Jane Smith',
        );

        self::assertSame('Jane Smith', $author->artist);
        self::assertSame('Camera Owner', $author->ownerName);
        self::assertSame('J. Smith', $author->creator);
        self::assertSame('jane@example.com', $author->creatorEmail);
        self::assertSame('Jane Smith Photography', $author->photographer);
        self::assertSame('Jane Smith', $author->imageEditor);
    }

    #[Test]
    public function allowsNullValues(): void
    {
        $author = new Author(
            artist: null,
            ownerName: null,
            creator: null,
            creatorEmail: null,
            photographer: null,
            imageEditor: null,
        );

        self::assertNull($author->artist);
        self::assertNull($author->ownerName);
        self::assertNull($author->creator);
        self::assertNull($author->creatorEmail);
        self::assertNull($author->photographer);
        self::assertNull($author->imageEditor);
    }
}
