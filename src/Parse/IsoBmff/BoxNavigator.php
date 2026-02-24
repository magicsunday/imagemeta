<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\IsoBmff;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\StreamWindow;
use MagicSunday\ImageMeta\Core\Util\Unpack;

use function bin2hex;
use function preg_match;
use function sprintf;
use function strlen;
use function strtoupper;

/**
 * Shared box navigation infrastructure for ISO BMFF parsers.
 *
 * Provides box header reading, child iteration, and utility methods
 * used by all ISO BMFF parsing classes.
 */
final readonly class BoxNavigator
{
    /**
     * @param Stream $stream Stream positioned at the beginning of the media file to parse.
     */
    public function __construct(
        private Stream $stream,
    ) {
    }

    /**
     * Iterates through child boxes within a container, yielding descriptors.
     *
     * @param BoxDescriptor $parent                  Parent box descriptor whose content is iterated.
     * @param int           $offset                  Optional relative byte offset where iteration begins.
     * @param bool          $allowTrailingTerminator When true, tolerates a trailing 4-byte zero terminator
     *                                               at the end of the child list. QuickTime File Format 2012
     *                                               §2 "User Data Atoms" specifies that a udta list may
     *                                               optionally end with a 32-bit integer set to 0.
     *
     * @return iterable<BoxDescriptor>
     */
    public function walkChildren(BoxDescriptor $parent, int $offset = 0, bool $allowTrailingTerminator = false): iterable
    {
        if ($offset < 0 || $offset > $parent->contentSize) {
            throw new ParseError('child offset outside container', 1258);
        }

        $limit  = $parent->contentOffset + $parent->contentSize;
        $cursor = $parent->contentOffset + $offset;
        $end    = $parent->contentOffset + $parent->contentSize;

        while ($cursor + 8 <= $end) {
            $box = $this->readBoxAt($cursor, $limit);
            yield $box;
            $cursor += $box->size;
        }

        if ($cursor !== $end) {
            // QuickTime File Format 2012 §2 "User Data Atoms": a udta child
            // list may optionally end with a 32-bit zero terminator.
            if ($allowTrailingTerminator && (($end - $cursor) === 4)) {
                $this->stream->seek($cursor);
                if ($this->stream->readU32BE() === 0) {
                    return;
                }
            }

            throw new ParseError('child boxes do not align with parent', 1259);
        }
    }

    /**
     * Reads a box header at the given offset and returns a descriptor object.
     *
     * @param int  $offset            Absolute byte offset of the box within the stream.
     * @param int  $limit             Limit offset that bounds the container.
     * @param bool $allowImplicitSize When true, allows size==0 boxes (implicit size to end of container).
     */
    public function readBoxAt(int $offset, int $limit, bool $allowImplicitSize = false): BoxDescriptor
    {
        if ($offset < 0 || $offset > $limit) {
            throw new ParseError('box offset outside container', 1260);
        }

        $this->stream->seek($offset);
        $size32     = $this->stream->readU32BE();
        $type       = $this->stream->read(4);
        $headerSize = 8;
        $size       = $size32;

        if ($size32 === 0) {
            if (!$allowImplicitSize) {
                throw new ParseError('nested box size==0 is only valid at top level', 1362);
            }

            $size = $limit - $offset;
        } elseif ($size32 === 1) {
            $size = $this->stream->readU64BE()->toInt('extended box size');
            $headerSize += 8;
        }

        $userType = null;
        if ($type === BoxType::UUID->value) {
            // uuid box must be at least 24 bytes (8-byte header + 16-byte userType)
            if ($size < 24) {
                throw new ParseError('uuid box size must be at least 24 bytes', 1420);
            }

            $userType = $this->stream->read(16);
            $headerSize += 16;
        }

        if ($size < $headerSize) {
            throw new ParseError('invalid box size for ' . $type, 1261);
        }

        if ($offset + $size > $limit) {
            // Truncated recordings (e.g. interrupted drone/camera captures)
            // commonly have an mdat header written with the intended full
            // recording size while the file ends mid-stream.  Clamping the
            // effective size lets the parser continue scanning for metadata
            // boxes that may follow (or precede) the mdat.
            if ($type === 'mdat' && $allowImplicitSize) {
                $size = $limit - $offset;
            } else {
                throw new ParseError(
                    sprintf('box %s exceeds container bounds', $type), 1262);
            }
        }

        $contentOffset = $offset + $headerSize;
        $contentSize   = $size - $headerSize;
        $window        = $this->stream->window($contentOffset, $contentSize);

        return new BoxDescriptor(
            $type,
            $size,
            $offset,
            $contentOffset,
            $contentSize,
            $window,
            $userType,
        );
    }

    /**
     * Reads an unsigned integer using the specified byte width.
     *
     * @param StreamWindow $window Window to read from.
     * @param int          $bytes  Number of bytes representing the integer.
     */
    public function readUInt(StreamWindow $window, int $bytes): int
    {
        return match ($bytes) {
            0       => 0,
            1       => $window->readU8(),
            2       => $window->readU16BE(),
            3       => Unpack::int('N', "\0" . $window->read(3), '24-bit integer value'),
            4       => $window->readU32BE(),
            8       => $window->readU64BE()->toInt('64-bit integer value'),
            default => throw new ParseError('unsupported integer size ' . $bytes, 1256),
        };
    }

    /**
     * Reads an unsigned 24-bit integer from the provided window.
     *
     * @param StreamWindow $window Window to read from.
     */
    public function readUInt24(StreamWindow $window): int
    {
        return $this->readUInt($window, 3);
    }

    /**
     * Checks whether a four-character code contains printable ASCII.
     *
     * @param string $fourcc Four-character code to test.
     */
    public function isPrintableFourcc(string $fourcc): bool
    {
        if (strlen($fourcc) !== 4) {
            return false;
        }

        if (preg_match('/^[\x20-\x7E]{4}$/', $fourcc) === 1) {
            return true;
        }

        return preg_match('/^\xA9[\x20-\x7E]{3}$/', $fourcc) === 1;
    }

    /**
     * Normalises a four-character code into a printable identifier.
     *
     * @param string $fourcc Raw four-character code bytes.
     */
    public function normaliseFourcc(string $fourcc): string
    {
        if ($this->isPrintableFourcc($fourcc)) {
            return $fourcc;
        }

        return strtoupper(bin2hex($fourcc));
    }

    /**
     * Reads the entire payload of a stream window.
     *
     * @param StreamWindow $window Window to consume.
     */
    public function readAll(StreamWindow $window): string
    {
        $window->seek(0);
        $size = $window->size();

        return $size > 0 ? $window->read($size) : '';
    }
}
