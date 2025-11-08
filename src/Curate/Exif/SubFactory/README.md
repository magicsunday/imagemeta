# Sub-Factory Refactoring

This directory contains specialized sub-factories that handle specific aspects of metadata creation,
extracted from the original monolithic ValueFactory.

## Architecture

Each sub-factory:
- Implements `SubFactoryInterface`
- Handles a specific domain of metadata
- Encapsulates all related logic and helper methods
- Can be tested independently

## Factories

### CameraFactory
**Responsibility:** Camera hardware metadata  
**Creates:** `Camera` value object  
**Dependencies:** EXIF document  
**Lines:** ~40

### LensFactory
**Responsibility:** Lens metadata with aperture calculations  
**Creates:** `Lens` value object  
**Dependencies:** EXIF document, ValueConverters  
**Lines:** ~55

### ExposureFactory
**Responsibility:** Exposure settings, metering, flash information  
**Creates:** `Exposure` value object  
**Dependencies:** EXIF document, ExifFlash  
**Lines:** ~60

### SensorFactory
**Responsibility:** Sensor characteristics and specifications  
**Creates:** `Sensor` value object  
**Dependencies:** EXIF document  
**Lines:** ~50

### DeviceFactory
**Responsibility:** Device and software information  
**Creates:** `Device` value object  
**Dependencies:** EXIF document, QuickTime metadata  
**Lines:** ~55

### ImageFactory
**Responsibility:** Image properties and color space normalization  
**Creates:** `Image` value object  
**Dependencies:** EXIF document, metadata container  
**Lines:** ~100

### SceneFactory
**Responsibility:** Scene capture type and HDR detection  
**Creates:** `Scene` value object  
**Dependencies:** EXIF document, QuickTime metadata, Apple MakerNotes  
**Lines:** ~160

### MotionFactory
**Responsibility:** Motion and acceleration data  
**Creates:** `Motion` value object  
**Dependencies:** EXIF document, Apple MakerNotes  
**Lines:** ~90

### GpsFactory
**Responsibility:** GPS metadata from EXIF and XMP  
**Creates:** `Gps` value object  
**Dependencies:** EXIF document, XMP document, ValueConverters  
**Lines:** ~550

## Benefits

1. **Separation of Concerns:** Each factory handles one specific aspect of metadata
2. **Testability:** Factories can be unit tested independently
3. **Maintainability:** Smaller, focused classes are easier to understand and modify
4. **Reusability:** Factories can be composed differently if needed
5. **Reduced Complexity:** Main ValueFactory is now a coordinator rather than doing everything

## Remaining Work

The following areas are still in the main ValueFactory and could be extracted:

- **TemporalFactory:** Date/time metadata (~150 lines)
- **RegionsFactory:** Face/region detection (~750 lines)
- **MultiPictureFactory:** MPF data (~30 lines)

## Usage

Sub-factories are injected into ValueFactory via constructor dependency injection:

```php
$valueFactory = new ValueFactory(
    cameraFactory: new CameraFactory(),
    lensFactory: new LensFactory(),
    exposureFactory: new ExposureFactory(),
    // ... other factories
);

$components = $valueFactory->createComponents($metadata);
```

Default instances are created automatically if not provided.
