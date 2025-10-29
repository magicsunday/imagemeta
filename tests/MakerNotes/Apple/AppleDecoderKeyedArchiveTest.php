<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple;

use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\AppleDecoder;
use MagicSunday\ImageMeta\Value\RunTime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_is_list;
use function chr;
use function count;
use function intdiv;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function pack;
use function strlen;
use function str_repeat;

/**
 * @covers \MagicSunday\ImageMeta\MakerNotes\AppleDecoder
 */
final class AppleDecoderKeyedArchiveTest extends TestCase
{
    #[Test]
    public function decodeResolvesNsKeyedArchivePayload(): void
    {
        $blob = $this->createKeyedArchiveBlob();

        $decoder  = new AppleDecoder();
        $metadata = $decoder->decode($blob, 'Apple', 'UnitTestDevice');
        $apple    = $metadata->apple();

        self::assertInstanceOf(AppleMakerNotes::class, $apple);
        self::assertSame('content-identifier-xyz', $apple->contentIdentifier);
        self::assertSame(6.25, $apple->hdrHeadroom);
        self::assertSame([1.1, 1.2, 1.3], $apple->hdrGain);
        self::assertSame(10.5, $apple->snr);
        self::assertSame(0.75, $apple->focusPosition);
        self::assertSame(5, $apple->livePhotoIndex);
        self::assertEqualsWithDelta(0.5, $apple->livePhotoTime, 1e-12);
        self::assertSame(6500, $apple->colorTemperature);
        self::assertSame([0.1, 0.2, 0.3], $apple->accelerationVector);
        self::assertInstanceOf(RunTime::class, $apple->runTime);
        self::assertSame(1, $apple->runTime?->epoch);
        self::assertSame(10, $apple->runTime?->timescale);
        self::assertSame(50, $apple->runTime?->value);
        self::assertSame(3, $apple->runTime?->flags);
    }

    private function createKeyedArchiveBlob(): string
    {
        $objects = [
            null,
            [
                '$class'              => ['CF$UID' => 2],
                'AccelerationVector'  => ['CF$UID' => 10],
                'ColorTemperature'    => ['CF$UID' => 9],
                'ContentIdentifier'   => ['CF$UID' => 3],
                'FocusPosition'       => ['CF$UID' => 7],
                'HdrGain'             => ['CF$UID' => 5],
                'HdrHeadroom'         => ['CF$UID' => 4],
                'LivePhotoVideoIndex' => ['CF$UID' => 8],
                'SNRSetting'          => ['CF$UID' => 6],
                'RunTime'             => ['CF$UID' => 17],
            ],
            [
                '$classes'   => ['AppleMakerNotesRoot', 'NSObject'],
                '$classname' => 'AppleMakerNotesRoot',
            ],
            'content-identifier-xyz',
            6.25,
            [
                ['CF$UID' => 11],
                ['CF$UID' => 12],
                ['CF$UID' => 13],
            ],
            10.5,
            0.75,
            5,
            6500,
            [
                ['CF$UID' => 14],
                ['CF$UID' => 15],
                ['CF$UID' => 16],
            ],
            1.1,
            1.2,
            1.3,
            0.1,
            0.2,
            0.3,
            [
                'epoch'     => 1,
                'timescale' => 10,
                'value'     => 50,
                'flags'     => 3,
            ],
        ];

        $archive = [
            'archiver' => 'NSKeyedArchiver',
            'objects'  => $objects,
            'top'      => ['root' => ['CF$UID' => 1]],
            'version'  => 100000,
        ];

        return (new BinaryPlistEncoder())->encode($archive);
    }
}

/**
 * Minimal binary property list encoder tailored for keyed archive fixtures.
 */
final class BinaryPlistEncoder
{
    /**
     * @var list<BinaryPlistNode>
     */
    private array $nodes = [];

    /**
     * @param array<array-key, array|bool|float|int|string|null>|bool|float|int|string|null $value
     */
    public function encode(array|bool|float|int|string|null $value): string
    {
        $this->nodes = [];

        $rootIndex = $this->collect($value);
        $objectCount = count($this->nodes);
        if ($objectCount === 0) {
            return 'bplist00';
        }

        $objectRefSize = 1;
        while ($objectCount > (1 << (8 * $objectRefSize))) {
            ++$objectRefSize;
        }

        $header  = 'bplist00';
        $objects = '';
        $offsets = [];
        foreach ($this->nodes as $index => $node) {
            $offsets[$index] = strlen($header) + strlen($objects);
            $objects        .= $this->encodeNode($node, $objectRefSize);
        }

        $maxOffset = 0;
        foreach ($offsets as $offset) {
            if ($offset > $maxOffset) {
                $maxOffset = $offset;
            }
        }

        $offsetIntSize = 1;
        while ($maxOffset >= (1 << (8 * $offsetIntSize))) {
            ++$offsetIntSize;
        }

        $offsetTable = '';
        foreach ($offsets as $offset) {
            $offsetTable .= $this->packInt($offset, $offsetIntSize);
        }

        $offsetTableStart = strlen($header) + strlen($objects);

        $trailer = str_repeat("\x00", 6)
            . chr($offsetIntSize)
            . chr($objectRefSize)
            . $this->packUint64($objectCount)
            . $this->packUint64($rootIndex)
            . $this->packUint64($offsetTableStart);

        return $header . $objects . $offsetTable . $trailer;
    }

    /**
     * @param array<array-key, array|bool|float|int|string|null>|bool|float|int|string|null $value
     */
    private function collect(array|bool|float|int|string|null $value): int
    {
        if ($value === null) {
            return $this->addNode(new BinaryPlistNode('null', null));
        }

        if (is_bool($value)) {
            return $this->addNode(new BinaryPlistNode('bool', $value));
        }

        if (is_int($value)) {
            return $this->addNode(new BinaryPlistNode('int', $value));
        }

        if (is_float($value)) {
            return $this->addNode(new BinaryPlistNode('float', $value));
        }

        if (is_string($value)) {
            return $this->addNode(new BinaryPlistNode('string', $value));
        }

        if (!is_array($value)) {
            return $this->addNode(new BinaryPlistNode('null', null));
        }

        if (array_is_list($value)) {
            $children = [];
            foreach ($value as $entry) {
                $children[] = $this->collect($entry);
            }

            return $this->addNode(new BinaryPlistNode('array', $children));
        }

        $keys   = [];
        $values = [];
        foreach ($value as $key => $entry) {
            $keys[]   = $this->collect((string) $key);
            $values[] = $this->collect($entry);
        }

        return $this->addNode(new BinaryPlistNode('dict', ['keys' => $keys, 'values' => $values]));
    }

    private function addNode(BinaryPlistNode $node): int
    {
        $this->nodes[] = $node;

        return count($this->nodes) - 1;
    }

    private function encodeNode(BinaryPlistNode $node, int $objectRefSize): string
    {
        return match ($node->type) {
            'null'   => "\x00",
            'bool'   => $node->value ? "\x09" : "\x08",
            'int'    => $this->encodeInteger((int) $node->value),
            'float'  => "\x23" . pack('E', (float) $node->value),
            'string' => $this->encodeString((string) $node->value),
            'array'  => $this->encodeArray($node->value, $objectRefSize),
            'dict'   => $this->encodeDictionary($node->value, $objectRefSize),
            default  => "\x00",
        };
    }

    private function encodeInteger(int $value): string
    {
        $size = 1;
        if ($value > 0xFF) {
            $size = 2;
        }
        if ($value > 0xFFFF) {
            $size = 4;
        }
        if ($value > 0xFFFFFFFF) {
            $size = 8;
        }

        $marker = 0x10 | match ($size) {
            1       => 0,
            2       => 1,
            4       => 2,
            default => 3,
        };

        return chr($marker) . $this->packInt($value, $size);
    }

    private function encodeLength(int $length, int $markerBase): array
    {
        if ($length < 0x0F) {
            return [chr($markerBase | $length), ''];
        }

        $intObject = $this->encodeInteger($length);

        return [chr($markerBase | 0x0F), $intObject];
    }

    private function encodeString(string $value): string
    {
        $length = strlen($value);
        [$marker, $lengthBytes] = $this->encodeLength($length, 0x50);

        return $marker . $lengthBytes . $value;
    }

    /**
     * @param list<int> $children
     */
    private function encodeArray(array $children, int $objectRefSize): string
    {
        $count = count($children);
        [$marker, $lengthBytes] = $this->encodeLength($count, 0xA0);

        $references = '';
        foreach ($children as $child) {
            $references .= $this->packInt($child, $objectRefSize);
        }

        return $marker . $lengthBytes . $references;
    }

    /**
     * @param array{keys:list<int>,values:list<int>} $dictionary
     */
    private function encodeDictionary(array $dictionary, int $objectRefSize): string
    {
        $count = count($dictionary['keys']);
        [$marker, $lengthBytes] = $this->encodeLength($count, 0xD0);

        $references = '';
        foreach ($dictionary['keys'] as $keyRef) {
            $references .= $this->packInt($keyRef, $objectRefSize);
        }

        foreach ($dictionary['values'] as $valueRef) {
            $references .= $this->packInt($valueRef, $objectRefSize);
        }

        return $marker . $lengthBytes . $references;
    }

    private function packInt(int $value, int $size): string
    {
        return match ($size) {
            1       => chr($value),
            2       => pack('n', $value),
            4       => pack('N', $value),
            8       => pack('N2', intdiv($value, 0x100000000), $value % 0x100000000),
            default => '',
        };
    }

    private function packUint64(int $value): string
    {
        return pack('N2', intdiv($value, 0x100000000), $value % 0x100000000);
    }
}

/**
 * @internal
 */
final class BinaryPlistNode
{
    /**
     * @param 'null'|'bool'|'int'|'float'|'string'|'array'|'dict' $type
     * @param mixed                                               $value
     */
    public function __construct(
        public string $type,
        public mixed $value,
    ) {
    }
}
