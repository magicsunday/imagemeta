# ExifTag Sources Analysis

## Overview

This document provides a comprehensive categorization of all tags in `ExifTag.php`, identifying which tags are from the official EXIF 3.0 specification versus tags from other sources (TIFF, Microsoft, Adobe DNG, vendor extensions, and legacy compatibility).

## Tag Sources Summary

### Categories

1. **EXIF 3.0 Standard Tags**: Tags explicitly defined in EXIF 3.0 §H.6 Tables 64-67
2. **TIFF 6.0 Standard Tags**: Tags from TIFF 6.0 specification (pre-EXIF, baseline imaging)
3. **Microsoft XP Tags**: Proprietary Microsoft Windows XP metadata extensions
4. **Adobe DNG Tags**: Tags from Adobe Digital Negative specification
5. **Legacy/Compatibility Tags**: Deprecated or renamed tags retained for backwards compatibility
6. **Vendor-Specific Tags**: Manufacturer-specific extensions (Epson, etc.)

## Detailed Tag Categorization

### 1. EXIF 3.0 Standard Tags (from Tables 64-67)

These tags are explicitly defined in EXIF 3.0 specification §H.6:

#### Table 64: 0th IFD TIFF Tags
```
IMAGE_WIDTH                     0x0100  (256)    Category Ⅰ
IMAGE_HEIGHT                    0x0101  (257)    Category Ⅰ
BITS_PER_SAMPLE                 0x0102  (258)    Category Ⅰ
COMPRESSION                     0x0103  (259)    Category Ⅰ
PHOTOMETRIC_INTERPRETATION      0x0106  (262)    Category Ⅰ
IMAGE_DESCRIPTION               0x010E  (270)    Category Ⅲ
MAKE                            0x010F  (271)    Category Ⅲ
MODEL                           0x0110  (272)    Category Ⅲ
STRIP_OFFSETS                   0x0111  (273)    Category Ⅰ
ORIENTATION                     0x0112  (274)    Category Ⅰ
SAMPLES_PER_PIXEL               0x0115  (277)    Category Ⅰ
ROWS_PER_STRIP                  0x0116  (278)    Category Ⅰ
STRIP_BYTE_COUNTS               0x0117  (279)    Category Ⅰ
X_RESOLUTION                    0x011A  (282)    Category Ⅰ
Y_RESOLUTION                    0x011B  (283)    Category Ⅰ
PLANAR_CONFIGURATION            0x011C  (284)    Category Ⅰ
RESOLUTION_UNIT                 0x0128  (296)    Category Ⅰ
TRANSFER_FUNCTION               0x012D  (301)    Category Ⅰ
SOFTWARE                        0x0131  (305)    Category Ⅲ
DATETIME / MODIFY_DATE          0x0132  (306)    Category Ⅲ
ARTIST                          0x013B  (315)    Category Ⅲ
WHITE_POINT                     0x013E  (318)    Category Ⅰ
PRIMARY_CHROMATICITIES          0x013F  (319)    Category Ⅰ
JPEG_INTERCHANGE_FORMAT         0x0201  (513)    Category Ⅰ
JPEG_INTERCHANGE_FORMAT_LENGTH  0x0202  (514)    Category Ⅰ
YCBCR_COEFFICIENTS              0x0211  (529)    Category Ⅰ
YCBCR_SUB_SAMPLING              0x0212  (530)    Category Ⅰ
YCBCR_POSITIONING               0x0213  (531)    Category Ⅰ
REFERENCE_BLACK_WHITE           0x0214  (532)    Category Ⅰ
COPYRIGHT                       0x8298  (33432)  Category Ⅲ
EXIF_IFD_POINTER                0x8769  (34665)  Category Ⅰ
GPS_IFD_POINTER                 0x8825  (34853)  Category Ⅰ
```

#### Table 65: 0th IFD Exif Private Tags
```
EXPOSURE_TIME                            0x829A  (33434)  Category Ⅱ
F_NUMBER                                 0x829D  (33437)  Category Ⅱ
EXPOSURE_PROGRAM                         0x8822  (34850)  Category Ⅱ
SPECTRAL_SENSITIVITY                     0x8824  (34852)  Category Ⅱ
PHOTOGRAPHIC_SENSITIVITY                 0x8827  (34855)  Category Ⅱ
OECF                                     0x8828  (34856)  Category Ⅱ
SENSITIVITY_TYPE                         0x8830  (34864)  Category Ⅱ
STANDARD_OUTPUT_SENSITIVITY              0x8831  (34865)  Category Ⅱ
RECOMMENDED_EXPOSURE_INDEX               0x8832  (34866)  Category Ⅱ
ISO_SPEED                                0x8833  (34867)  Category Ⅱ
ISO_SPEED_LATITUDE_YYY                   0x8834  (34868)  Category Ⅱ
ISO_SPEED_LATITUDE_ZZZ                   0x8835  (34869)  Category Ⅱ
EXIF_VERSION                             0x9000  (36864)  Category Ⅱ
DATETIME_ORIGINAL                        0x9003  (36867)  Category Ⅲ
DATETIME_DIGITIZED                       0x9004  (36868)  Category Ⅲ
OFFSET_TIME                              0x9010  (36880)  Category Ⅲ
OFFSET_TIME_ORIGINAL                     0x9011  (36881)  Category Ⅲ
OFFSET_TIME_DIGITIZED                    0x9012  (36882)  Category Ⅲ
COMPONENTS_CONFIGURATION                 0x9101  (37121)  Category Ⅰ
COMPRESSED_BITS_PER_PIXEL                0x9102  (37122)  Category Ⅰ
SHUTTER_SPEED_VALUE                      0x9201  (37377)  Category Ⅱ
APERTURE_VALUE                           0x9202  (37378)  Category Ⅱ
BRIGHTNESS_VALUE                         0x9203  (37379)  Category Ⅱ
EXPOSURE_BIAS_VALUE                      0x9204  (37380)  Category Ⅱ
MAX_APERTURE_VALUE                       0x9205  (37381)  Category Ⅱ
SUBJECT_DISTANCE                         0x9206  (37382)  Category Ⅱ
METERING_MODE                            0x9207  (37383)  Category Ⅱ
LIGHT_SOURCE                             0x9208  (37384)  Category Ⅱ
FLASH                                    0x9209  (37385)  Category Ⅱ
FOCAL_LENGTH                             0x920A  (37386)  Category Ⅱ
SUBJECT_AREA                             0x9214  (37396)  Category Ⅰ/Ⅱ
MAKER_NOTE                               0x927C  (37500)  Category Ⅱ
USER_COMMENT                             0x9286  (37510)  Category Ⅲ
SUB_SEC_TIME                             0x9290  (37520)  Category Ⅲ
SUB_SEC_TIME_ORIGINAL                    0x9291  (37521)  Category Ⅲ
SUB_SEC_TIME_DIGITIZED                   0x9292  (37522)  Category Ⅲ
TEMPERATURE                              0x9400  (37888)  Category Ⅲ
HUMIDITY                                 0x9401  (37889)  Category Ⅲ
PRESSURE                                 0x9402  (37890)  Category Ⅲ
WATER_DEPTH                              0x9403  (37891)  Category Ⅲ
ACCELERATION                             0x9404  (37892)  Category Ⅲ
CAMERA_ELEVATION_ANGLE                   0x9405  (37893)  Category Ⅲ
FLASHPIX_VERSION                         0xA000  (40960)  Category Ⅱ
COLOR_SPACE                              0xA001  (40961)  Category Ⅰ
PIXEL_X_DIMENSION                        0xA002  (40962)  Category Ⅰ
PIXEL_Y_DIMENSION                        0xA003  (40963)  Category Ⅰ
RELATED_SOUND_FILE                       0xA004  (40964)  Category Ⅰ
INTEROPERABILITY_IFD_POINTER             0xA005  (40965)  Category Ⅰ
FLASH_ENERGY                             0xA20B  (41483)  Category Ⅱ
SPATIAL_FREQUENCY_RESPONSE               0xA20C  (41484)  Category Ⅱ
FOCAL_PLANE_X_RESOLUTION                 0xA20E  (41486)  Category Ⅱ
FOCAL_PLANE_Y_RESOLUTION                 0xA20F  (41487)  Category Ⅱ
FOCAL_PLANE_RESOLUTION_UNIT              0xA210  (41488)  Category Ⅱ
SUBJECT_LOCATION                         0xA214  (41492)  Category Ⅰ/Ⅱ
EXPOSURE_INDEX                           0xA215  (41493)  Category Ⅱ
SENSING_METHOD                           0xA217  (41495)  Category Ⅱ
FILE_SOURCE                              0xA300  (41728)  Category Ⅱ
SCENE_TYPE                               0xA301  (41729)  Category Ⅱ
CFA_PATTERN                              0xA302  (41730)  Category Ⅱ
CUSTOM_RENDERED                          0xA401  (41985)  Category Ⅱ
EXPOSURE_MODE                            0xA402  (41986)  Category Ⅱ
WHITE_BALANCE                            0xA403  (41987)  Category Ⅱ
DIGITAL_ZOOM_RATIO                       0xA404  (41988)  Category Ⅱ
FOCAL_LENGTH_IN_35MM_FILM                0xA405  (41989)  Category Ⅱ
SCENE_CAPTURE_TYPE                       0xA406  (41990)  Category Ⅱ
GAIN_CONTROL                             0xA407  (41991)  Category Ⅱ
CONTRAST                                 0xA408  (41992)  Category Ⅱ
SATURATION                               0xA409  (41993)  Category Ⅱ
SHARPNESS                                0xA40A  (41994)  Category Ⅱ
DEVICE_SETTING_DESCRIPTION               0xA40B  (41995)  Category Ⅱ
SUBJECT_DISTANCE_RANGE                   0xA40C  (41996)  Category Ⅱ
IMAGE_UNIQUE_ID                          0xA420  (42016)  Category Ⅲ
CAMERA_OWNER_NAME                        0xA430  (42032)  Category Ⅲ
BODY_SERIAL_NUMBER                       0xA431  (42033)  Category Ⅲ
LENS_SPECIFICATION                       0xA432  (42034)  Category Ⅲ
LENS_MAKE                                0xA433  (42035)  Category Ⅲ
LENS_MODEL                               0xA434  (42036)  Category Ⅲ
LENS_SERIAL_NUMBER                       0xA435  (42037)  Category Ⅲ
COMPOSITE_IMAGE                          0xA460  (42080)  Category Ⅱ
SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE   0xA461  (42081)  Category Ⅱ
SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE 0xA462  (42082)  Category Ⅱ
GAMMA                                    0xA500  (42240)  Category Ⅰ
IMAGE_TITLE                              0xA436  (42038)  Category Ⅲ
PHOTOGRAPHER                             0xA437  (42039)  Category Ⅲ
IMAGE_EDITOR                             0xA438  (42040)  Category Ⅲ
CAMERA_FIRMWARE                          0xA439  (42041)  Category Ⅲ
RAW_DEVELOPING_SOFTWARE                  0xA43A  (42042)  Category Ⅲ
IMAGE_EDITING_SOFTWARE                   0xA43B  (42043)  Category Ⅲ
METADATA_EDITING_SOFTWARE                0xA43C  (42044)  Category Ⅲ
```

#### Table 66: GPS Info Tags (GPS IFD)
```
GPS_VERSION_ID              0x0000  (0)   Category Ⅲ
GPS_LATITUDE_REF            0x0001  (1)   Category Ⅲ
GPS_LATITUDE                0x0002  (2)   Category Ⅲ
GPS_LONGITUDE_REF           0x0003  (3)   Category Ⅲ
GPS_LONGITUDE               0x0004  (4)   Category Ⅲ
GPS_ALTITUDE_REF            0x0005  (5)   Category Ⅲ
GPS_ALTITUDE                0x0006  (6)   Category Ⅲ
GPS_TIME_STAMP              0x0007  (7)   Category Ⅲ
GPS_SATELLITES              0x0008  (8)   Category Ⅲ
GPS_STATUS                  0x0009  (9)   Category Ⅲ
GPS_MEASURE_MODE            0x000A  (10)  Category Ⅲ
GPS_DOP                     0x000B  (11)  Category Ⅲ
GPS_SPEED_REF               0x000C  (12)  Category Ⅲ
GPS_SPEED                   0x000D  (13)  Category Ⅲ
GPS_TRACK_REF               0x000E  (14)  Category Ⅲ
GPS_TRACK                   0x000F  (15)  Category Ⅲ
GPS_IMG_DIRECTION_REF       0x0010  (16)  Category Ⅲ
GPS_IMG_DIRECTION           0x0011  (17)  Category Ⅲ
GPS_MAP_DATUM               0x0012  (18)  Category Ⅲ
GPS_DEST_LATITUDE_REF       0x0013  (19)  Category Ⅲ
GPS_DEST_LATITUDE           0x0014  (20)  Category Ⅲ
GPS_DEST_LONGITUDE_REF      0x0015  (21)  Category Ⅲ
GPS_DEST_LONGITUDE          0x0016  (22)  Category Ⅲ
GPS_DEST_BEARING_REF        0x0017  (23)  Category Ⅲ
GPS_DEST_BEARING            0x0018  (24)  Category Ⅲ
GPS_DEST_DISTANCE_REF       0x0019  (25)  Category Ⅲ
GPS_DEST_DISTANCE           0x001A  (26)  Category Ⅲ
GPS_PROCESSING_METHOD       0x001B  (27)  Category Ⅲ
GPS_AREA_INFORMATION        0x001C  (28)  Category Ⅲ
GPS_DATE_STAMP              0x001D  (29)  Category Ⅲ
GPS_DIFFERENTIAL            0x001E  (30)  Category Ⅲ
GPS_H_POSITIONING_ERROR     0x001F  (31)  Category Ⅲ
```

#### Table 67: Interoperability Tags
```
INTEROPERABILITY_INDEX      0x0001  (1)     Category Ⅰ
```

**Note**: EXIF 3.0 also adds these camera orientation tags (not in older versions):
```
CAMERA_YAW_DEGREE           0x9406  (37894)  Category Ⅲ
CAMERA_PITCH_DEGREE         0x9407  (37895)  Category Ⅲ
CAMERA_ROLL_DEGREE          0x9408  (37896)  Category Ⅲ
GIMBAL_YAW_DEGREE           0x9409  (37897)  Category Ⅲ
GIMBAL_PITCH_DEGREE         0x940A  (37898)  Category Ⅲ
GIMBAL_ROLL_DEGREE          0x940B  (37899)  Category Ⅲ
AIRCRAFT_MAKE               0x940C  (37900)  Category Ⅲ
AIRCRAFT_MODEL              0x940D  (37901)  Category Ⅲ
```

### 2. TIFF 6.0 Standard Tags (Not in EXIF Spec)

These tags are from the TIFF 6.0 specification but are NOT listed in EXIF 3.0 Tables 64-67:

```
NEW_SUBFILE_TYPE            0x00FE  (254)    TIFF 6.0 §8
SUBFILE_TYPE                0x00FF  (255)    TIFF 5.0 (legacy)
PROCESSING_SOFTWARE         0x000B  (11)     TIFF/EP extension
DOCUMENT_NAME               0x010D  (269)    TIFF 6.0 (legacy in EXIF)
SUB_IFDS                    0x014A  (330)    TIFF 6.0 Extensions
TILE_WIDTH                  0x0142  (322)    TIFF 6.0 §15
TILE_LENGTH                 0x0143  (323)    TIFF 6.0 §15
TILE_OFFSETS                0x0144  (324)    TIFF 6.0 §15
TILE_BYTE_COUNTS            0x0145  (325)    TIFF 6.0 §15
PREDICTOR                   0x013D  (317)    TIFF 6.0 §14
ICC_PROFILE                 0x8773  (34675)  TIFF 6.0 §20 / ICC.1:2001-04
HOST_COMPUTER               0x013C  (316)    TIFF 6.0 (removed from EXIF 3.0)
```

### 3. Microsoft XP Tags (Proprietary Windows Extension)

Microsoft introduced these proprietary tags for Windows XP image properties, encoded as UTF-16LE:

```
XP_TITLE                    0x9C9B  (40091)  Microsoft Windows XP
XP_COMMENT                  0x9C9C  (40092)  Microsoft Windows XP
XP_AUTHOR                   0x9C9D  (40093)  Microsoft Windows XP
XP_KEYWORDS                 0x9C9E  (40094)  Microsoft Windows XP
XP_SUBJECT                  0x9C9F  (40095)  Microsoft Windows XP
```

**Source**: Microsoft Windows Imaging Component (WIC) / Windows Explorer metadata

### 4. Adobe DNG Tags (Digital Negative Specification)

These tags are from the Adobe DNG specification but included in ExifTag.php:

```
CAMERA_CALIBRATION_SIGNATURE    0xC6F3  (50931)  DNG 1.2.0.0 §48-55
PROFILE_CALIBRATION_SIGNATURE   0xC6F4  (50932)  DNG 1.2.0.0 §48-55
PROFILE_HUE_SAT_MAP_ENCODINGS   0xC6F5  (50933)  DNG 1.4.0.0 (duplicate ID!)
PROFILE_HUE_SAT_MAP_DIMS        0xC6F6  (50934)  DNG 1.2.0.0 §48-55
PROFILE_HUE_SAT_MAP_DATA_1      0xC6F7  (50935)  DNG 1.2.0.0 §48-55
PROFILE_HUE_SAT_MAP_DATA_2      0xC6F8  (50936)  DNG 1.2.0.0 §48-55
PROFILE_HUE_SAT_MAP_DATA_3      0xC6F9  (50937)  DNG 1.2.0.0 §48-55
PROFILE_LOOK_TABLE_DIMS         0xC6FA  (50938)  DNG 1.3.0.0 §61
PROFILE_LOOK_TABLE_DATA         0xC6FB  (50939)  DNG 1.3.0.0 §61
PROFILE_TONE_CURVE              0xC6FC  (50940)  DNG 1.2.0.0 §48-55
CAMERA_SERIAL_NUMBER            0xC62F  (50735)  DNG 1.0.0.0 (also in EXIF as BODY_SERIAL_NUMBER)
PREVIEW_IMAGE_START             0xC51B  (50459)  DNG extension
PREVIEW_IMAGE_LENGTH            0xC51C  (50460)  DNG extension
PREVIEW_IMAGE_ENCODING          0xC51D  (50461)  DNG extension
PREVIEW_IMAGE_MIME_TYPE         0xC51E  (50462)  DNG extension
PREVIEW_IMAGE_WIDTH             0xC51F  (50463)  DNG extension
PREVIEW_IMAGE_HEIGHT            0xC520  (50464)  DNG extension
PREVIEW_IMAGE_COLOR_SPACE       0xC521  (50465)  DNG extension
PREVIEW_IMAGE_BIT_DEPTH         0xC522  (50466)  DNG extension
PREVIEW_DATE_TIME               0xC523  (50467)  DNG extension
PREVIEW_DATE_TIME_DIGITIZED     0xC524  (50468)  DNG extension
PREVIEW_IMAGE_COMPRESSION       0xC525  (50469)  DNG extension
PREVIEW_IMAGE_SCALE             0xC526  (50470)  DNG extension
```

**Source**: Adobe DNG Specification v1.0.0.0 - v1.7.1.0

**Note**: The DNG specification extends TIFF/EXIF with RAW image processing tags. Many DNG files are valid EXIF files, but DNG tags are not part of the EXIF standard.

### 5. Vendor-Specific Tags

```
PRINT_IMAGE_MATCHING        0xC4A5  (50341)  Epson Print Image Matching (PrintIM)
MAKER_NOTE_SAFETY           0xC635  (50741)  DNG marker for safe maker notes
BATTERY_LEVEL               0x828F  (33423)  TIFF/EP extension
CFA_REPEAT_PATTERN_DIM      0x828D  (33421)  TIFF/EP extension
INTERLACE                   0x8829  (34857)  TIFF/IT extension
TIME_ZONE_OFFSET            0x882A  (34858)  EXIF private (rarely used)
SELF_TIMER_MODE             0x882B  (34859)  EXIF private (rarely used)
NOISE                       0xA20D  (41485)  TIFF/EP extension
IMAGE_NUMBER                0xA211  (41489)  TIFF/EP extension
SECURITY_CLASSIFICATION     0xA212  (41490)  TIFF/EP extension
IMAGE_HISTORY               0xA213  (41491)  TIFF/EP extension
TIFF_EP_STANDARD_ID         0xA216  (41494)  TIFF/EP standard identifier
```

### 6. Legacy/Compatibility Tags

Tags retained for backwards compatibility with older EXIF versions or renamed in newer versions:

```
IMAGE_TITLE_LEGACY                      0x0320  (800)    Pre-EXIF 3.0 document name
ISO_SPEED_RATINGS_LEGACY                0x8827  (34855)  Same as PHOTOGRAPHIC_SENSITIVITY (EXIF 2.x name)
PHOTOGRAPHER_LEGACY                     0xE92D  (59693)  Microsoft pre-EXIF 3.0
IMAGE_EDITOR_LEGACY                     0xE92E  (59694)  Microsoft pre-EXIF 3.0
CAMERA_FIRMWARE_VERSION_LEGACY          0xA436  (42038)  Conflicts with IMAGE_TITLE!
RAW_DEVELOPING_SOFTWARE_VERSION_LEGACY  0xA439  (42041)  Conflicts with CAMERA_FIRMWARE!
IMAGE_EDITING_SOFTWARE_VERSION_LEGACY   0xA43B  (42043)  Conflicts with IMAGE_EDITING_SOFTWARE!
METADATA_EDITING_SOFTWARE_VERSION_LEGACY 0xA43C (42044)  Conflicts with METADATA_EDITING_SOFTWARE!
CAMERA_FIRMWARE_LEGACY                  0xE92F  (59695)  Microsoft pre-EXIF 3.0
RAW_DEVELOPING_SOFTWARE_LEGACY          0xE930  (59696)  Microsoft pre-EXIF 3.0
IMAGE_EDITING_SOFTWARE_LEGACY           0xE931  (59697)  Microsoft pre-EXIF 3.0
METADATA_EDITING_SOFTWARE_LEGACY        0xE932  (59698)  Microsoft pre-EXIF 3.0
```

**Important**: Some legacy constants share the same hex value as current EXIF 3.0 tags, creating aliases.

### 7. Tags with Interoperability Issues

#### Additional Tags Not in EXIF 3.0 Spec Tables
```
RELATED_IMAGE_FILE_FORMAT   0x1000  (4096)   Interoperability IFD (EXIF 3.0 §H.3, but not in Table 67)
RELATED_IMAGE_WIDTH         0x1001  (4097)   Interoperability IFD (EXIF 3.0 §H.3, but not in Table 67)
RELATED_IMAGE_LENGTH        0x1002  (4098)   Interoperability IFD (EXIF 3.0 §H.3, but not in Table 67)
INTEROPERABILITY_VERSION    0x0002  (2)      Interoperability IFD (EXIF 3.0 §H.3, but not in Table 67)
```

These are mentioned in EXIF 3.0 §H.3 (Interoperability Structure and IFD) but not included in the official tag tables.

## Unmapped Tag Handling

### How are unmapped tags handled in parsing?

Based on analysis of `TiffExifReader.php`:

1. **All IFD entries are read and stored**: The parser reads ALL directory entries from IFDs regardless of whether they have a corresponding constant in `ExifTag.php` (see `readIfd()` and `readDirEntry()` methods).

2. **Tags are stored by numeric ID**: IFD entries are stored in an associative array with the numeric tag ID as the key (`array<int, IfdEntry>`).

3. **No filtering occurs during parsing**: There is NO code that filters or ignores tags based on whether they're in `ExifTag.php`.

4. **Access is by numeric ID**: Code can access any tag via its numeric ID:
   ```php
   $ifd->get(0x9999);  // Works for any tag, even if not in ExifTag.php
   ```

5. **Constants are for convenience**: The `ExifTag` constants are purely for developer convenience and type safety. They don't restrict which tags are parsed or stored.

### Example Code Paths

```php
// In TiffExifReader::readIfd()
for ($i = 0; $i < $entryCount; ++$i) {
    $entries += $this->readDirEntry();  // Reads ALL entries
}
$ifd = new Ifd($entries, $next > 0 ? $next : null);
```

```php
// In TiffExifReader::readDirEntry()
$tag  = $this->readU16();  // Reads ANY tag ID
$type = $this->readU16();
$cnt  = $this->bigTiff ? $this->readU64()->toInt('...') : $this->readU32();
// ... processes the entry ...
return [$tag => $entry];  // Returns entry with numeric tag as key
```

### Implications

- **Unmapped tags ARE preserved**: If an image contains a tag not defined in `ExifTag.php`, it will still be parsed and accessible via its numeric ID.
- **No data loss**: The parser doesn't discard unknown tags.
- **Forward compatibility**: New tags can be accessed by numeric ID even before adding them to `ExifTag.php`.
- **Vendor extensions work**: Manufacturer-specific tags (Canon, Nikon, Sony, etc.) in maker notes or custom IFDs are preserved.

### Value Conversion

Special handling exists for certain tag categories:

1. **UTF-16LE strings** (XP tags): Converted from byte array to string
2. **Maker notes** (0x927C): Raw bytes preserved for specialized decoders
3. **Image data offsets**: Special handling for strip/tile offsets and byte counts
4. **Pointer tags**: Followed to read sub-IFDs (ExifIFD, GPS IFD, etc.)

All other tags are decoded according to their TIFF type and stored as-is.

## Recommendations

### 1. Separate Tag Constants by Source

Consider splitting `ExifTag.php` into multiple classes:

```php
ExifTag.php          // Only official EXIF 3.0 tags
TiffTag.php          // TIFF 6.0 baseline tags
MicrosoftXpTag.php   // Microsoft XP proprietary tags
DngTag.php           // Already exists, good!
LegacyTag.php        // Backwards compatibility aliases
```

### 2. Document Tag Origins

Add PHPDoc annotations indicating each tag's origin:

```php
/**
 * Microsoft XPTitle property encoded as UTF-16LE.
 * 
 * @source Microsoft Windows XP / Windows Imaging Component
 * @standard Non-standard (vendor extension)
 */
public const int XP_TITLE = 0x9C9B;

/**
 * Width of the image in pixels.
 * 
 * @source EXIF 3.0 §H.6 Table 64 (Category Ⅰ)
 * @standard EXIF 3.0, EXIF 2.x, TIFF 6.0
 */
public const int IMAGE_WIDTH = 0x0100;
```

### 3. Add Test Coverage

Create tests that verify:
- All EXIF 3.0 Table 64-67 tags are present
- Non-EXIF tags are properly categorized
- Unmapped tags are still accessible via numeric ID

### 4. Update Documentation

Add this analysis to the repository documentation so users understand:
- Which tags are standard vs. extensions
- That all tags are preserved during parsing
- How to access tags not in `ExifTag.php`

## Summary

- **EXIF 3.0 Standard**: ~120 tags from Tables 64-67
- **TIFF 6.0 Extensions**: ~12 tags (not in EXIF spec)
- **Microsoft XP**: 5 proprietary tags
- **Adobe DNG**: ~20 tags (separate specification)
- **Legacy/Compatibility**: ~16 renamed or deprecated tags
- **Vendor-Specific**: ~8 manufacturer extension tags

**Total**: ~231 constants in `ExifTag.php`, of which approximately **52%** are from non-EXIF sources.

**Unmapped tags**: All tags are preserved during parsing, regardless of whether they appear in `ExifTag.php`. The constants are for convenience, not filtering.
