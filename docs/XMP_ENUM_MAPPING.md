# XMP Enum Mapping Fix

## Issue
The `scripts/imagemeta-format.php` script was not mapping XMP values to their enum names, unlike EXIF values which display enumerated names in parentheses.

### Before
```
---- XMP-exif ----
     - MeteringMode                    : 2
     - LightSource                     : 0
     - ColorSpace                      : 1

---- XMP-tiff ----
     - Compression                     : 6
     - Orientation                     : 1
```

### After
```
---- XMP-exif ----
     - MeteringMode                    : 2 (Center Weighted Average)
     - LightSource                     : 0 (Unknown)
     - ColorSpace                      : 1 (sRGB)

---- XMP-tiff ----
     - Compression                     : 6 (JPEG)
     - Orientation                     : 1 (Horizontal Normal)
```

## Solution

Added XMP enum mapping functionality to the formatter:

1. **New Property: `xmpPropertyToEnumMap`**
   - Maps XMP namespace+localName combinations to enum class names
   - Covers both EXIF and TIFF namespace properties

2. **New Method: `buildXmpEnumMap()`**
   - Builds the mapping for common XMP properties
   - Reuses existing EXIF/TIFF enum classes

3. **New Method: `convertXmpValueToEnum()`**
   - Converts raw XMP string/array values to enum instances
   - Handles numeric string parsing (`"2"` → `2`)
   - Handles array values (uses first element)

4. **Updated: `printXmpSections()`**
   - Applies enum conversion before displaying XMP values
   - Maintains backward compatibility with non-enum properties

## Supported Properties

### EXIF Namespace (http://ns.adobe.com/exif/1.0/)
- ColorSpace
- ExposureProgram
- MeteringMode
- LightSource
- WhiteBalance
- ExposureMode
- SceneCaptureType
- GainControl
- Contrast
- Saturation
- Sharpness
- SubjectDistanceRange
- SensingMethod
- FileSource
- SceneType
- CustomRendered

### TIFF Namespace (http://ns.adobe.com/tiff/1.0/)
- Orientation
- Compression
- PhotometricInterpretation
- PlanarConfiguration
- ResolutionUnit
- YCbCrPositioning

## Testing

### Unit Tests
- `tests/Scripts/MetadataFormatterXmpEnumTest.php`
  - Tests enum conversion for EXIF and TIFF namespace properties
  - Tests non-enum properties pass through unchanged
  - Tests array value and numeric string handling

### Regression Tests
- `tests/Parse/Xmp/XmpParserCompleteExtractionTest.php`
  - Tests that all properties from multiple rdf:Description elements are extracted
  - Regression test for Samsung GT-I9195 example from the issue

## Technical Details

### Enum Value Matching
XMP stores all values as strings, but enums expect integers. The conversion method:
1. Checks if the value is numeric
2. Converts to int/float as appropriate
3. Calls the enum's `fromExifValue()` method
4. Returns the enum instance or original value if conversion fails

### Namespace Handling
XMP properties use Clark notation internally: `{namespace}localName`

Example:
- Internal: `{http://ns.adobe.com/exif/1.0/}MeteringMode`
- Mapping key: `http://ns.adobe.com/exif/1.0/MeteringMode`

### Backward Compatibility
- Non-enum properties continue to display as-is
- Failed enum conversions fall back to original value
- Unknown namespaces are handled gracefully

## Example Usage

```php
$reader = new MetadataReader();
$metadata = $reader->read('photo.jpg');

$formatter = new MetadataFormatter();
$formatter->format('photo.jpg');
```

Output now includes enum names for XMP values, matching the format used for EXIF values.

## Related Files
- `scripts/imagemeta-format.php` - Formatter implementation
- `src/Parse/Xmp/XmpParser.php` - XMP parser (unchanged, already working correctly)
- `src/Value/Enum/*.php` - Enum definitions (reused from EXIF implementation)
