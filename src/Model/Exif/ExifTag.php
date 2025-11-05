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
     */
    public const int NEW_SUBFILE_TYPE = 0x00FE;

    /**
     * Legacy TIFF 5.0 subfile type value describing the image purpose.
     */
    public const int SUBFILE_TYPE = 0x00FF;

    /**
     * EXIF 3.0 tag recording the software responsible for final image processing.
     */
    public const int PROCESSING_SOFTWARE = 0x000B;

    /**
     * Width of the image in pixels.
     */
    public const int IMAGE_WIDTH = 0x0100;

    /**
     * Height of the image in pixels.
     */
    public const int IMAGE_HEIGHT = 0x0101;

    /**
     * Number of bits for each colour component.
     */
    public const int BITS_PER_SAMPLE = 0x0102;

    /**
     * Compression scheme applied to the image data.
     */
    public const int COMPRESSION = 0x0103;

    /**
     * Colour space interpretation of the pixel data.
     */
    public const int PHOTOMETRIC_INTERPRETATION = 0x0106;

    /**
     * Legacy EXIF ≤ 2.x tag storing the document name within IFD0.
     */
    public const int DOCUMENT_NAME = 0x010D;

    /**
     * Free-form text describing the image contents.
     */
    public const int IMAGE_DESCRIPTION = 0x010E;

    /**
     * Offset pointer to additional linked IFDs.
     */
    public const int SUB_IFDS = 0x014A;

    /**
     * Modern EXIF 3.0 title string for the image.
     */
    public const int IMAGE_TITLE = 0xA436;

    /**
     * Microsoft XPTitle property encoded as UTF-16LE.
     */
    public const int XP_TITLE = 0x9C9B;

    /**
     * Microsoft XPComment property encoded as UTF-16LE.
     */
    public const int XP_COMMENT = 0x9C9C;

    /**
     * Microsoft XPAuthor property encoded as UTF-16LE.
     */
    public const int XP_AUTHOR = 0x9C9D;

    /**
     * Microsoft XPKeywords property encoded as UTF-16LE.
     */
    public const int XP_KEYWORDS = 0x9C9E;

    /**
     * Microsoft XPSubject property encoded as UTF-16LE.
     */
    public const int XP_SUBJECT = 0x9C9F;

    /**
     * Legacy EXIF 2.x tag that stored the document name within IFD0.
     */
    public const int IMAGE_TITLE_LEGACY = 0x0320;

    /**
     * Manufacturer name of the recording equipment.
     */
    public const int MAKE = 0x010F;

    /**
     * Model name or identifier of the recording equipment.
     */
    public const int MODEL = 0x0110;

    /**
     * Orientation of the image as displayed.
     */
    public const int ORIENTATION = 0x0112;

    /**
     * Offsets to image strips within the file.
     */
    public const int STRIP_OFFSETS = 0x0111;

    /**
     * Number of colour components per pixel.
     */
    public const int SAMPLES_PER_PIXEL = 0x0115;

    /**
     * Number of rows stored in each strip.
     */
    public const int ROWS_PER_STRIP = 0x0116;

    /**
     * Total bytes used by each strip.
     */
    public const int STRIP_BYTE_COUNTS = 0x0117;

    /**
     * Width of each image tile in pixels.
     */
    public const int TILE_WIDTH = 0x0142;

    /**
     * Height of each image tile in pixels.
     */
    public const int TILE_LENGTH = 0x0143;

    /**
     * Offsets to tiled image data blocks.
     */
    public const int TILE_OFFSETS = 0x0144;

    /**
     * Total bytes used by each tile.
     */
    public const int TILE_BYTE_COUNTS = 0x0145;

    /**
     * Horizontal pixel density expressed as a rational value.
     */
    public const int X_RESOLUTION = 0x011A;

    /**
     * Vertical pixel density expressed as a rational value.
     */
    public const int Y_RESOLUTION = 0x011B;

    /**
     * Arrangement of colour components across pixel planes.
     */
    public const int PLANAR_CONFIGURATION = 0x011C;

    /**
     * Unit used for X and Y resolution values.
     */
    public const int RESOLUTION_UNIT = 0x0128;

    /**
     * Transfer function curve for colour components.
     */
    public const int TRANSFER_FUNCTION = 0x012D;

    /**
     * Software used to create the image.
     */
    public const int SOFTWARE = 0x0131;

    /**
     * Legacy EXIF 2.x identifier retained for backwards compatibility.
     */
    public const int DATETIME = 0x0132;

    /**
     * Preferred alias that matches the EXIF 3.0 ModifyDate tag name.
     */
    public const int MODIFY_DATE = 0x0132;

    /**
     * Artist or photographer responsible for the image.
     */
    public const int ARTIST = 0x013B;

    /**
     * Legacy EXIF 2.x identifier retained for backwards compatibility.
     */
    public const int HOST_COMPUTER = 0x013C;

    /**
     * Name of the credited photographer.
     */
    public const int PHOTOGRAPHER = 0xA437;

    /**
     * Legacy Microsoft EXIF tag that exposed the photographer credit prior to
     */
    public const int PHOTOGRAPHER_LEGACY = 0xE92D;

    /**
     * Name of the credited image editor.
     */
    public const int IMAGE_EDITOR = 0xA438;

    /**
     * Legacy Microsoft EXIF tag that exposed the image editor credit prior to
     */
    public const int IMAGE_EDITOR_LEGACY = 0xE92E;

    /**
     * Chromaticity of the image white point.
     */
    public const int WHITE_POINT = 0x013E;

    /**
     * Chromaticity coordinates of the primary colours.
     */
    public const int PRIMARY_CHROMATICITIES = 0x013F;

    /**
     * Offset to the JPEG-encoded preview image.
     */
    public const int JPEG_INTERCHANGE_FORMAT = 0x0201;

    /**
     * Length of the JPEG-encoded preview image in bytes.
     */
    public const int JPEG_INTERCHANGE_FORMAT_LENGTH = 0x0202;

    /**
     * Byte offset to the embedded preview image data.
     */
    public const int PREVIEW_IMAGE_START = 0xC51B;

    /**
     * Length of the embedded preview image data in bytes.
     */
    public const int PREVIEW_IMAGE_LENGTH = 0xC51C;

    /**
     * Encoding scheme for the embedded preview image.
     */
    public const int PREVIEW_IMAGE_ENCODING = 0xC51D;

    /**
     * MIME type describing the embedded preview image format.
     */
    public const int PREVIEW_IMAGE_MIME_TYPE = 0xC51E;

    /**
     * Width of the embedded preview image in pixels.
     */
    public const int PREVIEW_IMAGE_WIDTH = 0xC51F;

    /**
     * Height of the embedded preview image in pixels.
     */
    public const int PREVIEW_IMAGE_HEIGHT = 0xC520;

    /**
     * Colour space of the embedded preview image.
     */
    public const int PREVIEW_IMAGE_COLOR_SPACE = 0xC521;

    /**
     * Bit depth of the embedded preview image.
     */
    public const int PREVIEW_IMAGE_BIT_DEPTH = 0xC522;

    /**
     * Date and time when the preview image was generated.
     */
    public const int PREVIEW_DATE_TIME = 0xC523;

    /**
     * Date and time when the preview image was digitised.
     */
    public const int PREVIEW_DATE_TIME_DIGITIZED = 0xC524;

    /**
     * Compression method used for the preview image.
     */
    public const int PREVIEW_IMAGE_COMPRESSION = 0xC525;

    /**
     * Scaling factor applied to derive the preview image.
     */
    public const int PREVIEW_IMAGE_SCALE = 0xC526;

    /**
     * YCbCr transformation coefficients.
     */
    public const int YCBCR_COEFFICIENTS = 0x0211;

    /**
     * Sub-sampling factors for the YCbCr components.
     */
    public const int YCBCR_SUB_SAMPLING = 0x0212;

    /**
     * Reference location for YCbCr samples.
     */
    public const int YCBCR_POSITIONING = 0x0213;

    /**
     * Reference black and white levels for each colour channel.
     */
    public const int REFERENCE_BLACK_WHITE = 0x0214;

    /**
     * Copyright notice associated with the image.
     */
    public const int COPYRIGHT = 0x8298;

    /**
     * Offset to the Exif-specific IFD block.
     */
    public const int EXIF_IFD_POINTER = 0x8769;

    /**
     * Offset to the GPS IFD block.
     */
    public const int GPS_IFD_POINTER = 0x8825;

    /**
     * Offset to the interoperability IFD block.
     */
    public const int INTEROPERABILITY_IFD_POINTER = 0xA005;

    /**
     * Repetition pattern for the colour filter array.
     */
    public const int CFA_REPEAT_PATTERN_DIM = 0x828D;

    /**
     * Charge level remaining in the battery.
     */
    public const int BATTERY_LEVEL = 0x828F;

    /**
     * Epson Print Image Matching (PrintIM) parameter block.
     */
    public const int PRINT_IMAGE_MATCHING = 0xC4A5;

    /**
     * Flag indicating whether maker notes are considered safe to parse.
     */
    public const int MAKER_NOTE_SAFETY = 0xC635;

    /**
     * Exposure duration expressed in seconds.
     */
    public const int EXPOSURE_TIME = 0x829A;

    /**
     * F-number of the lens at the time of capture.
     */
    public const int F_NUMBER = 0x829D;

    /**
     * Program mode setting for exposure control.
     */
    public const int EXPOSURE_PROGRAM = 0x8822;

    /**
     * Description of the spectral sensitivity of the camera.
     */
    public const int SPECTRAL_SENSITIVITY = 0x8824;

    /**
     * Legacy EXIF 2.x identifier retained for backwards compatibility.
     */
    public const int ISO_SPEED_RATINGS_LEGACY = 0x8827;

    /**
     * Current photographic sensitivity expressed as ISO speed.
     */
    public const int PHOTOGRAPHIC_SENSITIVITY = 0x8827;

    /**
     * Opto-electronic conversion function parameters.
     */
    public const int OECF = 0x8828;

    /**
     * Indicator describing interlaced scan type.
     */
    public const int INTERLACE = 0x8829;

    /**
     * Time zone offsets for recorded timestamps.
     */
    public const int TIME_ZONE_OFFSET = 0x882A;

    /**
     * Self-timer delay used for the exposure.
     */
    public const int SELF_TIMER_MODE = 0x882B;

    /**
     * Type of sensitivity value recorded in ISO tags.
     */
    public const int SENSITIVITY_TYPE = 0x8830;

    /**
     * Standard output sensitivity of the camera.
     */
    public const int STANDARD_OUTPUT_SENSITIVITY = 0x8831;

    /**
     * Recommended exposure index for the scene.
     */
    public const int RECOMMENDED_EXPOSURE_INDEX = 0x8832;

    /**
     * Calculated ISO speed value.
     */
    public const int ISO_SPEED = 0x8833;

    /**
     * Latitude component of the ISO speed range (YYY value).
     */
    public const int ISO_SPEED_LATITUDE_YYY = 0x8834;

    /**
     * Latitude component of the ISO speed range (ZZZ value).
     */
    public const int ISO_SPEED_LATITUDE_ZZZ = 0x8835;

    /**
     * EXIF version information recorded in ASCII.
     */
    public const int EXIF_VERSION = 0x9000;

    /**
     * Original capture date and time.
     */
    public const int DATETIME_ORIGINAL = 0x9003;

    /**
     * Digitisation date and time of the original capture.
     */
    public const int DATETIME_DIGITIZED = 0x9004;

    /**
     * Time-zone offset applied to ModifyDate.
     */
    public const int OFFSET_TIME = 0x9010;

    /**
     * Time-zone offset applied to DateTimeOriginal.
     */
    public const int OFFSET_TIME_ORIGINAL = 0x9011;

    /**
     * Time-zone offset applied to DateTimeDigitized.
     */
    public const int OFFSET_TIME_DIGITIZED = 0x9012;

    /**
     * Arrangement of colour components in a compressed stream.
     */
    public const int COMPONENTS_CONFIGURATION = 0x9101;

    /**
     * Compression rate expressed as bits per pixel.
     */
    public const int COMPRESSED_BITS_PER_PIXEL = 0x9102;

    /**
     * APEX shutter speed value.
     */
    public const int SHUTTER_SPEED_VALUE = 0x9201;

    /**
     * APEX aperture value.
     */
    public const int APERTURE_VALUE = 0x9202;

    /**
     * APEX brightness value of the scene.
     */
    public const int BRIGHTNESS_VALUE = 0x9203;

    /**
     * APEX exposure bias applied to the capture.
     */
    public const int EXPOSURE_BIAS_VALUE = 0x9204;

    /**
     * Smallest available lens aperture value.
     */
    public const int MAX_APERTURE_VALUE = 0x9205;

    /**
     * Subject distance from the camera in metres.
     */
    public const int SUBJECT_DISTANCE = 0x9206;

    /**
     * Metering mode used to determine exposure.
     */
    public const int METERING_MODE = 0x9207;

    /**
     * Type of light source illuminating the scene.
     */
    public const int LIGHT_SOURCE = 0x9208;

    /**
     * Status and return light information for the flash.
     */
    public const int FLASH = 0x9209;

    /**
     * Actual lens focal length in millimetres.
     */
    public const int FOCAL_LENGTH = 0x920A;

    /**
     * Area of interest covered by the exposure metering.
     */
    public const int SUBJECT_AREA = 0x9214;

    /**
     * Maker-specific notes recorded by the camera.
     */
    public const int MAKER_NOTE = 0x927C;

    /**
     * Free-form comments entered by the camera user.
     */
    public const int USER_COMMENT = 0x9286;

    /**
     * Fractional seconds for the ModifyDate timestamp.
     */
    public const int SUB_SEC_TIME = 0x9290;

    /**
     * Fractional seconds for the DateTimeOriginal timestamp.
     */
    public const int SUB_SEC_TIME_ORIGINAL = 0x9291;

    /**
     * Fractional seconds for the DateTimeDigitized timestamp.
     */
    public const int SUB_SEC_TIME_DIGITIZED = 0x9292;

    /**
     * FlashPix format version used for the metadata.
     */
    public const int FLASHPIX_VERSION = 0xA000;

    /**
     * Colour space handling for the image data.
     */
    public const int COLOR_SPACE = 0xA001;

    /**
     * Valid pixel width of the primary image.
     */
    public const int PIXEL_X_DIMENSION = 0xA002;

    /**
     * Valid pixel height of the primary image.
     */
    public const int PIXEL_Y_DIMENSION = 0xA003;

    /**
     * Reference to an audio clip related to the image.
     */
    public const int RELATED_SOUND_FILE = 0xA004;

    /**
     * Source of the image data, such as digital camera or scanner.
     */
    public const int FILE_SOURCE = 0xA300;

    /**
     * Scene type indicator for the image source.
     */
    public const int SCENE_TYPE = 0xA301;

    /**
     * Rendering mode applied during image processing.
     */
    public const int CUSTOM_RENDERED = 0xA401;

    /**
     * Exposure mode setting used by the camera.
     */
    public const int EXPOSURE_MODE = 0xA402;

    /**
     * White balance setting applied during capture.
     */
    public const int WHITE_BALANCE = 0xA403;

    /**
     * Ratio between the focal length and a reference value.
     */
    public const int DIGITAL_ZOOM_RATIO = 0xA404;

    /**
     * Equivalent focal length expressed for 35mm film.
     */
    public const int FOCAL_LENGTH_IN_35MM_FILM = 0xA405;

    /**
     * Scene capture type classification.
     */
    public const int SCENE_CAPTURE_TYPE = 0xA406;

    /**
     * Overall image gain control setting.
     */
    public const int GAIN_CONTROL = 0xA407;

    /**
     * Contrast setting applied to the image.
     */
    public const int CONTRAST = 0xA408;

    /**
     * Saturation setting applied to the image.
     */
    public const int SATURATION = 0xA409;

    /**
     * Sharpness setting applied to the image.
     */
    public const int SHARPNESS = 0xA40A;

    /**
     * Distance range classification for the subject.
     */
    public const int SUBJECT_DISTANCE_RANGE = 0xA40C;

    /**
     * Globally unique identifier assigned to the image.
     */
    public const int IMAGE_UNIQUE_ID = 0xA420;

    /**
     * Name of the camera owner.
     */
    public const int CAMERA_OWNER_NAME = 0xA430;

    /**
     * Serial number assigned to the camera body.
     */
    public const int BODY_SERIAL_NUMBER = 0xA431;

    /**
     * Serial number assigned to the camera unit.
     */
    public const int CAMERA_SERIAL_NUMBER = 0xC62F;

    /**
     * Detailed lens specification range values.
     */
    public const int LENS_SPECIFICATION = 0xA432;

    /**
     * Lens manufacturer name.
     */
    public const int LENS_MAKE = 0xA433;

    /**
     * Lens model designation.
     */
    public const int LENS_MODEL = 0xA434;

    /**
     * Lens serial number value.
     */
    public const int LENS_SERIAL_NUMBER = 0xA435;

    /**
     * Legacy EXIF 2.x tag that stored the dedicated camera firmware version.
     */
    public const int CAMERA_FIRMWARE_VERSION_LEGACY = 0xA436;

    /**
     * Firmware name or version reported by the camera.
     */
    public const int CAMERA_FIRMWARE = 0xA439;

    /**
     * Legacy EXIF 2.x tag that stored the raw developing software version.
     */
    public const int RAW_DEVELOPING_SOFTWARE_VERSION_LEGACY = 0xA439;

    /**
     * Raw developing software name or version.
     */
    public const int RAW_DEVELOPING_SOFTWARE = 0xA43A;

    /**
     * Legacy EXIF 2.x tag that stored the image editing software version.
     */
    public const int IMAGE_EDITING_SOFTWARE_VERSION_LEGACY = 0xA43B;

    /**
     * Image editing software name or version.
     */
    public const int IMAGE_EDITING_SOFTWARE = 0xA43B;

    /**
     * Legacy EXIF 2.x tag that stored the metadata editing software version.
     */
    public const int METADATA_EDITING_SOFTWARE_VERSION_LEGACY = 0xA43C;

    /**
     * Metadata editing software name or version.
     */
    public const int METADATA_EDITING_SOFTWARE = 0xA43C;

    /**
     * Classification flag indicating a composite image.
     */
    public const int COMPOSITE_IMAGE = 0xA460;

    /**
     * Number of source images merged into the composite.
     */
    public const int SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE = 0xA461;

    /**
     * Exposure times of the source images used in the composite.
     */
    public const int SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE = 0xA462;

    /**
     * Applied gamma correction value.
     */
    public const int GAMMA = 0xA500;

    /**
     * Strobe energy used for the capture.
     */
    public const int FLASH_ENERGY = 0xA20B;

    /**
     * Spatial frequency response information.
     */
    public const int SPATIAL_FREQUENCY_RESPONSE = 0xA20C;

    /**
     * Noise measurement parameters.
     */
    public const int NOISE = 0xA20D;

    /**
     * Horizontal focal plane resolution.
     */
    public const int FOCAL_PLANE_X_RESOLUTION = 0xA20E;

    /**
     * Vertical focal plane resolution.
     */
    public const int FOCAL_PLANE_Y_RESOLUTION = 0xA20F;

    /**
     * Unit for the focal plane resolution values.
     */
    public const int FOCAL_PLANE_RESOLUTION_UNIT = 0xA210;

    /**
     * Sequential number assigned by the camera.
     */
    public const int IMAGE_NUMBER = 0xA211;

    /**
     * Security classification of the image.
     */
    public const int SECURITY_CLASSIFICATION = 0xA212;

    /**
     * Processing steps applied to the image.
     */
    public const int IMAGE_HISTORY = 0xA213;

    /**
     * Location of the subject within the frame.
     */
    public const int SUBJECT_LOCATION = 0xA214;

    /**
     * Exposure index recommended by the camera.
     */
    public const int EXPOSURE_INDEX = 0xA215;

    /**
     * Identifier for the TIFF/EP standard version used.
     */
    public const int TIFF_EP_STANDARD_ID = 0xA216;

    /**
     * Sensor sensing method employed by the camera.
     */
    public const int SENSING_METHOD = 0xA217;

    /**
     * Colour filter array pattern description.
     */
    public const int CFA_PATTERN = 0xA302;

    /**
     * Description of the device settings used for capture.
     */
    public const int DEVICE_SETTING_DESCRIPTION = 0xA40B;

    /**
     * DNG camera calibration signature string recorded alongside the profile data.
     */
    public const int CAMERA_CALIBRATION_SIGNATURE = 0xC6F3;

    /**
     * DNG profile calibration signature string supplied by the camera vendor.
     */
    public const int PROFILE_CALIBRATION_SIGNATURE = 0xC6F4;

    /**
     * Lists the encoding functions applied to each hue/saturation/value channel in the profile maps.
     */
    public const int PROFILE_HUE_SAT_MAP_ENCODINGS = 0xC6F5;

    /**
     * Records the hue/saturation/value grid dimensions used by the profile maps.
     */
    public const int PROFILE_HUE_SAT_MAP_DIMS = 0xC6F6;

    /**
     * Primary hue/saturation/value adjustment map encoded as IEEE-754 floats.
     */
    public const int PROFILE_HUE_SAT_MAP_DATA_1 = 0xC6F7;

    /**
     * Secondary hue/saturation/value adjustment map encoded as IEEE-754 floats.
     */
    public const int PROFILE_HUE_SAT_MAP_DATA_2 = 0xC6F8;

    /**
     * Tertiary hue/saturation/value adjustment map encoded as IEEE-754 floats.
     */
    public const int PROFILE_HUE_SAT_MAP_DATA_3 = 0xC6F9;

    /**
     * Defines the hue/saturation/value grid dimensions used by the look table.
     */
    public const int PROFILE_LOOK_TABLE_DIMS = 0xC6FA;

    /**
     * Profile look table entries encoded as triplets of IEEE-754 floats.
     */
    public const int PROFILE_LOOK_TABLE_DATA = 0xC6FB;

    /**
     * Optional tone curve defined as normalised IEEE-754 float pairs.
     */
    public const int PROFILE_TONE_CURVE = 0xC6FC;

    /**
     * Version of the GPS IFD specification.
     */
    public const int GPS_VERSION_ID = 0x0000;

    /**
     * Reference for latitude hemisphere.
     */
    public const int GPS_LATITUDE_REF = 0x0001;

    /**
     * Latitude expressed as degrees, minutes and seconds.
     */
    public const int GPS_LATITUDE = 0x0002;

    /**
     * Reference for longitude hemisphere.
     */
    public const int GPS_LONGITUDE_REF = 0x0003;

    /**
     * Longitude expressed as degrees, minutes and seconds.
     */
    public const int GPS_LONGITUDE = 0x0004;

    /**
     * Reference for altitude measurement (above/below sea level).
     */
    public const int GPS_ALTITUDE_REF = 0x0005;

    /**
     * Altitude of the image capture location.
     */
    public const int GPS_ALTITUDE = 0x0006;

    /**
     * UTC time recorded for the GPS measurement.
     */
    public const int GPS_TIME_STAMP = 0x0007;

    /**
     * Satellites used to acquire the GPS fix.
     */
    public const int GPS_SATELLITES = 0x0008;

    /**
     * Status of the GPS receiver at capture time.
     */
    public const int GPS_STATUS = 0x0009;

    /**
     * GPS measurement mode employed.
     */
    public const int GPS_MEASURE_MODE = 0x000A;

    /**
     * Dilution of precision for GPS measurements.
     */
    public const int GPS_DOP = 0x000B;

    /**
     * Reference unit for ground speed.
     */
    public const int GPS_SPEED_REF = 0x000C;

    /**
     * Ground speed of the GPS receiver.
     */
    public const int GPS_SPEED = 0x000D;

    /**
     * Reference for movement direction.
     */
    public const int GPS_TRACK_REF = 0x000E;

    /**
     * Movement direction of the GPS receiver.
     */
    public const int GPS_TRACK = 0x000F;

    /**
     * Reference for camera pointing direction.
     */
    public const int GPS_IMG_DIRECTION_REF = 0x0010;

    /**
     * Camera pointing direction.
     */
    public const int GPS_IMG_DIRECTION = 0x0011;

    /**
     * Map datum used for the geographic coordinates.
     */
    public const int GPS_MAP_DATUM = 0x0012;

    /**
     * Reference for destination latitude hemisphere.
     */
    public const int GPS_DEST_LATITUDE_REF = 0x0013;

    /**
     * Destination latitude of the GPS navigation data.
     */
    public const int GPS_DEST_LATITUDE = 0x0014;

    /**
     * Reference for destination longitude hemisphere.
     */
    public const int GPS_DEST_LONGITUDE_REF = 0x0015;

    /**
     * Destination longitude of the GPS navigation data.
     */
    public const int GPS_DEST_LONGITUDE = 0x0016;

    /**
     * Reference for destination bearing measurement.
     */
    public const int GPS_DEST_BEARING_REF = 0x0017;

    /**
     * Destination bearing for the recorded navigation data.
     */
    public const int GPS_DEST_BEARING = 0x0018;

    /**
     * Reference for destination distance measurement.
     */
    public const int GPS_DEST_DISTANCE_REF = 0x0019;

    /**
     * Destination distance for the recorded navigation data.
     */
    public const int GPS_DEST_DISTANCE = 0x001A;

    /**
     * Method used to determine location.
     */
    public const int GPS_PROCESSING_METHOD = 0x001B;

    /**
     * Name of the GPS area information.
     */
    public const int GPS_AREA_INFORMATION = 0x001C;

    /**
     * Date stamp recorded by the GPS receiver.
     */
    public const int GPS_DATE_STAMP = 0x001D;

    /**
     * Differential GPS correction data.
     */
    public const int GPS_DIFFERENTIAL = 0x001E;

    /**
     * Estimated horizontal positioning error.
     */
    public const int GPS_H_POSITIONING_ERROR = 0x001F;

    /**
     * Ambient temperature measured by the GPS unit.
     */
    public const int TEMPERATURE = 0x9400;

    /**
     * Relative humidity measured by the GPS unit.
     */
    public const int HUMIDITY = 0x9401;

    /**
     * Atmospheric pressure measured by the GPS unit.
     */
    public const int PRESSURE = 0x9402;

    /**
     * Water depth below the recording equipment.
     */
    public const int WATER_DEPTH = 0x9403;

    /**
     * Linear acceleration experienced during capture.
     */
    public const int ACCELERATION = 0x9404;

    /**
     * Camera elevation angle relative to the horizon.
     */
    public const int CAMERA_ELEVATION_ANGLE = 0x9405;

    /**
     * Camera yaw angle relative to true north.
     */
    public const int CAMERA_YAW_DEGREE = 0x9406;

    /**
     * Camera pitch angle relative to the ground plane.
     */
    public const int CAMERA_PITCH_DEGREE = 0x9407;

    /**
     * Camera roll angle relative to the horizon.
     */
    public const int CAMERA_ROLL_DEGREE = 0x9408;

    /**
     * Legacy identifiers retained for backwards compatibility with pre-EXIF 3.0 metadata.
     */
    public const int GIMBAL_YAW_DEGREE = 0x9409;

    /**
     * Gimbal pitch angle reported by the aircraft.
     */
    public const int GIMBAL_PITCH_DEGREE = 0x940A;

    /**
     * Gimbal roll angle reported by the aircraft.
     */
    public const int GIMBAL_ROLL_DEGREE = 0x940B;

    /**
     * Aircraft manufacturer name.
     */
    public const int AIRCRAFT_MAKE = 0x940C;

    /**
     * Aircraft model identifier.
     */
    public const int AIRCRAFT_MODEL = 0x940D;

    /**
     * Legacy Microsoft EXIF tag that stored the camera firmware string.
     */
    public const int CAMERA_FIRMWARE_LEGACY = 0xE92F;

    /**
     * Legacy Microsoft EXIF tag that stored the raw developing software name.
     */
    public const int RAW_DEVELOPING_SOFTWARE_LEGACY = 0xE930;

    /**
     * Legacy Microsoft EXIF tag that stored the image editing software name.
     */
    public const int IMAGE_EDITING_SOFTWARE_LEGACY = 0xE931;

    /**
     * Legacy Microsoft EXIF tag that stored the metadata editing software name.
     */
    public const int METADATA_EDITING_SOFTWARE_LEGACY = 0xE932;

    /**
     * Index describing the rules for interoperability data.
     */
    public const int INTEROPERABILITY_INDEX = 0x0001;

    /**
     * Interoperability version information.
     */
    public const int INTEROPERABILITY_VERSION = 0x0002;

    /**
     * File format of the related image data.
     */
    public const int RELATED_IMAGE_FILE_FORMAT = 0x1000;

    /**
     * Width of the related image in pixels.
     */
    public const int RELATED_IMAGE_WIDTH = 0x1001;

    /**
     * Height of the related image in pixels.
     */
    public const int RELATED_IMAGE_LENGTH = 0x1002;

    /**
     * Prevent instantiation of this constants-only utility class.
     */
    private function __construct()
    {
    }
}
