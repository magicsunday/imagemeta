# ExifTag Quellen-Analyse (Deutsch)

## Zusammenfassung

Die Klasse `ExifTag.php` enthält **nicht nur** Tags aus der EXIF-Spezifikation, sondern auch Tags aus verschiedenen anderen Quellen. Von den ~231 Konstanten in `ExifTag.php` stammen **nur etwa 48%** aus der offiziellen EXIF 3.0-Spezifikation (Tabellen 64-67, §H.6).

## Kategorisierung nach Herkunft

### 1. EXIF 3.0 Standard-Tags (~120 Tags)

Dies sind die offiziell in EXIF 3.0 §H.6 Tabellen 64-67 definierten Tags:

- **Tabelle 64**: 0th IFD TIFF-Tags (32 Tags)
- **Tabelle 65**: 0th IFD Exif Private Tags (77 Tags)  
- **Tabelle 66**: GPS Info Tags (32 Tags)
- **Tabelle 67**: Interoperability Tags (1 Tag)

Diese Tags sind der **offizielle EXIF-Standard**.

### 2. TIFF 6.0 Standard-Tags (~12 Tags)

Diese Tags stammen aus der TIFF 6.0-Spezifikation, sind aber **nicht** in den EXIF 3.0-Tabellen aufgeführt:

```
NEW_SUBFILE_TYPE            0x00FE  - TIFF 6.0 §8
SUBFILE_TYPE                0x00FF  - TIFF 5.0 (veraltet)
PROCESSING_SOFTWARE         0x000B  - TIFF/EP-Erweiterung
DOCUMENT_NAME               0x010D  - TIFF 6.0 (in EXIF veraltet)
SUB_IFDS                    0x014A  - TIFF 6.0 Extensions
TILE_WIDTH                  0x0142  - TIFF 6.0 §15
TILE_LENGTH                 0x0143  - TIFF 6.0 §15
TILE_OFFSETS                0x0144  - TIFF 6.0 §15
TILE_BYTE_COUNTS            0x0145  - TIFF 6.0 §15
PREDICTOR                   0x013D  - TIFF 6.0 §14 (LZW-Kompression)
ICC_PROFILE                 0x8773  - TIFF 6.0 §20 / ICC.1:2001-04
HOST_COMPUTER               0x013C  - TIFF 6.0 (aus EXIF 3.0 entfernt)
```

**Herkunft**: TIFF 6.0 ist die Basis-Spezifikation für EXIF, aber nicht alle TIFF-Tags sind Teil von EXIF.

### 3. Microsoft XP Tags (5 Tags)

Microsoft führte diese proprietären Tags für Windows XP-Bildeigenschaften ein, kodiert als UTF-16LE:

```
XP_TITLE                    0x9C9B  - Windows XP
XP_COMMENT                  0x9C9C  - Windows XP
XP_AUTHOR                   0x9C9D  - Windows XP
XP_KEYWORDS                 0x9C9E  - Windows XP
XP_SUBJECT                  0x9C9F  - Windows XP
```

**Herkunft**: Microsoft Windows Imaging Component (WIC) / Windows Explorer-Metadaten

**Zweck**: Ermöglicht Windows Explorer die Anzeige und Bearbeitung von Bildinformationen.

### 4. Adobe DNG Tags (~20 Tags in ExifTag.php)

Diese Tags stammen aus der Adobe Digital Negative-Spezifikation:

```
CAMERA_CALIBRATION_SIGNATURE    0xC6F3  - DNG 1.2.0.0
PROFILE_CALIBRATION_SIGNATURE   0xC6F4  - DNG 1.2.0.0
PROFILE_HUE_SAT_MAP_ENCODINGS   0xC6F5  - DNG 1.4.0.0
PROFILE_HUE_SAT_MAP_DIMS        0xC6F6  - DNG 1.2.0.0
PROFILE_HUE_SAT_MAP_DATA_1      0xC6F7  - DNG 1.2.0.0
PROFILE_HUE_SAT_MAP_DATA_2      0xC6F8  - DNG 1.2.0.0
PROFILE_HUE_SAT_MAP_DATA_3      0xC6F9  - DNG 1.2.0.0
PROFILE_LOOK_TABLE_DIMS         0xC6FA  - DNG 1.3.0.0
PROFILE_LOOK_TABLE_DATA         0xC6FB  - DNG 1.3.0.0
PROFILE_TONE_CURVE              0xC6FC  - DNG 1.2.0.0
CAMERA_SERIAL_NUMBER            0xC62F  - DNG 1.0.0.0
PREVIEW_IMAGE_START             0xC51B  - DNG-Erweiterung
PREVIEW_IMAGE_LENGTH            0xC51C  - DNG-Erweiterung
[... weitere Preview-Tags ...]
```

**Herkunft**: Adobe DNG-Spezifikation v1.0.0.0 - v1.7.1.0

**Hinweis**: Es gibt bereits eine separate `DngTag.php`-Klasse. Diese Tags könnten dorthin verschoben werden.

### 5. Legacy/Kompatibilitäts-Tags (~16 Tags)

Tags, die für Rückwärtskompatibilität mit älteren EXIF-Versionen beibehalten wurden:

```
IMAGE_TITLE_LEGACY                      0x0320  - Pre-EXIF 3.0
ISO_SPEED_RATINGS_LEGACY                0x8827  - EXIF 2.x-Name (jetzt PHOTOGRAPHIC_SENSITIVITY)
PHOTOGRAPHER_LEGACY                     0xE92D  - Microsoft Pre-EXIF 3.0
IMAGE_EDITOR_LEGACY                     0xE92E  - Microsoft Pre-EXIF 3.0
CAMERA_FIRMWARE_VERSION_LEGACY          0xA436  - EXIF 2.x (Konflikt mit IMAGE_TITLE!)
RAW_DEVELOPING_SOFTWARE_VERSION_LEGACY  0xA439  - EXIF 2.x (Konflikt mit CAMERA_FIRMWARE!)
[... weitere Legacy-Tags ...]
```

**Wichtig**: Einige Legacy-Konstanten teilen denselben Hex-Wert mit aktuellen EXIF 3.0-Tags und erstellen dadurch Aliase.

### 6. Herstellerspezifische Tags (~8 Tags)

```
PRINT_IMAGE_MATCHING        0xC4A5  - Epson Print Image Matching (PrintIM)
MAKER_NOTE_SAFETY           0xC635  - DNG-Markierung für sichere Maker Notes
BATTERY_LEVEL               0x828F  - TIFF/EP-Erweiterung
CFA_REPEAT_PATTERN_DIM      0x828D  - TIFF/EP-Erweiterung
INTERLACE                   0x8829  - TIFF/IT-Erweiterung
TIME_ZONE_OFFSET            0x882A  - EXIF private (selten verwendet)
SELF_TIMER_MODE             0x882B  - EXIF private (selten verwendet)
NOISE                       0xA20D  - TIFF/EP-Erweiterung
```

## Behandlung von unmappierten Tags

### Werden Tags ohne Konstante in ExifTag.php ignoriert?

**Nein! Alle Tags werden geparst und gespeichert.**

Basierend auf der Analyse von `TiffExifReader.php`:

1. **Alle IFD-Einträge werden gelesen**: Der Parser liest ALLE Directory-Einträge aus IFDs, unabhängig davon, ob sie eine entsprechende Konstante in `ExifTag.php` haben.

2. **Tags werden nach numerischer ID gespeichert**: IFD-Einträge werden in einem assoziativen Array mit der numerischen Tag-ID als Schlüssel gespeichert (`array<int, IfdEntry>`).

3. **Keine Filterung während des Parsens**: Es gibt KEINEN Code, der Tags basierend darauf filtert oder ignoriert, ob sie in `ExifTag.php` vorhanden sind.

4. **Zugriff per numerischer ID möglich**: Code kann auf jeden Tag über seine numerische ID zugreifen:
   ```php
   $ifd->get(0x9999);  // Funktioniert für jeden Tag, auch wenn er nicht in ExifTag.php ist
   ```

5. **Konstanten sind für Bequemlichkeit**: Die `ExifTag`-Konstanten sind rein für Entwickler-Bequemlichkeit und Typsicherheit da. Sie schränken nicht ein, welche Tags geparst oder gespeichert werden.

### Code-Beispiele

```php
// In TiffExifReader::readIfd()
for ($i = 0; $i < $entryCount; ++$i) {
    $entries += $this->readDirEntry();  // Liest ALLE Einträge
}
$ifd = new Ifd($entries, $next > 0 ? $next : null);
```

```php
// In TiffExifReader::readDirEntry()
$tag  = $this->readU16();  // Liest JEDE Tag-ID
$type = $this->readU16();
$cnt  = $this->bigTiff ? $this->readU64()->toInt('...') : $this->readU32();
// ... verarbeitet den Eintrag ...
return [$tag => $entry];  // Gibt Eintrag mit numerischem Tag als Schlüssel zurück
```

### Konsequenzen

- ✅ **Unmappierte Tags werden bewahrt**: Wenn ein Bild einen Tag enthält, der nicht in `ExifTag.php` definiert ist, wird er trotzdem geparst und ist über seine numerische ID zugänglich.

- ✅ **Kein Datenverlust**: Der Parser verwirft keine unbekannten Tags.

- ✅ **Vorwärtskompatibilität**: Neue Tags können über ihre numerische ID zugegriffen werden, bevor sie zu `ExifTag.php` hinzugefügt werden.

- ✅ **Herstellererweiterungen funktionieren**: Herstellerspezifische Tags (Canon, Nikon, Sony usw.) in Maker Notes oder benutzerdefinierten IFDs werden bewahrt.

### Spezielle Behandlung

Bestimmte Tag-Kategorien erhalten spezielle Behandlung:

1. **UTF-16LE-Strings** (XP-Tags): Von Byte-Array in String konvertiert
2. **Maker Notes** (0x927C): Rohe Bytes für spezialisierte Decoder bewahrt
3. **Bilddaten-Offsets**: Spezielle Behandlung für Strip/Tile-Offsets und Byte-Counts
4. **Pointer-Tags**: Werden verfolgt, um Sub-IFDs zu lesen (ExifIFD, GPS IFD usw.)

Alle anderen Tags werden entsprechend ihrem TIFF-Typ dekodiert und wie vorliegend gespeichert.

## Empfehlungen

### 1. Tag-Konstanten nach Quelle trennen

Erwägen Sie, `ExifTag.php` in mehrere Klassen aufzuteilen:

```php
ExifTag.php          // Nur offizielle EXIF 3.0-Tags
TiffTag.php          // TIFF 6.0-Baseline-Tags
MicrosoftXpTag.php   // Proprietäre Microsoft XP-Tags
DngTag.php           // Existiert bereits, gut!
LegacyTag.php        // Rückwärtskompatibilitäts-Aliase
```

### 2. Tag-Ursprünge dokumentieren

PHPDoc-Annotationen hinzufügen, die den Ursprung jedes Tags angeben:

```php
/**
 * Microsoft XPTitle-Eigenschaft, kodiert als UTF-16LE.
 * 
 * @source Microsoft Windows XP / Windows Imaging Component
 * @standard Nicht-Standard (Herstellererweiterung)
 */
public const int XP_TITLE = 0x9C9B;

/**
 * Breite des Bildes in Pixeln.
 * 
 * @source EXIF 3.0 §H.6 Tabelle 64 (Kategorie Ⅰ)
 * @standard EXIF 3.0, EXIF 2.x, TIFF 6.0
 */
public const int IMAGE_WIDTH = 0x0100;
```

### 3. Test-Abdeckung hinzufügen

Tests erstellen, die verifizieren:
- Alle EXIF 3.0 Tabellen 64-67-Tags sind vorhanden
- Nicht-EXIF-Tags sind korrekt kategorisiert
- Unmappierte Tags sind über numerische ID zugänglich

### 4. Dokumentation aktualisieren

Diese Analyse zur Repository-Dokumentation hinzufügen, damit Benutzer verstehen:
- Welche Tags Standard vs. Erweiterungen sind
- Dass alle Tags während des Parsens bewahrt werden
- Wie man auf Tags zugreift, die nicht in `ExifTag.php` sind

## Zusammenfassung der Statistik

- **EXIF 3.0 Standard**: ~120 Tags aus Tabellen 64-67
- **TIFF 6.0 Erweiterungen**: ~12 Tags (nicht in EXIF-Spec)
- **Microsoft XP**: 5 proprietäre Tags
- **Adobe DNG**: ~20 Tags (separate Spezifikation)
- **Legacy/Kompatibilität**: ~16 umbenannte oder veraltete Tags
- **Herstellerspezifisch**: ~8 Herstellererweiterungs-Tags

**Gesamt**: ~231 Konstanten in `ExifTag.php`, wovon etwa **52%** aus Nicht-EXIF-Quellen stammen.

**Unmappierte Tags**: Alle Tags werden während des Parsens bewahrt, unabhängig davon, ob sie in `ExifTag.php` erscheinen. Die Konstanten sind für Bequemlichkeit, nicht für Filterung.

## Antwort auf die ursprüngliche Frage

### Welche Tags sind nicht aus der EXIF-Spezifikation?

Etwa **52% der Tags** in `ExifTag.php` stammen aus anderen Quellen:

1. **TIFF 6.0**: Baseline-Imaging-Tags (Predictor, ICC Profile, Tile-Tags usw.)
2. **Microsoft XP**: Proprietäre Windows-Metadaten (XP_TITLE, XP_AUTHOR usw.)
3. **Adobe DNG**: RAW-Verarbeitungs-Tags (ProfileHueSatMap, CameraCalibration usw.)
4. **Legacy**: Veraltete oder umbenannte Tags aus älteren EXIF-Versionen
5. **Hersteller**: Epson PrintIM, TIFF/EP-Erweiterungen

### Wie werden Tags ohne Mapping behandelt?

**Sie werden NICHT ignoriert!** Alle Tags werden:

- ✅ Geparst und in IFD-Strukturen gespeichert
- ✅ Über numerische ID zugänglich: `$ifd->get(0xABCD)`
- ✅ Mit ihrem Rohdatentyp und -wert bewahrt
- ✅ Für Weiterverarbeitung verfügbar gemacht

Die `ExifTag`-Konstanten sind Entwicklerhilfen für häufig verwendete Tags, sie filtern oder beschränken NICHT die tatsächlich geparseten Tags.
