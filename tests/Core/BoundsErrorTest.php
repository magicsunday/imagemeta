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
 * Exercises BoundsError scenarios triggered by stream and buffer guard rails.
 * It forces read and seek operations beyond valid limits to ensure exceptions are raised.
 * The assertions require precise, contextual error messages for debugging.
 * This keeps bounds violations explicit and actionable instead of silent failures.
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
     * Attempts to read past EOF after consuming the entire stream.
     * It verifies the BoundsError message includes the read range and stream size.
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
     * Attempts to seek beyond the buffer length.
     * It verifies the BoundsError message reports the attempted offset.
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
