<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Helpers;

use MagicSunday\ImageMeta\Core\Stream;

use function fopen;
use function fwrite;
use function pack;
use function rewind;
use function str_pad;
use function strlen;

/**
 * Provides helpers to build synthetic RIFF chunk structures for tests.
 */
trait RiffChunkTrait
{
    /**
     * Creates an in-memory stream populated with the provided bytes.
     */
    private function createRiffTempStream(string $data): Stream
    {
        $handle = fopen('php://temp', 'wb+');

        if ($handle === false) {
            self::fail('Unable to create temporary stream handle.');
        }

        $bytesWritten = fwrite($handle, $data);

        if ($bytesWritten !== strlen($data)) {
            self::fail('Unable to populate temporary stream data.');
        }

        if (rewind($handle) === false) {
            self::fail('Unable to rewind temporary stream handle.');
        }

        return new Stream($handle, strlen($data));
    }

    /**
     * Builds a RIFF chunk (8-byte header + payload + optional pad byte).
     *
     * RIFF 1991 section 2: chunk = ckID (4) + ckSize (4, LE) + ckData + optional pad.
     */
    private function riffChunk(string $type, string $payload): string
    {
        $padded = str_pad($type, 4);
        $chunk  = $padded . pack('V', strlen($payload)) . $payload;

        // WORD alignment: add pad byte if payload length is odd
        if ((strlen($payload) & 1) !== 0) {
            $chunk .= "\x00";
        }

        return $chunk;
    }

    /**
     * Builds a RIFF LIST chunk.
     *
     * RIFF 1991 section 2: LIST = 'LIST' + size (4, LE) + listType (4) + subchunks.
     */
    private function riffList(string $listType, string $childrenPayload): string
    {
        $innerPayload = str_pad($listType, 4) . $childrenPayload;

        return $this->riffChunk('LIST', $innerPayload);
    }

    /**
     * Builds a complete RIFF 'AVI ' container.
     */
    private function riffAviContainer(string $body): string
    {
        $payload = 'AVI ' . $body;

        return 'RIFF' . pack('V', strlen($payload)) . $payload;
    }

    /**
     * Builds a null-terminated string for INFO chunks.
     */
    private function infoString(string $value): string
    {
        return $value . "\x00";
    }
}
