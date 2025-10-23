# PHPStan Follow-up Tasks

Documenting the issues reported by `composer ci:test:php:phpstan` (using `.build/phpstan.neon`) on the initial run. Every item links the original PHPStan message and serves as a resolved follow-up task.

## 2025-02-14 verification

- [x] Re-ran `composer ci:test:php:phpstan` (32/32 tasks) and confirmed the analyser reports `No errors` with the current code base.

## src/Convenience/ExifConvenience.php
- [x] Line 101: Using nullsafe property access `?->value` on left side of `??` is unnecessary. (nullsafe.neverNull)
- [x] Line 115: Using nullsafe property access `?->value` on left side of `??` is unnecessary. (nullsafe.neverNull)
- [x] Line 129: Using nullsafe property access `?->value` on left side of `??` is unnecessary. (nullsafe.neverNull)
- [x] Line 222: Casting to float something that's already float. (cast.useless)
- [x] Line 223: Casting to float something that's already float. (cast.useless)
- [x] Line 224: Casting to float something that's already float. (cast.useless)

## src/Core/MemoryBuffer.php
- [x] Line 117: Cannot access offset 1 on array|false. (offsetAccess.nonOffsetAccessible)
- [x] Line 117: Method MagicSunday\ImageMeta\Core\MemoryBuffer::readU16LE() should return int but returns mixed. (return.type)
- [x] Line 127: Cannot access offset 1 on array|false. (offsetAccess.nonOffsetAccessible)
- [x] Line 127: Method MagicSunday\ImageMeta\Core\MemoryBuffer::readU16BE() should return int but returns mixed. (return.type)
- [x] Line 137: Cannot access offset 1 on array|false. (offsetAccess.nonOffsetAccessible)
- [x] Line 137: Method MagicSunday\ImageMeta\Core\MemoryBuffer::readU32LE() should return int but returns mixed. (return.type)
- [x] Line 147: Cannot access offset 1 on array|false. (offsetAccess.nonOffsetAccessible)
- [x] Line 147: Method MagicSunday\ImageMeta\Core\MemoryBuffer::readU32BE() should return int but returns mixed. (return.type)

## src/Core/Stream.php
- [x] Line 46: Casting to int something that's already int. (cast.useless)
- [x] Line 109: Parameter #2 $length of function fread expects int<1, max>, int<0, max> given. (argument.type)
- [x] Line 135: Cannot access offset 1 on array|false. (offsetAccess.nonOffsetAccessible)
- [x] Line 135: Method MagicSunday\ImageMeta\Core\Stream::readU16BE() should return int but returns mixed. (return.type)
- [x] Line 145: Cannot access offset 1 on array|false. (offsetAccess.nonOffsetAccessible)
- [x] Line 145: Method MagicSunday\ImageMeta\Core\Stream::readU32BE() should return int but returns mixed. (return.type)

## src/Core/StreamWindow.php
- [x] Line 107: Cannot access offset 1 on array|false. (offsetAccess.nonOffsetAccessible)
- [x] Line 107: Method MagicSunday\ImageMeta\Core\StreamWindow::readU16BE() should return int but returns mixed. (return.type)
- [x] Line 117: Cannot access offset 1 on array|false. (offsetAccess.nonOffsetAccessible)
- [x] Line 117: Method MagicSunday\ImageMeta\Core\StreamWindow::readU32BE() should return int but returns mixed. (return.type)

## src/MakerNotes/Registry.php
- [x] Line 51: Casting to string something that's already string. (cast.useless)

## src/Model/Exif/ExifDocument.php
- [x] Line 106: Using nullsafe property access `?->value` on left side of `??` is unnecessary. (nullsafe.neverNull)
- [x] Line 119: Using nullsafe property access `?->value` on left side of `??` is unnecessary. (nullsafe.neverNull)
- [x] Line 132: Using nullsafe property access `?->value` on left side of `??` is unnecessary. (nullsafe.neverNull)
- [x] Line 164: Only booleans are allowed in a negated boolean, MagicSunday\ImageMeta\Model\Exif\Ifd|null given. (booleanNot.exprNotBoolean)
- [x] Line 179: Only booleans are allowed in a negated boolean, string|null given. (booleanNot.exprNotBoolean)
- [x] Line 183: Only booleans are allowed in a ternary operator condition, string|null given. (ternary.condNotBoolean)
- [x] Line 189: Short ternary operator is not allowed. (ternary.shortNotAllowed)
- [x] Line 211: Using nullsafe property access `?->value` on left side of `??` is unnecessary. (nullsafe.neverNull)

## src/Model/Exif/ExifNumericList.php
- [x] Line 35: Call to function array_is_list() with list<float|int> will always evaluate to true. (function.alreadyNarrowedType)
- [x] Line 40: Call to function is_float() with float will always evaluate to true. (function.alreadyNarrowedType)
- [x] Line 40: Result of && is always false. (booleanAnd.alwaysFalse)

## src/Model/Exif/ExifRationalList.php
- [x] Line 33: Call to function array_is_list() with list<MagicSunday\ImageMeta\Model\Exif\ExifRational> will always evaluate to true. (function.alreadyNarrowedType)
- [x] Line 38: Instanceof between MagicSunday\ImageMeta\Model\Exif\ExifRational and MagicSunday\ImageMeta\Model\Exif\ExifRational will always evaluate to true. (instanceof.alwaysTrue)

## src/Model/Exif/ValueConverters.php
- [x] Line 125: Offset 0 on non-empty-list<MagicSunday\ImageMeta\Model\Exif\ExifRational> on left side of ?? always exists and is not nullable. (nullCoalesce.offset)

## src/Model/Xmp/XmpDocument.php
- [x] Line 69: Call to function is_array() with array<int, string> will always evaluate to true. (function.alreadyNarrowedType)

## src/Parse/IsoBmff/IsoBmffExtractor.php
- [x] Line 161: Access to an undefined property object::$type. (property.notFound)
- [x] Line 165: Access to an undefined property object::$userType. (property.notFound)
- [x] Line 166: Access to an undefined property object::$window. (property.notFound)
- [x] Line 166: Parameter #1 $window of method MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffExtractor::readAll() expects MagicSunday\ImageMeta\Core\StreamWindow, mixed given. (argument.type)
- [x] Line 192: Access to an undefined property object::$size. (property.notFound)
- [x] Line 192: Binary operation "+=" between (float|int) and mixed results in an error. (assignOp.invalid)
- [x] Line 246: Access to an undefined property object::$contentSize. (property.notFound)
- [x] Line 503: Access to an undefined property object::$window. (property.notFound)
- [x] Line 504: Cannot call method seek() on mixed. (method.nonObject)
- [x] Line 505: Cannot call method readU8() on mixed. (method.nonObject)
- [x] Line 506: Parameter #1 $window of method MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffExtractor::readUInt24() expects MagicSunday\ImageMeta\Core\StreamWindow, mixed given. (argument.type)
- [x] Line 508: Cannot call method readU16BE() on mixed. (method.nonObject)
- [x] Line 508: Cannot call method readU32BE() on mixed. (method.nonObject)
- [x] Line 509: Cannot call method tell() on mixed. (method.nonObject)
- [x] Line 578: Return type has no value type specified in iterable type array<int, array{...}> (return.type)
- [x] Line 620: Access to an undefined property object::$contentSize. (property.notFound)
- [x] Line 646: Access to an undefined property object::$contentSize. (property.notFound)
- [x] Line 701: Access to an undefined property object::$contentSize. (property.notFound)
- [x] Line 702: Cannot cast mixed to float. (cast.double)
- [x] Line 742: Cannot call method read() on mixed. (method.nonObject)
- [x] Line 762: Access to an undefined property object::$window. (property.notFound)
- [x] Line 763: Cannot call method readU32BE() on mixed. (method.nonObject)
- [x] Line 764: Cannot call method readU32BE() on mixed. (method.nonObject)
- [x] Line 765: Binary operation "-" between mixed and 8 results in an error. (binaryOp.invalid)
- [x] Line 766: Cannot call method read() on mixed. (method.nonObject)
- [x] Line 773: Parameter #1 $string of function trim expects string, mixed given. (argument.type)
- [x] Line 776: Method MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffExtractor::parseDataBox() should return string|null but returns mixed. (return.type)
- [x] Line 823: Casting to string something that's already string. (cast.useless)
- [x] Line 826: Casting to string something that's already string. (cast.useless)
- [x] Line 830: Casting to string something that's already string. (cast.useless)
- [x] Line 849: Casting to string something that's already string. (cast.useless)
- [x] Line 889: Method MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffExtractor::readUInt() should return int but returns mixed. (return.type)
- [x] Line 893: Cannot access offset 1 on array|false. (offsetAccess.nonOffsetAccessible)
- [x] Line 941: Cannot access offset 1 on array|false. (offsetAccess.nonOffsetAccessible)
- [x] Line 943: Method MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffExtractor::fourccToIndex() should return int|null but returns mixed. (return.type)
- [x] Line 956: Access to an undefined property object::$contentSize. (property.notFound)
- [x] Line 960: Access to an undefined property object::$contentOffset. (property.notFound)
- [x] Line 960: Binary operation "+" between mixed and mixed results in an error. (binaryOp.invalid)
- [x] Line 961: Access to an undefined property object::$contentOffset. (property.notFound)
- [x] Line 961: Binary operation "+" between mixed and int<0, max> results in an error. (binaryOp.invalid)
- [x] Line 962: Access to an undefined property object::$contentOffset. (property.notFound)
- [x] Line 962: Binary operation "+" between mixed and mixed results in an error. (binaryOp.invalid)
- [x] Line 966: Generator expects value type object{type: string, size: int, offset: int, contentOffset: int, contentSize: int, window: StreamWindow, userType: string|null}, object given. (generator.valueType)
- [x] Line 967: Access to an undefined property object::$size. (property.notFound)
- [x] Line 967: Binary operation "+=" between (float|int) and mixed results in an error. (assignOp.invalid)

## src/Parse/Tiff/TiffExifReader.php
- [x] Line 82: Only booleans are allowed in an if condition, MagicSunday\ImageMeta\Model\Exif\IfdEntry|null given. (if.condNotBoolean)
- [x] Line 83: Cannot cast ... to int. (cast.int)
- [x] Line 83: Parameter #1 $offset of method ...::readIfd() expects int, mixed given. (argument.type)
- [x] Line 84: Only booleans are allowed in an if condition, MagicSunday\ImageMeta\Model\Exif\IfdEntry|null given. (if.condNotBoolean)
- [x] Line 85: Cannot cast ... to int. (cast.int)
- [x] Line 85: Parameter #1 $offset ... expects int, mixed given. (argument.type)
- [x] Line 88: Only booleans are allowed in an if condition, MagicSunday\ImageMeta\Model\Exif\IfdEntry|null given. (if.condNotBoolean)
- [x] Line 89: Cannot cast ... to int. (cast.int)
- [x] Line 89: Parameter #1 $offset ... expects int, mixed given. (argument.type)
- [x] Line 91: Only booleans are allowed in an if condition, int|null given. (if.condNotBoolean)
- [x] Line 149: Casting to int something that's already int. (cast.useless)
- [x] Line 350: Cannot access offset 1 on array|false. (offsetAccess.nonOffsetAccessible)
- [x] Line 350: Cannot access offset 1 on array|false. (offsetAccess.nonOffsetAccessible)
- [x] Line 350: Method ...::unpackU16() should return int but returns mixed. (return.type)
- [x] Line 376: Cannot access offset 1 on array|false. (offsetAccess.nonOffsetAccessible)
- [x] Line 376: Cannot access offset 1 on array|false. (offsetAccess.nonOffsetAccessible)
- [x] Line 376: Method ...::unpackU32() should return int but returns mixed. (return.type)
- [x] Line 390: Only booleans are allowed in a ternary operator condition, int<0, 2147483648> given. (ternary.condNotBoolean)
- [x] Line 402: Cannot access offset 1 on array|false. (offsetAccess.nonOffsetAccessible)
- [x] Line 402: Cannot access offset 1 on array|false. (offsetAccess.nonOffsetAccessible)
- [x] Line 402: Cannot cast mixed to float. (cast.double)
- [x] Line 414: Cannot access offset 1 on array|false. (offsetAccess.nonOffsetAccessible)
- [x] Line 414: Cannot access offset 1 on array|false. (offsetAccess.nonOffsetAccessible)
- [x] Line 414: Cannot cast mixed to float. (cast.double)
- [x] Line 429: Only booleans are allowed in a ternary operator condition, int given. (ternary.condNotBoolean)

## src/Parse/Xmp/XmpParser.php
- [x] Line 43: Dynamic call to static method XMLReader::XML(). (staticMethod.dynamicCall)
- [x] Line 43: Only booleans are allowed in a negated boolean, bool|XMLReader given. (booleanNot.exprNotBoolean)
- [x] Line 64: Property XMLReader::$namespaceURI (string) on left side of ?? is not nullable. (nullCoalesce.property)
- [x] Line 119: Property XMLReader::$namespaceURI (string) on left side of ?? is not nullable. (nullCoalesce.property)
- [x] Line 184: Property XMLReader::$namespaceURI (string) on left side of ?? is not nullable. (nullCoalesce.property)

## src/Parse/Xmp/XmpReader.php
- [x] Line 43: Dynamic call to static method XMLReader::XML(). (staticMethod.dynamicCall)
- [x] Line 43: Only booleans are allowed in a negated boolean, bool|XMLReader given. (booleanNot.exprNotBoolean)
- [x] Line 56: Property XMLReader::$namespaceURI (string) on left side of ?? is not nullable. (nullCoalesce.property)
- [x] Line 68: Offset int on non-empty-array<int, string> on left side of ?? always exists and is not nullable. (nullCoalesce.offset)
- [x] Line 120: Parameter #1 $data of class MagicSunday\ImageMeta\Model\Xmp\XmpDocument constructor expects array<string, array<int, string>|string>, array<string, array|string> given. (argument.type)
- [x] Line 131: Method ...::finalizeValue() return type has no value type specified in iterable type array. (missingType.iterableValue)

