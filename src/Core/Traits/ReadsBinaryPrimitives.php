<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core\Traits;

use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\Util\UInt64;

/**
 * Provides shared implementations for reading primitive types from a ByteReader backed source.
 */
trait ReadsBinaryPrimitives
{
    /**
     * Returns the byte reader used to access the underlying data source.
     */
    abstract protected function byteReader(): ByteReader;

    public function readU8(): int
    {
        return $this->byteReader()->readU8();
    }

    public function readU16BE(): int
    {
        return $this->byteReader()->readU16BE();
    }

    public function readU32BE(): int
    {
        return $this->byteReader()->readU32BE();
    }

    public function readU64BE(): UInt64
    {
        return $this->byteReader()->readU64BE();
    }
}
