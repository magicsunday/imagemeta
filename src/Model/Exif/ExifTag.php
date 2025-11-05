<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Exif;

/**
 * Centralised list of EXIF tag identifiers used throughout the library.
 *
 * EXIF 3.0 §4.6 catalogues the tag registry for the primary, Exif, GPS and
 * interoperability IFDs referenced by this enumeration.
 */
final readonly class ExifTag
{
    /**
     * TIFF 6.0 subfile type bitfield describing the purpose of the image data.
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1.
     */
    public const int NEW_SUBFILE_TYPE = 0x00FE;

    /**
     * Legacy TIFF 5.0 subfile type value describing the image purpose.
     * EXIF 2.32 §4.6.2 Table 1 retains the identifier for backward compatibility.
     */
    public const int SUBFILE_TYPE = 0x00FF;

    /**
     * EXIF 3.0 tag recording the software responsible for final image processing.
     * EXIF 3.0 §4.6.2 Table 1; mapped from EXIF 2.32 §4.6.2 Table 1 guidance.
     */
    public const int PROCESSING_SOFTWARE = 0x000B;

    /**
     * Width of the image in pixels.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int IMAGE_WIDTH = 0x0100;

    /**
     * Height of the image in pixels.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int IMAGE_HEIGHT = 0x0101;

    /**
     * Number of bits for each colour component.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int BITS_PER_SAMPLE = 0x0102;

    /**
     * Compression scheme applied to the image data.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int COMPRESSION = 0x0103;

    /**
     * Colour space interpretation of the pixel data.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int PHOTOMETRIC_INTERPRETATION = 0x0106;

    /**
     * Legacy EXIF ≤ 2.x tag storing the document name within IFD0.
     * EXIF 2.32 §4.6.2 Table 1; superseded by the EXIF 3.0 ImageTitle tag in Table 1.
     *
     * Retained for backwards compatibility alongside the EXIF 3.0 IMAGE_TITLE tag.
     * EXIF 2.32 §4.6.2 Table 1 (legacy) / EXIF 3.0 §4.6.2 Table 1 (ImageTitle).
     */
    public const int DOCUMENT_NAME = 0x010D;

    /**
     * Free-form text describing the image contents.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int IMAGE_DESCRIPTION = 0x010E;

    /**
     * Offset pointer to additional linked IFDs.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int SUB_IFDS = 0x014A;

    /**
     * Modern EXIF 3.0 title string for the image.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int IMAGE_TITLE = 0xA436;

    /**
     * Microsoft XPTitle property encoded as UTF-16LE.
     * EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2.
     */
    public const int XP_TITLE = 0x9C9B;

    /**
     * Microsoft XPComment property encoded as UTF-16LE.
     * EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2.
     */
    public const int XP_COMMENT = 0x9C9C;

    /**
     * Microsoft XPAuthor property encoded as UTF-16LE.
     * EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2.
     */
    public const int XP_AUTHOR = 0x9C9D;

    /**
     * Microsoft XPKeywords property encoded as UTF-16LE.
     * EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2.
     */
    public const int XP_KEYWORDS = 0x9C9E;

    /**
     * Microsoft XPSubject property encoded as UTF-16LE.
     * EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2.
     */
    public const int XP_SUBJECT = 0x9C9F;

    /**
     * Legacy EXIF 2.x tag that stored the document name within IFD0.
     *
     * Retained for backwards compatibility with images that have not been
     * updated to the EXIF 3.0 IMAGE_TITLE identifier.
     * EXIF 2.32 §4.6.2 Table 1 (legacy) / EXIF 3.0 §4.6.2 Table 1 (ImageTitle).
     */
    public const int IMAGE_TITLE_LEGACY = 0x0320;

    /**
     * Manufacturer name of the recording equipment.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int MAKE = 0x010F;

    /**
     * Model name or identifier of the recording equipment.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int MODEL = 0x0110;

    /**
     * Orientation of the image as displayed.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int ORIENTATION = 0x0112;

    /**
     * Offsets to image strips within the file.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int STRIP_OFFSETS = 0x0111;

    /**
     * Number of colour components per pixel.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int SAMPLES_PER_PIXEL = 0x0115;

    /**
     * Number of rows stored in each strip.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int ROWS_PER_STRIP = 0x0116;

    /**
     * Total bytes used by each strip.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int STRIP_BYTE_COUNTS = 0x0117;

    /**
     * Width of each image tile in pixels.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int TILE_WIDTH = 0x0142;

    /**
     * Height of each image tile in pixels.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int TILE_LENGTH = 0x0143;

    /**
     * Offsets to tiled image data blocks.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int TILE_OFFSETS = 0x0144;

    /**
     * Total bytes used by each tile.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int TILE_BYTE_COUNTS = 0x0145;

    /**
     * Horizontal pixel density expressed as a rational value.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int X_RESOLUTION = 0x011A;

    /**
     * Vertical pixel density expressed as a rational value.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int Y_RESOLUTION = 0x011B;

    /**
     * Arrangement of colour components across pixel planes.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int PLANAR_CONFIGURATION = 0x011C;

    /**
     * Unit used for X and Y resolution values.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int RESOLUTION_UNIT = 0x0128;

    /**
     * Transfer function curve for colour components.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int TRANSFER_FUNCTION = 0x012D;

    /**
     * Software used to create the image.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int SOFTWARE = 0x0131;

    /**
     * Legacy EXIF 2.x identifier retained for backwards compatibility.
     *
     * EXIF 3.0 renames the tag to ModifyDate, exposed via the MODIFY_DATE alias.
     * EXIF 2.32 §4.6.2 Table 2 (DateTime) / EXIF 3.0 §4.6.2 Table 2 (ModifyDate).
     */
    public const int DATETIME = 0x0132;

    /**
     * Preferred alias that matches the EXIF 3.0 ModifyDate tag name.
     * EXIF 3.0 §4.6.2 Table 2; aligns with EXIF 2.32 §4.6.2 Table 2 DateTime guidance.
     */
    public const int MODIFY_DATE = 0x0132;

    /**
     * Artist or photographer responsible for the image.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int ARTIST = 0x013B;

    /**
     * Legacy EXIF 2.x identifier retained for backwards compatibility.
     *
     * Removed from the EXIF 3.0 registry but still exposed for older files.
     * EXIF 2.32 §4.6.2 Table 2; absent from EXIF 3.0 Table 2 but preserved as a legacy alias.
     */
    public const int HOST_COMPUTER = 0x013C;

    /**
     * Name of the credited photographer.
     *
     * EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2
     */
    public const int PHOTOGRAPHER = 0xA437;

    /**
     * Legacy Microsoft EXIF tag that exposed the photographer credit prior to
     * EXIF 2.32 §4.6.2 Table 2; replaced by EXIF 3.0 §4.6.2 Table 2 Photographer.
     */
    public const int PHOTOGRAPHER_LEGACY = 0xE92D;

    /**
     * Name of the credited image editor.
     *
     * EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2
     */
    public const int IMAGE_EDITOR = 0xA438;

    /**
     * Legacy Microsoft EXIF tag that exposed the image editor credit prior to
     * EXIF 2.32 §4.6.2 Table 2; replaced by EXIF 3.0 §4.6.2 Table 2 ImageEditor.
     */
    public const int IMAGE_EDITOR_LEGACY = 0xE92E;

    /**
     * Chromaticity of the image white point.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int WHITE_POINT = 0x013E;

    /**
     * Chromaticity coordinates of the primary colours.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int PRIMARY_CHROMATICITIES = 0x013F;

    /**
     * Offset to the JPEG-encoded preview image.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int JPEG_INTERCHANGE_FORMAT = 0x0201;

    /**
     * Length of the JPEG-encoded preview image in bytes.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int JPEG_INTERCHANGE_FORMAT_LENGTH = 0x0202;

    /**
     * Byte offset to the embedded preview image data.
     *
     * EXIF 3.0 §4.6.12 Tables 29–32
     */
    public const int PREVIEW_IMAGE_START = 0xC51B;

    /**
     * Length of the embedded preview image data in bytes.
     *
     * EXIF 3.0 §4.6.12 Tables 29–32
     */
    public const int PREVIEW_IMAGE_LENGTH = 0xC51C;

    /**
     * Encoding scheme for the embedded preview image.
     *
     * EXIF 3.0 §4.6.12 Tables 29–32
     */
    public const int PREVIEW_IMAGE_ENCODING = 0xC51D;

    /**
     * MIME type describing the embedded preview image format.
     *
     * EXIF 3.0 §4.6.12 Tables 29–32
     */
    public const int PREVIEW_IMAGE_MIME_TYPE = 0xC51E;

    /**
     * Width of the embedded preview image in pixels.
     *
     * EXIF 3.0 §4.6.12 Tables 29–32
     */
    public const int PREVIEW_IMAGE_WIDTH = 0xC51F;

    /**
     * Height of the embedded preview image in pixels.
     *
     * EXIF 3.0 §4.6.12 Tables 29–32
     */
    public const int PREVIEW_IMAGE_HEIGHT = 0xC520;

    /**
     * Colour space of the embedded preview image.
     *
     * EXIF 3.0 §4.6.12 Tables 29–32
     */
    public const int PREVIEW_IMAGE_COLOR_SPACE = 0xC521;

    /**
     * Bit depth of the embedded preview image.
     *
     * EXIF 3.0 §4.6.12 Tables 29–32
     */
    public const int PREVIEW_IMAGE_BIT_DEPTH = 0xC522;

    /**
     * Date and time when the preview image was generated.
     *
     * EXIF 3.0 §4.6.12 Tables 29–32
     */
    public const int PREVIEW_DATE_TIME = 0xC523;

    /**
     * Date and time when the preview image was digitised.
     *
     * EXIF 3.0 §4.6.12 Tables 29–32
     */
    public const int PREVIEW_DATE_TIME_DIGITIZED = 0xC524;

    /**
     * Compression method used for the preview image.
     *
     * EXIF 3.0 §4.6.12 Tables 29–32
     */
    public const int PREVIEW_IMAGE_COMPRESSION = 0xC525;

    /**
     * Scaling factor applied to derive the preview image.
     *
     * EXIF 3.0 §4.6.12 Tables 29–32
     */
    public const int PREVIEW_IMAGE_SCALE = 0xC526;

    /**
     * YCbCr transformation coefficients.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int YCBCR_COEFFICIENTS = 0x0211;

    /**
     * Sub-sampling factors for the YCbCr components.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int YCBCR_SUB_SAMPLING = 0x0212;

    /**
     * Reference location for YCbCr samples.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int YCBCR_POSITIONING = 0x0213;

    /**
     * Reference black and white levels for each colour channel.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int REFERENCE_BLACK_WHITE = 0x0214;

    /**
     * Copyright notice associated with the image.
     *
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1
     */
    public const int COPYRIGHT = 0x8298;

    /**
     * Offset to the Exif-specific IFD block.
     *
     * EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2
     */
    public const int EXIF_IFD_POINTER = 0x8769;

    /**
     * Offset to the GPS IFD block.
     *
     * EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2
     */
    public const int GPS_IFD_POINTER = 0x8825;

    /**
     * Offset to the interoperability IFD block.
     *
     * EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2
     */
    public const int INTEROPERABILITY_IFD_POINTER = 0xA005;

    /**
     * Repetition pattern for the colour filter array.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int CFA_REPEAT_PATTERN_DIM = 0x828D;

    /**
     * Charge level remaining in the battery.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int BATTERY_LEVEL = 0x828F;

    /**
     * Epson Print Image Matching (PrintIM) parameter block.
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26.
     */
    public const int PRINT_IMAGE_MATCHING = 0xC4A5;

    /**
     * Flag indicating whether maker notes are considered safe to parse.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int MAKER_NOTE_SAFETY = 0xC635;

    /**
     * Exposure duration expressed in seconds.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int EXPOSURE_TIME = 0x829A;

    /**
     * F-number of the lens at the time of capture.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int F_NUMBER = 0x829D;

    /**
     * Program mode setting for exposure control.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int EXPOSURE_PROGRAM = 0x8822;

    /**
     * Description of the spectral sensitivity of the camera.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int SPECTRAL_SENSITIVITY = 0x8824;

    /**
     * Legacy EXIF 2.x identifier retained for backwards compatibility.
     *
     * EXIF 3.0 renames the tag to PhotographicSensitivity, exposed via the
     * PHOTOGRAPHIC_SENSITIVITY alias.
     * EXIF 2.32 §4.6.3 Table 13 (ISOSpeedRatings) / EXIF 3.0 §4.6.3 Table 13 (PhotographicSensitivity).
     */
    public const int ISO_SPEED_RATINGS_LEGACY = 0x8827;

    /**
     * Current photographic sensitivity expressed as ISO speed.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int PHOTOGRAPHIC_SENSITIVITY = 0x8827;

    /**
     * Opto-electronic conversion function parameters.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int OECF = 0x8828;

    /**
     * Indicator describing interlaced scan type.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int INTERLACE = 0x8829;

    /**
     * Time zone offsets for recorded timestamps.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int TIME_ZONE_OFFSET = 0x882A;

    /**
     * Self-timer delay used for the exposure.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int SELF_TIMER_MODE = 0x882B;

    /**
     * Type of sensitivity value recorded in ISO tags.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int SENSITIVITY_TYPE = 0x8830;

    /**
     * Standard output sensitivity of the camera.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int STANDARD_OUTPUT_SENSITIVITY = 0x8831;

    /**
     * Recommended exposure index for the scene.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int RECOMMENDED_EXPOSURE_INDEX = 0x8832;

    /**
     * Calculated ISO speed value.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int ISO_SPEED = 0x8833;

    /**
     * Latitude component of the ISO speed range (YYY value).
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int ISO_SPEED_LATITUDE_YYY = 0x8834;

    /**
     * Latitude component of the ISO speed range (ZZZ value).
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int ISO_SPEED_LATITUDE_ZZZ = 0x8835;

    /**
     * EXIF version information recorded in ASCII.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int EXIF_VERSION = 0x9000;

    /**
     * Original capture date and time.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int DATETIME_ORIGINAL = 0x9003;

    /**
     * Digitisation date and time of the original capture.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int DATETIME_DIGITIZED = 0x9004;

    /**
     * Time-zone offset applied to ModifyDate.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int OFFSET_TIME = 0x9010;

    /**
     * Time-zone offset applied to DateTimeOriginal.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int OFFSET_TIME_ORIGINAL = 0x9011;

    /**
     * Time-zone offset applied to DateTimeDigitized.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int OFFSET_TIME_DIGITIZED = 0x9012;

    /**
     * Arrangement of colour components in a compressed stream.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int COMPONENTS_CONFIGURATION = 0x9101;

    /**
     * Compression rate expressed as bits per pixel.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int COMPRESSED_BITS_PER_PIXEL = 0x9102;

    /**
     * APEX shutter speed value.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int SHUTTER_SPEED_VALUE = 0x9201;

    /**
     * APEX aperture value.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int APERTURE_VALUE = 0x9202;

    /**
     * APEX brightness value of the scene.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int BRIGHTNESS_VALUE = 0x9203;

    /**
     * APEX exposure bias applied to the capture.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int EXPOSURE_BIAS_VALUE = 0x9204;

    /**
     * Smallest available lens aperture value.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int MAX_APERTURE_VALUE = 0x9205;

    /**
     * Subject distance from the camera in metres.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int SUBJECT_DISTANCE = 0x9206;

    /**
     * Metering mode used to determine exposure.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int METERING_MODE = 0x9207;

    /**
     * Type of light source illuminating the scene.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int LIGHT_SOURCE = 0x9208;

    /**
     * Status and return light information for the flash.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int FLASH = 0x9209;

    /**
     * Actual lens focal length in millimetres.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int FOCAL_LENGTH = 0x920A;

    /**
     * Area of interest covered by the exposure metering.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int SUBJECT_AREA = 0x9214;

    /**
     * Maker-specific notes recorded by the camera.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int MAKER_NOTE = 0x927C;

    /**
     * Free-form comments entered by the camera user.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int USER_COMMENT = 0x9286;

    /**
     * Fractional seconds for the ModifyDate timestamp.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int SUB_SEC_TIME = 0x9290;

    /**
     * Fractional seconds for the DateTimeOriginal timestamp.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int SUB_SEC_TIME_ORIGINAL = 0x9291;

    /**
     * Fractional seconds for the DateTimeDigitized timestamp.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int SUB_SEC_TIME_DIGITIZED = 0x9292;

    /**
     * FlashPix format version used for the metadata.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int FLASHPIX_VERSION = 0xA000;

    /**
     * Colour space handling for the image data.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int COLOR_SPACE = 0xA001;

    /**
     * Valid pixel width of the primary image.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int PIXEL_X_DIMENSION = 0xA002;

    /**
     * Valid pixel height of the primary image.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int PIXEL_Y_DIMENSION = 0xA003;

    /**
     * Reference to an audio clip related to the image.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int RELATED_SOUND_FILE = 0xA004;

    /**
     * Source of the image data, such as digital camera or scanner.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int FILE_SOURCE = 0xA300;

    /**
     * Scene type indicator for the image source.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int SCENE_TYPE = 0xA301;

    /**
     * Rendering mode applied during image processing.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int CUSTOM_RENDERED = 0xA401;

    /**
     * Exposure mode setting used by the camera.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int EXPOSURE_MODE = 0xA402;

    /**
     * White balance setting applied during capture.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int WHITE_BALANCE = 0xA403;

    /**
     * Ratio between the focal length and a reference value.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int DIGITAL_ZOOM_RATIO = 0xA404;

    /**
     * Equivalent focal length expressed for 35mm film.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int FOCAL_LENGTH_IN_35MM_FILM = 0xA405;

    /**
     * Scene capture type classification.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int SCENE_CAPTURE_TYPE = 0xA406;

    /**
     * Overall image gain control setting.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int GAIN_CONTROL = 0xA407;

    /**
     * Contrast setting applied to the image.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int CONTRAST = 0xA408;

    /**
     * Saturation setting applied to the image.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int SATURATION = 0xA409;

    /**
     * Sharpness setting applied to the image.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int SHARPNESS = 0xA40A;

    /**
     * Distance range classification for the subject.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int SUBJECT_DISTANCE_RANGE = 0xA40C;

    /**
     * Globally unique identifier assigned to the image.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int IMAGE_UNIQUE_ID = 0xA420;

    /**
     * Name of the camera owner.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int CAMERA_OWNER_NAME = 0xA430;

    /**
     * Serial number assigned to the camera body.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int BODY_SERIAL_NUMBER = 0xA431;

    /**
     * Serial number assigned to the camera unit.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int CAMERA_SERIAL_NUMBER = 0xC62F;

    /**
     * Detailed lens specification range values.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int LENS_SPECIFICATION = 0xA432;

    /**
     * Lens manufacturer name.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int LENS_MAKE = 0xA433;

    /**
     * Lens model designation.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int LENS_MODEL = 0xA434;

    /**
     * Lens serial number value.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int LENS_SERIAL_NUMBER = 0xA435;

    /**
     * Legacy EXIF 2.x tag that stored the dedicated camera firmware version.
     *
     * The identifier was reassigned to IMAGE_TITLE in EXIF 3.0 and therefore
     * EXIF 2.32 §4.6.3 Table 18 (FirmwareVersion) / EXIF 3.0 §4.6.2 Table 1 (ImageTitle).
     * remains available only for backwards compatibility lookups.
     */
    public const int CAMERA_FIRMWARE_VERSION_LEGACY = 0xA436;

    /**
     * Firmware name or version reported by the camera.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int CAMERA_FIRMWARE = 0xA439;

    /**
     * Legacy EXIF 2.x tag that stored the raw developing software version.
     *
     * EXIF 3.0 reassigned this identifier to CAMERA_FIRMWARE.
     * EXIF 2.32 §4.6.3 Table 18 (RawDataUniqueID) / EXIF 3.0 §4.6.3 Table 18 (CameraFirmware).
     */
    public const int RAW_DEVELOPING_SOFTWARE_VERSION_LEGACY = 0xA439;

    /**
     * Raw developing software name or version.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int RAW_DEVELOPING_SOFTWARE = 0xA43A;

    /**
     * Legacy EXIF 2.x tag that stored the image editing software version.
     *
     * EXIF 3.0 reassigned this identifier to IMAGE_EDITING_SOFTWARE.
     * EXIF 2.32 §4.6.3 Table 18 (Software).
     */
    public const int IMAGE_EDITING_SOFTWARE_VERSION_LEGACY = 0xA43B;

    /**
     * Image editing software name or version.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int IMAGE_EDITING_SOFTWARE = 0xA43B;

    /**
     * Legacy EXIF 2.x tag that stored the metadata editing software version.
     *
     * EXIF 3.0 reassigned this identifier to METADATA_EDITING_SOFTWARE.
     * EXIF 2.32 §4.6.3 Table 18 (MetadataEditing).
     */
    public const int METADATA_EDITING_SOFTWARE_VERSION_LEGACY = 0xA43C;

    /**
     * Metadata editing software name or version.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int METADATA_EDITING_SOFTWARE = 0xA43C;

    /**
     * Classification flag indicating a composite image.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int COMPOSITE_IMAGE = 0xA460;

    /**
     * Number of source images merged into the composite.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE = 0xA461;

    /**
     * Exposure times of the source images used in the composite.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE = 0xA462;

    /**
     * Applied gamma correction value.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int GAMMA = 0xA500;

    /**
     * Strobe energy used for the capture.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int FLASH_ENERGY = 0xA20B;

    /**
     * Spatial frequency response information.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int SPATIAL_FREQUENCY_RESPONSE = 0xA20C;

    /**
     * Noise measurement parameters.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int NOISE = 0xA20D;

    /**
     * Horizontal focal plane resolution.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int FOCAL_PLANE_X_RESOLUTION = 0xA20E;

    /**
     * Vertical focal plane resolution.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int FOCAL_PLANE_Y_RESOLUTION = 0xA20F;

    /**
     * Unit for the focal plane resolution values.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int FOCAL_PLANE_RESOLUTION_UNIT = 0xA210;

    /**
     * Sequential number assigned by the camera.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int IMAGE_NUMBER = 0xA211;

    /**
     * Security classification of the image.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int SECURITY_CLASSIFICATION = 0xA212;

    /**
     * Processing steps applied to the image.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int IMAGE_HISTORY = 0xA213;

    /**
     * Location of the subject within the frame.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int SUBJECT_LOCATION = 0xA214;

    /**
     * Exposure index recommended by the camera.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int EXPOSURE_INDEX = 0xA215;

    /**
     * Identifier for the TIFF/EP standard version used.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int TIFF_EP_STANDARD_ID = 0xA216;

    /**
     * Sensor sensing method employed by the camera.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int SENSING_METHOD = 0xA217;

    /**
     * Colour filter array pattern description.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int CFA_PATTERN = 0xA302;

    /**
     * Description of the device settings used for capture.
     *
     * EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26
     */
    public const int DEVICE_SETTING_DESCRIPTION = 0xA40B;

    /**
     * DNG specification version encoded as four bytes.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int DNG_VERSION = 0xC612;

    /**
     * DNG backwards compatibility version encoded as four bytes.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int DNG_BACKWARD_VERSION = 0xC613;

    /**
     * Unique camera model identifier for DNG raw files.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int UNIQUE_CAMERA_MODEL = 0xC614;

    /**
     * Localized camera model name encoded as UTF-8.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int LOCALIZED_CAMERA_MODEL = 0xC615;

    /**
     * Color filter array plane color specification.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int CFA_PLANE_COLOR = 0xC616;

    /**
     * Color filter array spatial layout pattern.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int CFA_LAYOUT = 0xC617;

    /**
     * Linearization lookup table for raw sensor values.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int LINEARIZATION_TABLE = 0xC618;

    /**
     * Dimensions of the repeating black level pattern.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int BLACK_LEVEL_REPEAT_DIM = 0xC619;

    /**
     * Black level values for each color plane.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int BLACK_LEVEL = 0xC61A;

    /**
     * Horizontal black level delta values.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int BLACK_LEVEL_DELTA_H = 0xC61B;

    /**
     * Vertical black level delta values.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int BLACK_LEVEL_DELTA_V = 0xC61C;

    /**
     * White level values for each color plane.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int WHITE_LEVEL = 0xC61D;

    /**
     * Default scale factors for the raw image.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int DEFAULT_SCALE = 0xC61E;

    /**
     * Default crop origin coordinates.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int DEFAULT_CROP_ORIGIN = 0xC61F;

    /**
     * Default crop size dimensions.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int DEFAULT_CROP_SIZE = 0xC620;

    /**
     * Primary color matrix transformation from camera RGB to XYZ.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int COLOR_MATRIX_1 = 0xC621;

    /**
     * Secondary color matrix for alternative illuminant.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int COLOR_MATRIX_2 = 0xC622;

    /**
     * Primary camera calibration matrix.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int CAMERA_CALIBRATION_1 = 0xC623;

    /**
     * Secondary camera calibration matrix.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int CAMERA_CALIBRATION_2 = 0xC624;

    /**
     * Primary dimensionality reduction matrix.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int REDUCTION_MATRIX_1 = 0xC625;

    /**
     * Secondary dimensionality reduction matrix.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int REDUCTION_MATRIX_2 = 0xC626;

    /**
     * Analog balance values per color channel.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int ANALOG_BALANCE = 0xC627;

    /**
     * As-shot neutral white balance coordinates.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int AS_SHOT_NEUTRAL = 0xC628;

    /**
     * As-shot white point chromaticity coordinates.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int AS_SHOT_WHITE_XY = 0xC629;

    /**
     * Baseline exposure offset value.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int BASELINE_EXPOSURE = 0xC62A;

    /**
     * Baseline noise level estimate.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int BASELINE_NOISE = 0xC62B;

    /**
     * Baseline sharpness estimate.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int BASELINE_SHARPNESS = 0xC62C;

    /**
     * Bayer green channel split tolerance.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int BAYER_GREEN_SPLIT = 0xC62D;

    /**
     * Linear response limit for the sensor.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int LINEAR_RESPONSE_LIMIT = 0xC62E;

    /**
     * Lens specification information for the captured image.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int LENS_INFO = 0xC630;

    /**
     * Chroma blur radius applied during demosaicing.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int CHROMA_BLUR_RADIUS = 0xC631;

    /**
     * Anti-aliasing strength applied during capture.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int ANTI_ALIAS_STRENGTH = 0xC632;

    /**
     * Shadow scale parameter for tone mapping.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int SHADOW_SCALE = 0xC633;

    /**
     * Private DNG data block for vendor extensions.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int DNG_PRIVATE_DATA = 0xC634;

    /**
     * Primary calibration illuminant identifier.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int CALIBRATION_ILLUMINANT_1 = 0xC65A;

    /**
     * Secondary calibration illuminant identifier.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int CALIBRATION_ILLUMINANT_2 = 0xC65B;

    /**
     * Best quality scale factor for rendering.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int BEST_QUALITY_SCALE = 0xC65C;

    /**
     * Unique identifier for the raw image data.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int RAW_DATA_UNIQUE_ID = 0xC65D;

    /**
     * Original raw file name before conversion.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int ORIGINAL_RAW_FILE_NAME = 0xC68B;

    /**
     * Original raw file data embedded in the DNG.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int ORIGINAL_RAW_FILE_DATA = 0xC68C;

    /**
     * Active image area coordinates.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int ACTIVE_AREA = 0xC68D;

    /**
     * Masked areas within the raw image.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int MASKED_AREAS = 0xC68E;

    /**
     * As-shot ICC profile for color rendering.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int AS_SHOT_ICC_PROFILE = 0xC68F;

    /**
     * As-shot pre-profile matrix for color transforms.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int AS_SHOT_PRE_PROFILE_MATRIX = 0xC690;

    /**
     * Current ICC profile for color rendering.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int CURRENT_ICC_PROFILE = 0xC691;

    /**
     * Current pre-profile matrix for color transforms.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int CURRENT_PRE_PROFILE_MATRIX = 0xC692;

    /**
     * Colorimetric reference identifier.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int COLORIMETRIC_REFERENCE = 0xC6BF;

    /**
     * DNG camera calibration signature string recorded alongside the profile data.
     * EXIF 3.0 §4.6.3 Tables 35–41; EXIF 2.32 §4.6.3 Tables 35–41.
     */
    public const int CAMERA_CALIBRATION_SIGNATURE = 0xC6F3;

    /**
     * DNG profile calibration signature string supplied by the camera vendor.
     * EXIF 3.0 §4.6.3 Tables 35–41; EXIF 2.32 §4.6.3 Tables 35–41.
     */
    public const int PROFILE_CALIBRATION_SIGNATURE = 0xC6F4;

    /**
     * Lists the encoding functions applied to each hue/saturation/value channel in the profile maps.
     * EXIF 3.0 §4.6.3 Tables 35–41; EXIF 2.32 §4.6.3 Tables 35–41.
     */
    public const int PROFILE_HUE_SAT_MAP_ENCODINGS = 0xC6F5;

    /**
     * Records the hue/saturation/value grid dimensions used by the profile maps.
     * EXIF 3.0 §4.6.3 Tables 35–41; EXIF 2.32 §4.6.3 Tables 35–41.
     */
    public const int PROFILE_HUE_SAT_MAP_DIMS = 0xC6F6;

    /**
     * Primary hue/saturation/value adjustment map encoded as IEEE-754 floats.
     * EXIF 3.0 §4.6.3 Tables 35–41; EXIF 2.32 §4.6.3 Tables 35–41.
     */
    public const int PROFILE_HUE_SAT_MAP_DATA_1 = 0xC6F7;

    /**
     * Secondary hue/saturation/value adjustment map encoded as IEEE-754 floats.
     * EXIF 3.0 §4.6.3 Tables 35–41; EXIF 2.32 §4.6.3 Tables 35–41.
     */
    public const int PROFILE_HUE_SAT_MAP_DATA_2 = 0xC6F8;

    /**
     * Tertiary hue/saturation/value adjustment map encoded as IEEE-754 floats.
     * EXIF 3.0 §4.6.3 Tables 35–41; EXIF 2.32 §4.6.3 Tables 35–41.
     */
    public const int PROFILE_HUE_SAT_MAP_DATA_3 = 0xC6F9;

    /**
     * Defines the hue/saturation/value grid dimensions used by the look table.
     * EXIF 3.0 §4.6.3 Tables 35–41; EXIF 2.32 §4.6.3 Tables 35–41.
     */
    public const int PROFILE_LOOK_TABLE_DIMS = 0xC6FA;

    /**
     * Profile look table entries encoded as triplets of IEEE-754 floats.
     * EXIF 3.0 §4.6.3 Tables 35–41; EXIF 2.32 §4.6.3 Tables 35–41.
     */
    public const int PROFILE_LOOK_TABLE_DATA = 0xC6FB;

    /**
     * Optional tone curve defined as normalised IEEE-754 float pairs.
     * EXIF 3.0 §4.6.3 Tables 35–41; EXIF 2.32 §4.6.3 Tables 35–41.
     */
    public const int PROFILE_TONE_CURVE = 0xC6FC;

    /**
     * Profile embed policy flag.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int PROFILE_EMBED_POLICY = 0xC6FD;

    /**
     * Profile copyright information.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int PROFILE_COPYRIGHT = 0xC6FE;

    /**
     * Primary forward transformation matrix.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int FORWARD_MATRIX_1 = 0xC714;

    /**
     * Secondary forward transformation matrix.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int FORWARD_MATRIX_2 = 0xC715;

    /**
     * Preview application name.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int PREVIEW_APPLICATION_NAME = 0xC716;

    /**
     * Preview application version.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int PREVIEW_APPLICATION_VERSION = 0xC717;

    /**
     * Preview settings name.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int PREVIEW_SETTINGS_NAME = 0xC718;

    /**
     * Preview settings digest.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int PREVIEW_SETTINGS_DIGEST = 0xC719;

    /**
     * Preview color space identifier.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int PREVIEW_COLOR_SPACE = 0xC71A;

    /**
     * DNG preview date and time for processing settings.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int DNG_PREVIEW_DATE_TIME = 0xC71B;

    /**
     * Raw image digest for integrity verification.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int RAW_IMAGE_DIGEST = 0xC71C;

    /**
     * Original raw file digest for integrity verification.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int ORIGINAL_RAW_FILE_DIGEST = 0xC71D;

    /**
     * Sub-tile block size for tiled images.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int SUB_TILE_BLOCK_SIZE = 0xC71E;

    /**
     * Row interleave factor for image data.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int ROW_INTERLEAVE_FACTOR = 0xC71F;

    /**
     * DNG profile look table dimensions (corrected location).
     * Adobe DNG Specification v1.4 §2.
     * Note: Supersedes the 0xC6FA location with correct DNG SDK mapping.
     */
    public const int DNG_PROFILE_LOOK_TABLE_DIMS = 0xC725;

    /**
     * DNG profile look table data (corrected location).
     * Adobe DNG Specification v1.4 §2.
     * Note: Supersedes the 0xC6FB location with correct DNG SDK mapping.
     */
    public const int DNG_PROFILE_LOOK_TABLE_DATA = 0xC726;

    /**
     * Opcode list 1 for image processing operations.
     * Adobe DNG Specification v1.4 §3.
     */
    public const int OPCODE_LIST_1 = 0xC740;

    /**
     * Opcode list 2 for image processing operations.
     * Adobe DNG Specification v1.4 §3.
     */
    public const int OPCODE_LIST_2 = 0xC741;

    /**
     * Opcode list 3 for image processing operations.
     * Adobe DNG Specification v1.4 §3.
     */
    public const int OPCODE_LIST_3 = 0xC74E;

    /**
     * Noise profile parameters.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int NOISE_PROFILE = 0xC761;

    /**
     * Original default final size dimensions.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int ORIGINAL_DEFAULT_FINAL_SIZE = 0xC791;

    /**
     * Original best quality final size dimensions.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int ORIGINAL_BEST_QUALITY_FINAL_SIZE = 0xC792;

    /**
     * Original default crop size dimensions.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int ORIGINAL_DEFAULT_CROP_SIZE = 0xC793;

    /**
     * Profile hue/saturation map encoding method.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int PROFILE_HUE_SAT_MAP_ENCODING = 0xC7A3;

    /**
     * Profile look table encoding method.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int PROFILE_LOOK_TABLE_ENCODING = 0xC7A4;

    /**
     * Baseline exposure offset adjustment.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int BASELINE_EXPOSURE_OFFSET = 0xC7A5;

    /**
     * Default black render flag.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int DEFAULT_BLACK_RENDER = 0xC7A6;

    /**
     * New raw image digest for updated integrity verification.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int NEW_RAW_IMAGE_DIGEST = 0xC7A7;

    /**
     * Raw to preview gain value.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int RAW_TO_PREVIEW_GAIN = 0xC7A8;

    /**
     * Cache blob for performance optimization.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int CACHE_BLOB = 0xC7A9;

    /**
     * Cache version identifier.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int CACHE_VERSION = 0xC7AA;

    /**
     * Default user crop region.
     * Adobe DNG Specification v1.4 §2.
     */
    public const int DEFAULT_USER_CROP = 0xC7B5;

    /**
     * Version of the GPS IFD specification.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_VERSION_ID = 0x0000;

    /**
     * Reference for latitude hemisphere.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_LATITUDE_REF = 0x0001;

    /**
     * Latitude expressed as degrees, minutes and seconds.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_LATITUDE = 0x0002;

    /**
     * Reference for longitude hemisphere.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_LONGITUDE_REF = 0x0003;

    /**
     * Longitude expressed as degrees, minutes and seconds.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_LONGITUDE = 0x0004;

    /**
     * Reference for altitude measurement (above/below sea level).
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_ALTITUDE_REF = 0x0005;

    /**
     * Altitude of the image capture location.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_ALTITUDE = 0x0006;

    /**
     * UTC time recorded for the GPS measurement.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_TIME_STAMP = 0x0007;

    /**
     * Satellites used to acquire the GPS fix.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_SATELLITES = 0x0008;

    /**
     * Status of the GPS receiver at capture time.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_STATUS = 0x0009;

    /**
     * GPS measurement mode employed.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_MEASURE_MODE = 0x000A;

    /**
     * Dilution of precision for GPS measurements.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_DOP = 0x000B;

    /**
     * Reference unit for ground speed.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_SPEED_REF = 0x000C;

    /**
     * Ground speed of the GPS receiver.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_SPEED = 0x000D;

    /**
     * Reference for movement direction.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_TRACK_REF = 0x000E;

    /**
     * Movement direction of the GPS receiver.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_TRACK = 0x000F;

    /**
     * Reference for camera pointing direction.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_IMG_DIRECTION_REF = 0x0010;

    /**
     * Camera pointing direction.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_IMG_DIRECTION = 0x0011;

    /**
     * Map datum used for the geographic coordinates.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_MAP_DATUM = 0x0012;

    /**
     * Reference for destination latitude hemisphere.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_DEST_LATITUDE_REF = 0x0013;

    /**
     * Destination latitude of the GPS navigation data.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_DEST_LATITUDE = 0x0014;

    /**
     * Reference for destination longitude hemisphere.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_DEST_LONGITUDE_REF = 0x0015;

    /**
     * Destination longitude of the GPS navigation data.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_DEST_LONGITUDE = 0x0016;

    /**
     * Reference for destination bearing measurement.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_DEST_BEARING_REF = 0x0017;

    /**
     * Destination bearing for the recorded navigation data.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_DEST_BEARING = 0x0018;

    /**
     * Reference for destination distance measurement.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_DEST_DISTANCE_REF = 0x0019;

    /**
     * Destination distance for the recorded navigation data.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_DEST_DISTANCE = 0x001A;

    /**
     * Method used to determine location.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_PROCESSING_METHOD = 0x001B;

    /**
     * Name of the GPS area information.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_AREA_INFORMATION = 0x001C;

    /**
     * Date stamp recorded by the GPS receiver.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_DATE_STAMP = 0x001D;

    /**
     * Differential GPS correction data.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_DIFFERENTIAL = 0x001E;

    /**
     * Estimated horizontal positioning error.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GPS_H_POSITIONING_ERROR = 0x001F;

    /**
     * Ambient temperature measured by the GPS unit.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int TEMPERATURE = 0x9400;

    /**
     * Relative humidity measured by the GPS unit.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int HUMIDITY = 0x9401;

    /**
     * Atmospheric pressure measured by the GPS unit.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int PRESSURE = 0x9402;

    /**
     * Water depth below the recording equipment.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int WATER_DEPTH = 0x9403;

    /**
     * Linear acceleration experienced during capture.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int ACCELERATION = 0x9404;

    /**
     * Camera elevation angle relative to the horizon.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int CAMERA_ELEVATION_ANGLE = 0x9405;

    /**
     * Camera yaw angle relative to true north.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int CAMERA_YAW_DEGREE = 0x9406;

    /**
     * Camera pitch angle relative to the ground plane.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int CAMERA_PITCH_DEGREE = 0x9407;

    /**
     * Camera roll angle relative to the horizon.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int CAMERA_ROLL_DEGREE = 0x9408;

    /**
     * Legacy identifiers retained for backwards compatibility with pre-EXIF 3.0 metadata.
     * EXIF 2.32 §4.6.6 Table 66 labels the FLIGHT_* names; EXIF 3.0 §4.6.6 Table 66 renames them to CAMERA_* variants.
     *
     * The EXIF 3.0 specification renamed the tags to the CAMERA_* variants, but older drone
     * metadata may still expose the historic FLIGHT_* names.
     */
    public const int FLIGHT_YAW_DEGREE = self::CAMERA_YAW_DEGREE;

    /**
     * Legacy identifier mirroring the EXIF 2.32 flight pitch tag name.
     * EXIF 2.32 §4.6.6 Table 66; EXIF 3.0 §4.6.6 Table 66.
     */
    public const int FLIGHT_PITCH_DEGREE = self::CAMERA_PITCH_DEGREE;

    /**
     * Legacy identifier mirroring the EXIF 2.32 flight roll tag name.
     * EXIF 2.32 §4.6.6 Table 66; EXIF 3.0 §4.6.6 Table 66.
     */
    public const int FLIGHT_ROLL_DEGREE = self::CAMERA_ROLL_DEGREE;

    /**
     * Gimbal yaw angle reported by the aircraft.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GIMBAL_YAW_DEGREE = 0x9409;

    /**
     * Gimbal pitch angle reported by the aircraft.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GIMBAL_PITCH_DEGREE = 0x940A;

    /**
     * Gimbal roll angle reported by the aircraft.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int GIMBAL_ROLL_DEGREE = 0x940B;

    /**
     * Aircraft manufacturer name.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int AIRCRAFT_MAKE = 0x940C;

    /**
     * Aircraft model identifier.
     *
     * EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66
     */
    public const int AIRCRAFT_MODEL = 0x940D;

    /**
     * Legacy Microsoft EXIF tag that stored the camera firmware string.
     * Documented in EXIF 2.32 §4.6.3 Table 18; superseded by EXIF 3.0 §4.6.3 Table 18 CameraFirmware.
     */
    public const int CAMERA_FIRMWARE_LEGACY = 0xE92F;

    /**
     * Legacy Microsoft EXIF tag that stored the raw developing software name.
     * Documented in EXIF 2.32 §4.6.3 Table 18; superseded by EXIF 3.0 §4.6.3 Table 18 RawDevelopingSoftware.
     */
    public const int RAW_DEVELOPING_SOFTWARE_LEGACY = 0xE930;

    /**
     * Legacy Microsoft EXIF tag that stored the image editing software name.
     * Documented in EXIF 2.32 §4.6.3 Table 18; superseded by EXIF 3.0 §4.6.3 Table 18 ImageEditingSoftware.
     */
    public const int IMAGE_EDITING_SOFTWARE_LEGACY = 0xE931;

    /**
     * Legacy Microsoft EXIF tag that stored the metadata editing software name.
     * Documented in EXIF 2.32 §4.6.3 Table 18; superseded by EXIF 3.0 §4.6.3 Table 18 MetadataEditingSoftware.
     */
    public const int METADATA_EDITING_SOFTWARE_LEGACY = 0xE932;

    /**
     * Index describing the rules for interoperability data.
     *
     * EXIF 3.0 §4.6.7 Table 67; EXIF 2.32 §4.6.7 Table 67
     */
    public const int INTEROPERABILITY_INDEX = 0x0001;

    /**
     * Interoperability version information.
     *
     * EXIF 3.0 §4.6.7 Table 67; EXIF 2.32 §4.6.7 Table 67
     */
    public const int INTEROPERABILITY_VERSION = 0x0002;

    /**
     * File format of the related image data.
     *
     * EXIF 3.0 §4.6.7 Table 67; EXIF 2.32 §4.6.7 Table 67
     */
    public const int RELATED_IMAGE_FILE_FORMAT = 0x1000;

    /**
     * Width of the related image in pixels.
     *
     * EXIF 3.0 §4.6.7 Table 67; EXIF 2.32 §4.6.7 Table 67
     */
    public const int RELATED_IMAGE_WIDTH = 0x1001;

    /**
     * Height of the related image in pixels.
     *
     * EXIF 3.0 §4.6.7 Table 67; EXIF 2.32 §4.6.7 Table 67
     */
    public const int RELATED_IMAGE_LENGTH = 0x1002;

    /**
     * Prevent instantiation of this constants-only utility class.
     */
    private function __construct()
    {
    }
}
