<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\MakerNotes\Apple\ApplePlistArray;
use MagicSunday\ImageMeta\MakerNotes\Apple\ApplePlistDictionary;
use MagicSunday\ImageMeta\MakerNotes\Apple\ApplePlistScalar;
use MagicSunday\ImageMeta\MakerNotes\Apple\BinaryPlistDecoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function is_string;

final class BinaryPlistDecoderFixtureTest extends TestCase
{
    private function loadFixture(): string
    {
        $path = __DIR__ . '/../../Fixtures/test_binary.plist';
        $data = file_get_contents($path);
        $this->assertIsString($data, 'Fixture not found or not readable: ' . $path);

        return $data;
    }

    #[Test]
    public function it_decodes_fixture_and_matches_expected_values(): void
    {
        $data = $this->loadFixture();
        $decoder = new BinaryPlistDecoder();
        $root = $decoder->decode($data);

        // Root should be a dictionary
        $this->assertInstanceOf(ApplePlistDictionary::class, $root);

        // string_type
        $string = $root->get('string_type');
        $this->assertInstanceOf(ApplePlistScalar::class, $string);
        $this->assertSame('hello plist', $string->value());

        // int_type
        $intType = $root->get('int_type');
        $this->assertInstanceOf(ApplePlistScalar::class, $intType);
        $this->assertSame(12345, $intType->value());

        // int_short (signed two's complement: 0xFD => -3)
        $intShort = $root->get('int_short');
        $this->assertInstanceOf(ApplePlistScalar::class, $intShort);
        $this->assertSame(253, $intShort->value());

        // int_16bit (signed two's complement)
        $int16 = $root->get('int_16bit');
        $this->assertInstanceOf(ApplePlistScalar::class, $int16);
        $this->assertSame(42767, $int16->value());

        // int_negative
        $intNeg = $root->get('int_negative');
        $this->assertInstanceOf(ApplePlistScalar::class, $intNeg);
        $this->assertSame(-2354, $intNeg->value());

        // double_type
        $double = $root->get('double_type');
        $this->assertInstanceOf(ApplePlistScalar::class, $double);
        $this->assertEqualsWithDelta(12.345, (float) $double->value(), 1e-6);

        // bools
        $boolTrue = $root->get('bool_type_true');
        $this->assertInstanceOf(ApplePlistScalar::class, $boolTrue);
        $this->assertTrue((bool) $boolTrue->value());

        $boolFalse = $root->get('bool_type_false');
        $this->assertInstanceOf(ApplePlistScalar::class, $boolFalse);
        $this->assertFalse((bool) $boolFalse->value());

        // date_type: Binary decoder returns ISO-8601 UTC string
        $date = $root->get('date_type');
        $this->assertInstanceOf(ApplePlistScalar::class, $date);
        $this->assertSame('2022-02-11T18:27:45Z', $date->value());

        // data_type: base64("Test Value") = raw bytes "Test Value"
        $dataType = $root->get('data_type');
        $this->assertInstanceOf(ApplePlistScalar::class, $dataType);
        $this->assertSame('Test Value', $dataType->value());

        // dict_type
        $dict = $root->get('dict_type');
        $this->assertInstanceOf(ApplePlistDictionary::class, $dict);
        $k1 = $dict->get('key1');
        $this->assertInstanceOf(ApplePlistScalar::class, $k1);
        $this->assertSame('value1', $k1->value());
        $k2 = $dict->get('key2');
        $this->assertInstanceOf(ApplePlistScalar::class, $k2);
        $this->assertSame(2, $k2->value());
        $kl = $dict->get('long_key_item_name_aaaaa_bbbbb_ccccc_ddddd_eeeee');
        $this->assertInstanceOf(ApplePlistScalar::class, $kl);
        $this->assertSame('long_key_item_value_11111_22222_33333_44444_55555', $kl->value());

        // array_type
        $arr = $root->get('array_type');
        $this->assertInstanceOf(ApplePlistArray::class, $arr);
        $this->assertSame(2, $arr->count());
        $a0 = $arr->get(0); $a1 = $arr->get(1);
        $this->assertInstanceOf(ApplePlistScalar::class, $a0);
        $this->assertSame('array item1', $a0->value());
        $this->assertInstanceOf(ApplePlistScalar::class, $a1);
        $this->assertSame('array item2', $a1->value());

        // array_type2
        $arr2 = $root->get('array_type2');
        $this->assertInstanceOf(ApplePlistArray::class, $arr2);
        $this->assertSame(2, $arr2->count());

        $arr2_0 = $arr2->get(0);
        $this->assertInstanceOf(ApplePlistScalar::class, $arr2_0);
        $this->assertSame('array2 item1', $arr2_0->value());

        $arr2_1 = $arr2->get(1);
        $this->assertInstanceOf(ApplePlistDictionary::class, $arr2_1);

        $nestArray = $arr2_1->get('nest_array');
        $this->assertInstanceOf(ApplePlistArray::class, $nestArray);
        $this->assertSame(1, $nestArray->count());
        $nestArray0 = $nestArray->get(0);
        $this->assertInstanceOf(ApplePlistScalar::class, $nestArray0);
        $this->assertSame('nest_array_item', $nestArray0->value());

        $nestDict = $arr2_1->get('nest_dict');
        $this->assertInstanceOf(ApplePlistDictionary::class, $nestDict);
        $ndi = $nestDict->get('nest_dict_item');
        $this->assertInstanceOf(ApplePlistScalar::class, $ndi);
        $this->assertSame(12345, $ndi->value());
    }
}
