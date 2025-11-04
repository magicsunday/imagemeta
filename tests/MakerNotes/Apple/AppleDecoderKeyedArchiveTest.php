<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple;

use LogicException;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\AppleDecoder;
use MagicSunday\ImageMeta\Value\RunTime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function array_is_list;
use function chr;
use function count;
use function get_debug_type;
use function intdiv;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function pack;
use function str_repeat;
use function strlen;

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
        $apple    = $metadata->apple;

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
        $runTime = $apple->runTime;
        self::assertInstanceOf(RunTime::class, $runTime);
        self::assertSame(1, $runTime->epoch);
        self::assertSame(10, $runTime->timescale);
        self::assertSame(50, $runTime->value);
        self::assertSame(3, $runTime->flags);
    }

    #[Test]
    public function decodeBinaryPropertyListSkipsLeadingPadding(): void
    {
        $blob = $this->createKeyedArchiveBlob();
        $raw  = "\x00\xFF" . $blob;

        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'decodeBinaryPropertyList');

        /** @var array<int|string, mixed>|bool|float|int|string|null $result */
        $result = $method->invoke($decoder, $raw);

        self::assertIsArray($result);
        self::assertArrayHasKey('archiver', $result);
    }

    #[Test]
    public function resolveKeyedArchiveDictionaryUnwrapsNestedArchive(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'resolveKeyedArchiveDictionary');

        $archive = [
            'archiver' => 'NSKeyedArchiver',
            'objects'  => [
                null,
                [
                    '$class'            => ['CF$UID' => 2],
                    'ContentIdentifier' => ['CF$UID' => 3],
                ],
                [
                    '$classes'   => ['AppleMakerNotesRoot', 'NSObject'],
                    '$classname' => 'AppleMakerNotesRoot',
                ],
                'wrapped-identifier',
            ],
            'top' => [
                'root' => ['CF$UID' => 1],
            ],
            'version' => 100000,
        ];

        $wrapper = [
            'MakerNote'      => $archive,
            'PayloadVersion' => 1,
        ];

        /** @var array<int|string, mixed>|null $result */
        $result = $method->invoke($decoder, $wrapper);

        self::assertIsArray($result);
        self::assertArrayHasKey('ContentIdentifier', $result);
        self::assertSame('wrapped-identifier', $result['ContentIdentifier']);
    }

    private function plistNull(): BinaryPlistNullValue
    {
        return new BinaryPlistNullValue();
    }

    private function plistInt(int $value): BinaryPlistIntValue
    {
        return new BinaryPlistIntValue($value);
    }

    private function plistFloat(float $value): BinaryPlistFloatValue
    {
        return new BinaryPlistFloatValue($value);
    }

    private function plistString(string $value): BinaryPlistStringValue
    {
        return new BinaryPlistStringValue($value);
    }

    /**
     * @param list<BinaryPlistValue> $values
     */
    private function plistArray(array $values): BinaryPlistArrayValue
    {
        return new BinaryPlistArrayValue($values);
    }

    /**
     * @param array<string, BinaryPlistValue> $entries
     */
    private function plistDict(array $entries): BinaryPlistDictionaryValue
    {
        return new BinaryPlistDictionaryValue($entries);
    }

    private function createKeyedArchiveBlob(): string
    {
        $objects = $this->plistArray([
            $this->plistNull(),
            $this->plistDict([
                '$class'              => $this->plistDict(['CF$UID' => $this->plistInt(2)]),
                'AccelerationVector'  => $this->plistDict(['CF$UID' => $this->plistInt(10)]),
                'ColorTemperature'    => $this->plistDict(['CF$UID' => $this->plistInt(9)]),
                'ContentIdentifier'   => $this->plistDict(['CF$UID' => $this->plistInt(3)]),
                'FocusPosition'       => $this->plistDict(['CF$UID' => $this->plistInt(7)]),
                'HdrGain'             => $this->plistDict(['CF$UID' => $this->plistInt(5)]),
                'HdrHeadroom'         => $this->plistDict(['CF$UID' => $this->plistInt(4)]),
                'LivePhotoVideoIndex' => $this->plistDict(['CF$UID' => $this->plistInt(8)]),
                'SNRSetting'          => $this->plistDict(['CF$UID' => $this->plistInt(6)]),
                'RunTime'             => $this->plistDict(['CF$UID' => $this->plistInt(17)]),
            ]),
            $this->plistDict([
                '$classes' => $this->plistArray([
                    $this->plistString('AppleMakerNotesRoot'),
                    $this->plistString('NSObject'),
                ]),
                '$classname' => $this->plistString('AppleMakerNotesRoot'),
            ]),
            $this->plistString('content-identifier-xyz'),
            $this->plistFloat(6.25),
            $this->plistArray([
                $this->plistDict(['CF$UID' => $this->plistInt(11)]),
                $this->plistDict(['CF$UID' => $this->plistInt(12)]),
                $this->plistDict(['CF$UID' => $this->plistInt(13)]),
            ]),
            $this->plistFloat(10.5),
            $this->plistFloat(0.75),
            $this->plistInt(5),
            $this->plistInt(6500),
            $this->plistArray([
                $this->plistDict(['CF$UID' => $this->plistInt(14)]),
                $this->plistDict(['CF$UID' => $this->plistInt(15)]),
                $this->plistDict(['CF$UID' => $this->plistInt(16)]),
            ]),
            $this->plistFloat(1.1),
            $this->plistFloat(1.2),
            $this->plistFloat(1.3),
            $this->plistFloat(0.1),
            $this->plistFloat(0.2),
            $this->plistFloat(0.3),
            $this->plistDict([
                'epoch'     => $this->plistInt(1),
                'timescale' => $this->plistInt(10),
                'value'     => $this->plistInt(50),
                'flags'     => $this->plistInt(3),
            ]),
        ]);

        $archive = $this->plistDict([
            'archiver' => $this->plistString('NSKeyedArchiver'),
            'objects'  => $objects,
            'top'      => $this->plistDict([
                'root' => $this->plistDict(['CF$UID' => $this->plistInt(1)]),
            ]),
            'version' => $this->plistInt(100000),
        ]);

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

    public function encode(BinaryPlistValue $value): string
    {
        $this->nodes = [];

        $rootIndex = $this->collectValue($value);

        /** @var non-empty-list<BinaryPlistNode> $nodes */
        $nodes         = $this->nodes;
        $objectCount   = count($nodes);
        $objectRefSize = 1;
        while ($objectCount > (1 << (8 * $objectRefSize))) {
            ++$objectRefSize;
        }

        $header  = 'bplist00';
        $objects = '';
        $offsets = [];
        foreach ($nodes as $index => $node) {
            $offsets[$index] = strlen($header) + strlen($objects);
            $objects .= $this->encodeNode($node, $objectRefSize);
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
     * @internal
     */
    public function collectValue(BinaryPlistValue $value): int
    {
        return $value->collect($this);
    }

    public function addNode(BinaryPlistNode $node): int
    {
        $this->nodes[] = $node;

        return count($this->nodes) - 1;
    }

    private function encodeNode(BinaryPlistNode $node, int $objectRefSize): string
    {
        return match ($node->type) {
            'null'   => "\x00",
            'bool'   => $this->encodeBoolean($this->extractBool($node)),
            'int'    => $this->encodeInteger($this->extractInt($node)),
            'float'  => "\x23" . pack('E', $this->extractFloat($node)),
            'string' => $this->encodeString($this->extractString($node)),
            'array'  => $this->encodeArray($this->extractReferenceList($node), $objectRefSize),
            'dict'   => $this->encodeDictionary($this->extractDictionary($node), $objectRefSize),
        };
    }

    private function encodeBoolean(bool $value): string
    {
        return $value ? "\x09" : "\x08";
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

    /**
     * @return array{0: string, 1: string}
     */
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
        $length                 = strlen($value);
        [$marker, $lengthBytes] = $this->encodeLength($length, 0x50);

        return $marker . $lengthBytes . $value;
    }

    /**
     * @param list<int> $children
     */
    private function encodeArray(array $children, int $objectRefSize): string
    {
        $count                  = count($children);
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
        $count                  = count($dictionary['keys']);
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

    private function extractBool(BinaryPlistNode $node): bool
    {
        $value = $node->value;
        if (!is_bool($value)) {
            throw new LogicException('Expected boolean plist node value, got ' . get_debug_type($value));
        }

        return $value;
    }

    private function extractInt(BinaryPlistNode $node): int
    {
        $value = $node->value;
        if (!is_int($value)) {
            throw new LogicException('Expected integer plist node value, got ' . get_debug_type($value));
        }

        return $value;
    }

    private function extractFloat(BinaryPlistNode $node): float
    {
        $value = $node->value;
        if (!is_float($value)) {
            throw new LogicException('Expected float plist node value, got ' . get_debug_type($value));
        }

        return $value;
    }

    private function extractString(BinaryPlistNode $node): string
    {
        $value = $node->value;
        if (!is_string($value)) {
            throw new LogicException('Expected string plist node value, got ' . get_debug_type($value));
        }

        return $value;
    }

    /**
     * @return list<int>
     */
    private function extractReferenceList(BinaryPlistNode $node): array
    {
        $value = $node->value;
        if (!is_array($value) || !array_is_list($value)) {
            throw new LogicException('Expected reference list plist node value, got ' . get_debug_type($value));
        }

        foreach ($value as $reference) {
            if (!is_int($reference)) {
                throw new LogicException('Expected integer reference index, got ' . get_debug_type($reference));
            }
        }

        /** @var list<int> $value */
        return $value;
    }

    /**
     * @return array{keys: list<int>, values: list<int>}
     */
    private function extractDictionary(BinaryPlistNode $node): array
    {
        $value = $node->value;
        if (!is_array($value) || !isset($value['keys'], $value['values'])) {
            throw new LogicException('Expected dictionary plist node value, got ' . get_debug_type($value));
        }

        return [
            'keys'   => $this->extractReferenceList(new BinaryPlistNode('array', $value['keys'])),
            'values' => $this->extractReferenceList(new BinaryPlistNode('array', $value['values'])),
        ];
    }
}

/**
 * @internal
 */
interface BinaryPlistValue
{
    public function collect(BinaryPlistEncoder $encoder): int;
}

/**
 * @internal
 */
final class BinaryPlistNullValue implements BinaryPlistValue
{
    public function collect(BinaryPlistEncoder $encoder): int
    {
        return $encoder->addNode(new BinaryPlistNode('null', null));
    }
}

/**
 * @internal
 */
final readonly class BinaryPlistBoolValue implements BinaryPlistValue
{
    public function __construct(private bool $value)
    {
    }

    public function collect(BinaryPlistEncoder $encoder): int
    {
        return $encoder->addNode(new BinaryPlistNode('bool', $this->value));
    }
}

/**
 * @internal
 */
final readonly class BinaryPlistIntValue implements BinaryPlistValue
{
    public function __construct(private int $value)
    {
    }

    public function collect(BinaryPlistEncoder $encoder): int
    {
        return $encoder->addNode(new BinaryPlistNode('int', $this->value));
    }
}

/**
 * @internal
 */
final readonly class BinaryPlistFloatValue implements BinaryPlistValue
{
    public function __construct(private float $value)
    {
    }

    public function collect(BinaryPlistEncoder $encoder): int
    {
        return $encoder->addNode(new BinaryPlistNode('float', $this->value));
    }
}

/**
 * @internal
 */
final readonly class BinaryPlistStringValue implements BinaryPlistValue
{
    public function __construct(private string $value)
    {
    }

    public function collect(BinaryPlistEncoder $encoder): int
    {
        return $encoder->addNode(new BinaryPlistNode('string', $this->value));
    }
}

/**
 * @internal
 */
final readonly class BinaryPlistArrayValue implements BinaryPlistValue
{
    /**
     * @param list<BinaryPlistValue> $values
     */
    public function __construct(private array $values)
    {
    }

    public function collect(BinaryPlistEncoder $encoder): int
    {
        $references = [];
        foreach ($this->values as $value) {
            $references[] = $encoder->collectValue($value);
        }

        return $encoder->addNode(new BinaryPlistNode('array', $references));
    }
}

/**
 * @internal
 */
final readonly class BinaryPlistDictionaryValue implements BinaryPlistValue
{
    /**
     * @param array<string, BinaryPlistValue> $entries
     */
    public function __construct(private array $entries)
    {
    }

    public function collect(BinaryPlistEncoder $encoder): int
    {
        $keys   = [];
        $values = [];

        foreach ($this->entries as $key => $value) {
            $keys[]   = $encoder->collectValue(new BinaryPlistStringValue($key));
            $values[] = $encoder->collectValue($value);
        }

        return $encoder->addNode(new BinaryPlistNode('dict', ['keys' => $keys, 'values' => $values]));
    }
}

/**
 * @internal
 */
final class BinaryPlistNode
{
    /**
     * @param 'null'|'bool'|'int'|'float'|'string'|'array'|'dict' $type
     * @param array|bool|float|int|string|null                    $value
     *
     * @phpstan-param 'null'|'bool'|'int'|'float'|'string'|'array'|'dict' $type
     * @phpstan-param bool|float|int|string|null|list<int>|array{keys: list<int>, values: list<int>} $value
     */
    public function __construct(
        public string $type,
        public array|bool|float|int|string|null $value,
    ) {
    }
}
