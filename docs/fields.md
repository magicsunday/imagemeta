# Structured field semantics

This document summarises the physical units attached to selected value objects and explains the
circle of confusion (CoC) model used by the derived metrics helper.

## Units

* **Metres (m)**
  * `Derived::hyperfocalM`
  * `Capture::waterDepthM`
  * `Gps::altitude`, `Gps::destinationDistanceMetres`, `Gps::horizontalPositioningError`
* **Kelvin (K)**
  * `Apple::colorTemperature` reports the device supplied white balance as an absolute colour temperature.
* **Degrees (°)**
  * `Derived::fovDiagonalDeg`, `Derived::fovHorizontalDeg`, `Derived::fovVerticalDeg`
  * `Motion::rollDeg`, `Motion::pitchDeg`, `Motion::yawDeg`
  * `Capture::cameraElevationAngleDeg`
  * `Gps::track`, `Gps::imageDirection`, `Gps::destinationBearing`
* **Metres per second squared (m/s²)**
  * `Capture::accelerationMs2`
  * `Motion::accelX`, `Motion::accelY`, `Motion::accelZ`

Unless stated otherwise, temperatures recorded via EXIF tags (for example `Capture::temperatureC`) remain in Celsius.
Time zone offsets in `Temporal` are expressed as raw EXIF strings and minutes (`timeZoneOffsetMinutes`). GPS speed values
(`Gps::speedMs`) are normalised to metres per second.

## Circle of confusion model

`ValueConverters::calcCircleOfConfusionMm()` assumes a full-frame reference circle of confusion of 0.030&nbsp;mm. When a crop
factor is available, the constant is divided by the crop factor to approximate the effective CoC for the captured format. If no
crop factor can be derived the function keeps the 0.030&nbsp;mm baseline, and it returns `null` whenever an invalid (zero or
negative) crop factor is encountered. This value feeds into the hyperfocal distance calculator and the three field-of-view helpers.
