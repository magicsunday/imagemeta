# ImageMeta Structured API (EXIF quickstart)

The structured API normalises EXIF metadata into immutable value objects. Each value object exposes fluent getters that return
typed data or `null` when the source tag is absent. This quickstart shows how to read an image and access EXIF-centric
information without dealing with raw tag identifiers.

## Minimal example

```php
<?php

declare(strict_types=1);

use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;

$reader = new MetadataReader();
$metadata = $reader->read('/path/to/photo.jpg')->structured();

printf("Camera: %s %s\n", $metadata->camera->make, $metadata->camera->model);

$exposure = $metadata->exposure;

printf("ISO: %s\n", $exposure->iso ?? 'n/a');
printf("Exposure time: %.4f s\n", $exposure->exposureTimeSec ?? 0.0);
printf("F-number: f/%.1f\n", $exposure->fNumber ?? 0.0);

if ($exposure->program === ExposureProgram::PROGRAM_APERTURE_PRIORITY) {
    echo "Captured in aperture priority mode" . PHP_EOL;
}

if ($exposure->flash?->fired() === true) {
    echo "Flash fired" . PHP_EOL;
}

$whiteBalanceKelvin = $metadata->processing->whiteBalance->kelvin();
printf("White balance: %s K\n", $whiteBalanceKelvin !== null ? (string) $whiteBalanceKelvin : 'auto');
```

The `MetadataReader` streams the file, so the example works for JPEG and HEIC containers without loading the full asset into
memory. Every nested aggregate returns well-defined value objects, e.g. the flash metadata above resolves to
`MagicSunday\ImageMeta\Value\FlashInfo`.

## Accessing GPS information

```php
$gps = $metadata->gps;

if (($gps->latitude !== null) && ($gps->longitude !== null)) {
    printf(
        "Coordinates: %.6f°, %.6f° (%s/%s)\n",
        $gps->latitude->toFloat() ?? 0.0,
        $gps->longitude->toFloat() ?? 0.0,
        $gps->latitude->reference() ?? '?',
        $gps->longitude->reference() ?? '?',
    );
}
```

The structured API mirrors EXIF 2.32 table 66, so consumers receive decoded coordinates, navigation data and derived timestamps.
Missing fields simply surface as `null`, which keeps the fluent API predictable for chained property access.

## Handling absent EXIF values

All getters return nullable scalars or enums. For example, when the camera does not report white balance or flash metadata the
calls in the example above resolve to `null`. Consumers can therefore safely access nested properties using PHP's nullsafe
operator (`?->`) or coalesce to defaults.
