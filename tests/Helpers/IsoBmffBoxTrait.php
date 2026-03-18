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
use MagicSunday\ImageMeta\Core\StreamWindow;

use function chr;
use function fopen;
use function fwrite;
use function pack;
use function rewind;
use function strlen;

/**
 * Provides helpers to build synthetic ISO BMFF box structures for tests.
 */
trait IsoBmffBoxTrait
{
    /**
     * Creates an in-memory stream populated with the provided bytes.
     */
    private function createIsoBmffTempStream(string $data): Stream
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
     * Creates a window over an in-memory stream for the provided bytes.
     */
    private function createIsoBmffTempWindow(string $data, int $offset = 0, ?int $length = null): StreamWindow
    {
        $windowLength = $length ?? (strlen($data) - $offset);

        return $this->createIsoBmffTempStream($data)
            ->window($offset, $windowLength);
    }

    /**
     * Creates a standard ISO BMFF box header around a payload.
     *
     * @param string $type    Four-character box type.
     * @param string $payload Raw box payload.
     *
     * @return string Serialized box bytes containing the header and payload.
     */
    private function box(string $type, string $payload): string
    {
        $size = 8 + strlen($payload);

        return pack('N', $size) . $type . $payload;
    }

    /**
     * Creates a full box including version and flags fields.
     *
     * @param string $type    Four-character box type.
     * @param string $payload Raw box payload excluding version/flags.
     * @param int    $version Box version field.
     * @param int    $flags   Box flags field.
     *
     * @return string Serialized full box bytes with version and flags header.
     */
    private function fullBox(string $type, string $payload, int $version = 0, int $flags = 0): string
    {
        $header = chr($version) . chr(($flags >> 16) & 0xFF) . chr(($flags >> 8) & 0xFF) . chr($flags & 0xFF);

        return $this->box($type, $header . $payload);
    }
}
