<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core;

use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\Traits\NormalisesOffsets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

use function strlen;

/**
 * Dedicated unit tests asserting that guard rails raise informative BoundsError messages.
 */
#[CoversClass(BoundsError::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Stream::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesTrait(NormalisesOffsets::class)]
final class BoundsErrorTest extends TestCase
{
    use CreatesTempStream;

    /**
     * Verifies that BoundsError::class is thrown with message 'read beyond EOF: 4+1 > 4'.
     *
     * @return void
     */
    #[Test]
    public function streamReadBeyondEndReportsContextInBoundsError(): void
    {
        $payload = 'meta';

        $stream = new Stream($this->createTempStream($payload), strlen($payload));

        $stream->read(4);

        $this->expectException(BoundsError::class);
        $this->expectExceptionMessage('read beyond EOF: 4+1 > 4');

        $stream->read(1);
    }

    /**
     * Verifies that BoundsError::class is thrown with message 'MemoryBuffer seek out of range: 6'.
     *
     * @return void
     */
    #[Test]
    public function memoryBufferSeekOutsideRangeReportsAttemptedOffset(): void
    {
        $buffer = new MemoryBuffer('guard');

        $this->expectException(BoundsError::class);
        $this->expectExceptionMessage('MemoryBuffer seek out of range: 6');

        $buffer->seek(6);
    }
}
