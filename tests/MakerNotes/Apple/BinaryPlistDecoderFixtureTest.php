<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple;

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
final class BinaryPlistDecoderFixtureTest extends TestCase
{
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
     *
     * @return void
     */
    #[Test]
    public function itDecodesFixtureAndMatchesExpectedValues(): void
    {
        $data    = $this->loadFixture();
        $decoder = new BinaryPlistDecoder();
        $root    = $decoder->decode($data);

        // Root should be a dictionary
        self::assertInstanceOf(ApplePlistDictionary::class, $root);

        // string_type
        $string = $root->get('string_type');
        self::assertInstanceOf(ApplePlistScalar::class, $string);
        self::assertSame('hello plist', $string->value());

        // int_type
        $intType = $root->get('int_type');
        self::assertInstanceOf(ApplePlistScalar::class, $intType);
        self::assertSame(12345, $intType->value());

        // int_short (signed two's complement: 0xFD => -3)
        $intShort = $root->get('int_short');
        self::assertInstanceOf(ApplePlistScalar::class, $intShort);
        self::assertSame(253, $intShort->value());

        // int_16bit (signed two's complement)
        $int16 = $root->get('int_16bit');
        self::assertInstanceOf(ApplePlistScalar::class, $int16);
        self::assertSame(42767, $int16->value());

        // int_negative
        $intNeg = $root->get('int_negative');
        self::assertInstanceOf(ApplePlistScalar::class, $intNeg);
        self::assertSame(-2354, $intNeg->value());

        // double_type
        $double = $root->get('double_type');
        self::assertInstanceOf(ApplePlistScalar::class, $double);
        self::assertEqualsWithDelta(12.345, (float) $double->value(), 1e-6);

        // bools
        $boolTrue = $root->get('bool_type_true');
        self::assertInstanceOf(ApplePlistScalar::class, $boolTrue);
        self::assertTrue((bool) $boolTrue->value());

        $boolFalse = $root->get('bool_type_false');
        self::assertInstanceOf(ApplePlistScalar::class, $boolFalse);
        self::assertFalse((bool) $boolFalse->value());

        // date_type: Binary decoder returns ISO-8601 UTC string
        $date = $root->get('date_type');
        self::assertInstanceOf(ApplePlistScalar::class, $date);
        self::assertSame('2022-02-11T18:27:45+00:00', $date->value());

        // data_type: base64("Test Value") = raw bytes "Test Value"
        $dataType = $root->get('data_type');
        self::assertInstanceOf(ApplePlistScalar::class, $dataType);
        self::assertSame('Test Value', $dataType->value());

        // dict_type
        $dict = $root->get('dict_type');
        self::assertInstanceOf(ApplePlistDictionary::class, $dict);
        $k1 = $dict->get('key1');
        self::assertInstanceOf(ApplePlistScalar::class, $k1);
        self::assertSame('value1', $k1->value());
        $k2 = $dict->get('key2');
        self::assertInstanceOf(ApplePlistScalar::class, $k2);
        self::assertSame(2, $k2->value());
        $kl = $dict->get('long_key_item_name_aaaaa_bbbbb_ccccc_ddddd_eeeee');
        self::assertInstanceOf(ApplePlistScalar::class, $kl);
        self::assertSame('long_key_item_value_11111_22222_33333_44444_55555', $kl->value());

        // array_type
        $arr = $root->get('array_type');
        self::assertInstanceOf(ApplePlistArray::class, $arr);
        self::assertSame(2, $arr->count());
        $a0 = $arr->get(0);
        $a1 = $arr->get(1);
        self::assertInstanceOf(ApplePlistScalar::class, $a0);
        self::assertSame('array item1', $a0->value());
        self::assertInstanceOf(ApplePlistScalar::class, $a1);
        self::assertSame('array item2', $a1->value());

        // array_type2
        $arr2 = $root->get('array_type2');
        self::assertInstanceOf(ApplePlistArray::class, $arr2);
        self::assertSame(2, $arr2->count());

        $arr2_0 = $arr2->get(0);
        self::assertInstanceOf(ApplePlistScalar::class, $arr2_0);
        self::assertSame('array2 item1', $arr2_0->value());

        $arr2_1 = $arr2->get(1);
        self::assertInstanceOf(ApplePlistDictionary::class, $arr2_1);

        $nestArray = $arr2_1->get('nest_array');
        self::assertInstanceOf(ApplePlistArray::class, $nestArray);
        self::assertSame(1, $nestArray->count());
        $nestArray0 = $nestArray->get(0);
        self::assertInstanceOf(ApplePlistScalar::class, $nestArray0);
        self::assertSame('nest_array_item', $nestArray0->value());

        $nestDict = $arr2_1->get('nest_dict');
        self::assertInstanceOf(ApplePlistDictionary::class, $nestDict);
        $ndi = $nestDict->get('nest_dict_item');
        self::assertInstanceOf(ApplePlistScalar::class, $ndi);
        self::assertSame(12345, $ndi->value());
    }
}
