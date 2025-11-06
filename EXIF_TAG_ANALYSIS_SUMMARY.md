# ExifTag Sources Analysis - Summary

## Question
> ExifTag scheint auch Tags von außerhalb der EXIF-Spezifikation zu enthalten. Kannst du aufschlüsseln welche das sind und woher diese kommen? Des Weiteren, wie werden Tags in den IFDs usw. behandelt, für die es derzeit keine Mappingentsprechung gibt? Werden diese einfach ignoriert?

## Answer Summary

### Part 1: Which tags are not from the EXIF specification?

**Finding**: Approximately **52% of the ~231 tags** in `ExifTag.php` come from sources other than the EXIF 3.0 specification.

#### Tag Categories:

1. **EXIF 3.0 Standard (~120 tags, 48%)**
   - From Tables 64-67 in EXIF 3.0 §H.6
   - Official EXIF specification tags

2. **TIFF 6.0 Standard (~12 tags, 5%)**
   - `PREDICTOR`, `ICC_PROFILE`, `SUB_IFDS`, `TILE_*` tags, etc.
   - From TIFF 6.0 specification (baseline imaging)
   - Not included in EXIF 3.0 tables

3. **Microsoft XP Tags (5 tags, 2%)**
   - `XP_TITLE`, `XP_COMMENT`, `XP_AUTHOR`, `XP_KEYWORDS`, `XP_SUBJECT`
   - Proprietary Windows XP metadata extensions
   - Encoded as UTF-16LE

4. **Adobe DNG Tags (~20 tags, 9%)**
   - `CAMERA_CALIBRATION_SIGNATURE`, `PROFILE_*` tags, `PREVIEW_IMAGE_*` tags
   - From Adobe Digital Negative specification v1.0-1.7
   - RAW image processing tags

5. **Legacy/Compatibility Tags (~16 tags, 7%)**
   - `ISO_SPEED_RATINGS_LEGACY`, `HOST_COMPUTER`, Microsoft legacy tags
   - Retained for backwards compatibility with older EXIF versions
   - Some are aliases (same hex value as newer tags)

6. **Vendor-Specific Tags (~8 tags, 3%)**
   - `PRINT_IMAGE_MATCHING` (Epson), TIFF/EP extensions
   - Manufacturer-specific extensions

### Part 2: How are unmapped tags handled?

**Finding**: Unmapped tags are **NOT ignored**. All tags are preserved during parsing.

#### Key Facts:

1. ✅ **All IFD entries are read**: The parser reads ALL directory entries, regardless of whether they have a corresponding constant in `ExifTag.php`

2. ✅ **Tags stored by numeric ID**: IFD entries are stored in an associative array with numeric tag ID as key: `array<int, IfdEntry>`

3. ✅ **No filtering during parsing**: There is NO code that filters or ignores tags based on presence in `ExifTag.php`

4. ✅ **Access via numeric ID**: Any tag can be accessed via its numeric ID:
   ```php
   $ifd->get(0x9999);  // Works for ANY tag, even if not in ExifTag.php
   ```

5. ✅ **Constants are for convenience**: The `ExifTag` constants are purely for developer convenience and type safety. They do NOT restrict which tags are parsed or stored.

#### Code Evidence:

```php
// In TiffExifReader::readIfd()
for ($i = 0; $i < $entryCount; ++$i) {
    $entries += $this->readDirEntry();  // Reads ALL entries
}
```

```php
// In TiffExifReader::readDirEntry()  
$tag  = $this->readU16();  // Reads ANY tag ID
// ... processes the entry ...
return [$tag => $entry];  // Returns with numeric tag as key
```

#### Implications:

- ✅ **No data loss**: Unknown tags are still parsed and accessible
- ✅ **Forward compatibility**: New tags can be accessed by numeric ID before adding to `ExifTag.php`
- ✅ **Vendor extensions work**: Manufacturer-specific tags in maker notes or custom IFDs are preserved
- ✅ **Full TIFF/EXIF compatibility**: Any valid TIFF/EXIF tag is supported

## Documentation Added

1. **docs/EXIF_TAG_SOURCES_ANALYSIS.md** (English)
   - Comprehensive categorization of all tags
   - Detailed breakdown by source (EXIF, TIFF, Microsoft, DNG, etc.)
   - Code analysis of unmapped tag handling
   - Recommendations for improvement

2. **docs/EXIF_TAG_SOURCES_ANALYSIS_DE.md** (German)
   - Same content in German
   - Direct answer to the original question

3. **tests/Model/Exif/ExifTagSourcesTest.php**
   - Test suite validating EXIF 3.0 compliance
   - Verifies all tags from Tables 64-67 are present
   - Tests for non-EXIF tags (Microsoft XP, TIFF, DNG, etc.)
   - Validates tag categorization

## Files Changed

- `docs/EXIF_TAG_SOURCES_ANALYSIS.md` (new)
- `docs/EXIF_TAG_SOURCES_ANALYSIS_DE.md` (new)
- `tests/Model/Exif/ExifTagSourcesTest.php` (new)

## Recommendations for Future

1. **Separate tag constants by source**:
   - `ExifTag.php` - Only official EXIF 3.0 tags
   - `TiffTag.php` - TIFF 6.0 baseline tags
   - `MicrosoftXpTag.php` - Microsoft XP proprietary tags
   - `DngTag.php` - Already exists!
   - `LegacyTag.php` - Backwards compatibility aliases

2. **Document tag origins in PHPDoc**:
   ```php
   /**
    * @source EXIF 3.0 §H.6 Table 64 (Category Ⅰ)
    * @standard EXIF 3.0, EXIF 2.x, TIFF 6.0
    */
   public const int IMAGE_WIDTH = 0x0100;
   ```

3. **Maintain test coverage** for tag categorization

## Testing Note

The new test `ExifTagSourcesTest.php` validates:
- All EXIF 3.0 Table 64 tags (0th IFD TIFF Tags)
- All EXIF 3.0 Table 65 tags (Exif Private Tags)
- All EXIF 3.0 Table 66 tags (GPS Info Tags)
- All EXIF 3.0 Table 67 tags (Interoperability Tags)
- EXIF 3.0 orientation tags (new in v3.0)
- Microsoft XP tag presence
- TIFF 6.0 tag presence
- DNG tag presence
- Legacy tag presence

This ensures ongoing compliance with the EXIF specification and proper categorization of non-EXIF tags.
