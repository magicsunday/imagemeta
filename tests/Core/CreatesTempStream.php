<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core;

use PHPUnit\Framework\Assert;

use function fopen;
use function fwrite;
use function rewind;
use function strlen;

/**
 * Helper for creating temporary in-memory streams populated with fixture payloads.
 */
trait CreatesTempStream
{
    /**
     * Creates a temporary stream populated with the provided payload.
     *
     * @param string $payload Bytes that should be written to the stream.
     *
     * @return resource Writable stream resource positioned at the beginning of the payload.
     */
    private function createTempStream(string $payload)
    {
        $handle = fopen('php://temp', 'r+b');

        if ($handle === false) {
            Assert::fail('Unable to create temporary stream.');
        }

        $written = fwrite($handle, $payload);

        if ($written === false || $written !== strlen($payload)) {
            Assert::fail('Unable to populate temporary stream.');
        }

        if (rewind($handle) === false) {
            Assert::fail('Unable to rewind temporary stream.');
        }

        return $handle;
    }
}
