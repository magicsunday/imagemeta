# Structured field semantics

This document summarises the physical units attached to selected value objects and explains the
circle of confusion (CoC) model used by the derived metrics helper.

## Units

* **Metres (m)**
  * `Derived::hyperfocalDistanceMetres`
  * `Capture::waterDepthM`
  * `Gps::altitude`, `Gps::destinationDistanceMetres`, `Gps::horizontalPositioningError`
* **Kelvin (K)**
  * `Apple::colorTemperature` reports the device supplied white balance as an absolute colour temperature.
* **Degrees (°)**
  * `Derived::fieldOfViewDiagonalDeg`, `Derived::fieldOfViewHorizontalDeg`, `Derived::fieldOfViewVerticalDeg`
  * `Motion::rollDeg`, `Motion::pitchDeg`, `Motion::yawDeg`
  * `Capture::cameraElevationAngleDeg`
  * `Gps::track`, `Gps::imageDirection`, `Gps::destinationBearing`
* **Millimetres (mm)**
  * `Derived::circleOfConfusionMm`
* **Metres per second squared (m/s²)**
  * `Capture::accelerationMs2`
  * `Motion::accelX`, `Motion::accelY`, `Motion::accelZ`

Unless stated otherwise, temperatures recorded via EXIF tags (for example `Capture::temperatureC`) remain in Celsius.
Time zone offsets in `Temporal` are expressed as raw EXIF strings and minutes (`timeZoneOffsetMinutes`). GPS speed values
(`Gps::speedMs`) are normalised to metres per second.

When the EXIF GPS IFD omits `GPSVersionID` or only includes null padding, `Gps::version` resolves to the EXIF default `2.0.0.0`.

## Circle of confusion model

`ValueConverters::calcCircleOfConfusionMm()` assumes a full-frame reference circle of confusion of 0.030&nbsp;mm. The resulting value is exposed to consumers via `Derived::circleOfConfusionMm`. When a crop
factor is available, the constant is divided by the crop factor to approximate the effective CoC for the captured format. If no
crop factor can be derived the function keeps the 0.030&nbsp;mm baseline, and it returns `null` whenever an invalid (zero or
negative) crop factor is encountered. This value feeds into the hyperfocal distance calculator and the three field-of-view helpers.
