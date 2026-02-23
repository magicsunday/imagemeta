<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

use function strlen;
use function substr;

/**
 * Mutable text cursor for Apple plist text parsing.
 *
 * Replaces by-reference offset threading with an object that tracks the parsing
 * position within a raw string buffer. The cursor is forward-only and provides
 * primitive navigation methods for recursive descent parsing.
 */
final class PlistTextCursor
{
    private int $offset = 0;

    private readonly int $length;

    public function __construct(
        private readonly string $raw,
    ) {
        $this->length = strlen($raw);
    }

    /**
     * Returns true when the cursor has reached or passed the end of the buffer.
     */
    public function isAtEnd(): bool
    {
        return $this->offset >= $this->length;
    }

    /**
     * Returns the character at the current position without advancing.
     */
    public function peek(): string
    {
        return $this->raw[$this->offset];
    }

    /**
     * Advances the cursor by one position.
     */
    public function advance(): void
    {
        ++$this->offset;
    }

    /**
     * Advances the cursor by the specified number of positions.
     */
    public function advanceBy(int $n): void
    {
        $this->offset += $n;
    }

    /**
     * Returns the current byte offset within the buffer.
     */
    public function offset(): int
    {
        return $this->offset;
    }

    /**
     * Returns the total length of the buffer.
     */
    public function length(): int
    {
        return $this->length;
    }

    /**
     * Extracts a substring from the underlying buffer.
     */
    public function substr(int $start, int $length): string
    {
        return substr($this->raw, $start, $length);
    }

    /**
     * Returns the full raw buffer.
     */
    public function raw(): string
    {
        return $this->raw;
    }
}
