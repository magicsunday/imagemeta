<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple;

use MagicSunday\ImageMeta\Core\PayloadGuard;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\MakerNotes\Apple\ApplePlistArray;
use MagicSunday\ImageMeta\MakerNotes\Apple\ApplePlistDictionary;
use MagicSunday\ImageMeta\MakerNotes\Apple\ApplePlistScalar;
use MagicSunday\ImageMeta\MakerNotes\Apple\BinaryPlistDecoder;
use MagicSunday\ImageMeta\MakerNotes\Apple\PlistBinaryReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function file_get_contents;

/**
 * Exercises BinaryPlistDecoder against a real fixture binary plist payload.
 * It verifies dictionary, array, and scalar nodes are decoded into ApplePlist objects.
 * The suite checks expected keys and values match the fixture contents.
 * This ensures plist decoding remains stable for maker note ingestion.
 *
 * @internal
 */
#[CoversClass(BinaryPlistDecoder::class)]
#[UsesClass(ApplePlistArray::class)]
#[UsesClass(ApplePlistDictionary::class)]
#[UsesClass(ApplePlistScalar::class)]
#[UsesClass(PlistBinaryReader::class)]
#[UsesClass(PayloadGuard::class)]
#[UsesClass(Unpack::class)]
final class BinaryPlistDecoderFixtureTest extends TestCase
{
    private function decodeFixtureRoot(): ApplePlistDictionary
    {
        $decoder = new BinaryPlistDecoder();
        $root    = $decoder->decode($this->loadFixture());
        self::assertInstanceOf(ApplePlistDictionary::class, $root);

        return $root;
    }

    private function assertDictionaryScalarValue(
        ApplePlistDictionary $dictionary,
        string $key,
        int|float|string|bool $expected,
    ): void {
        $value = $dictionary->get($key);
        self::assertInstanceOf(ApplePlistScalar::class, $value);
        self::assertSame($expected, $value->value());
    }

    private function assertArrayScalarValue(ApplePlistArray $array, int $index, int|float|string|bool $expected): void
    {
        $value = $array->get($index);
        self::assertInstanceOf(ApplePlistScalar::class, $value);
        self::assertSame($expected, $value->value());
    }

    private function loadFixture(): string
    {
        $path = __DIR__ . '/../../Fixtures/test_binary.plist';
        $data = file_get_contents($path);
        self::assertIsString($data, 'Fixture not found or not readable: ' . $path);

        return $data;
    }

    /**
     * Decodes a fixture binary plist and validates the expected keys, types, and values.
     * Ensures the decoder produces correct ApplePlist objects for scalars, arrays, and dictionaries.
     */
    #[Test]
    public function itDecodesFixtureAndMatchesExpectedValues(): void
    {
        $root = $this->decodeFixtureRoot();

        // string_type
        $this->assertDictionaryScalarValue($root, 'string_type', 'hello plist');

        // int_type
        $this->assertDictionaryScalarValue($root, 'int_type', 12345);

        // int_short (signed two's complement: 0xFD => -3)
        $this->assertDictionaryScalarValue($root, 'int_short', 253);

        // int_16bit (signed two's complement)
        $this->assertDictionaryScalarValue($root, 'int_16bit', 42767);

        // int_negative
        $this->assertDictionaryScalarValue($root, 'int_negative', -2354);

        // double_type
        $double = $root->get('double_type');
        self::assertInstanceOf(ApplePlistScalar::class, $double);
        self::assertEqualsWithDelta(12.345, (float) $double->value(), 1e-6);

        // bools
        $this->assertDictionaryScalarValue($root, 'bool_type_true', true);
        $this->assertDictionaryScalarValue($root, 'bool_type_false', false);

        // date_type: Binary decoder returns ISO-8601 UTC string
        $this->assertDictionaryScalarValue($root, 'date_type', '2022-02-11T18:27:45+00:00');

        // data_type: base64("Test Value") = raw bytes "Test Value"
        $this->assertDictionaryScalarValue($root, 'data_type', 'Test Value');

        // dict_type
        $dict = $root->get('dict_type');
        self::assertInstanceOf(ApplePlistDictionary::class, $dict);
        $this->assertDictionaryScalarValue($dict, 'key1', 'value1');
        $this->assertDictionaryScalarValue($dict, 'key2', 2);
        $this->assertDictionaryScalarValue($dict, 'long_key_item_name_aaaaa_bbbbb_ccccc_ddddd_eeeee', 'long_key_item_value_11111_22222_33333_44444_55555');

        // array_type
        $arr = $root->get('array_type');
        self::assertInstanceOf(ApplePlistArray::class, $arr);
        self::assertSame(2, $arr->count());
        $this->assertArrayScalarValue($arr, 0, 'array item1');
        $this->assertArrayScalarValue($arr, 1, 'array item2');

        // array_type2
        $arr2 = $root->get('array_type2');
        self::assertInstanceOf(ApplePlistArray::class, $arr2);
        self::assertSame(2, $arr2->count());

        $this->assertArrayScalarValue($arr2, 0, 'array2 item1');

        $arr2_1 = $arr2->get(1);
        self::assertInstanceOf(ApplePlistDictionary::class, $arr2_1);

        $nestArray = $arr2_1->get('nest_array');
        self::assertInstanceOf(ApplePlistArray::class, $nestArray);
        self::assertSame(1, $nestArray->count());
        $this->assertArrayScalarValue($nestArray, 0, 'nest_array_item');

        $nestDict = $arr2_1->get('nest_dict');
        self::assertInstanceOf(ApplePlistDictionary::class, $nestDict);
        $this->assertDictionaryScalarValue($nestDict, 'nest_dict_item', 12345);
    }
}
