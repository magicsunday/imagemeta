# Architektur-Verstöße – Engineering-Tickets

## 🎫 Ticket: DRY-Verstoß durch duplizierte XMP-Parsing-Logik in `MetadataReader`
- **Prinzipien:** DRY, KISS, SoC
- **Priorität:** Mittel
- **Evidenz (Code):**
  - `/home/runner/work/imagemeta/imagemeta/src/MetadataReader.php:167-176`
  - `/home/runner/work/imagemeta/imagemeta/src/MetadataReader.php:255-263`
- **Warum Verstoß:** Die gleiche Schleife zum Parsen/Mergen von XMP-Blobs ist in `fromJpeg()` und `fromIsoBmff()` nahezu identisch implementiert.
- **Risiko / Wartbarkeitskosten:** Bugfixes (z. B. Fehlerbehandlung, Reihenfolge, Merge-Policy) müssen an mehreren Stellen synchron geändert werden.
- **Minimaler Lösungsansatz:** Private Methode `parseXmpBlobs(array $xmpBlobs): ?XmpDocument` extrahieren und in beiden Codepfaden nutzen.
- **Akzeptanzkriterien (prüfbar):**
  - Es existiert genau eine Implementierung der XMP-Blob-Parsing-Loop in `MetadataReader`.
  - Verhalten bleibt unverändert (gleiche Merge-Reihenfolge, gleiche Null-Behandlung).

## 🎫 Ticket: SRP/LoD-Verstoß durch 26-Parameter-Konstruktor von `Metadata`
- **Prinzipien:** SOLID (SRP), Law of Demeter, GRASP (Low Coupling)
- **Priorität:** Hoch
- **Evidenz (Code):**
  - `/home/runner/work/imagemeta/imagemeta/src/Model/Metadata.php:67-94`
  - Aufruf mit vielen positional/null-Parametern: `/home/runner/work/imagemeta/imagemeta/src/MetadataReader.php:190-214`, `265-288`, `319-339`
- **Warum Verstoß:** Der Konstruktor bündelt zu viele Verantwortungen und zwingt Aufrufer, interne Strukturdetails zu kennen (viele `null`/leere Arrays).
- **Risiko / Wartbarkeitskosten:** Hohe Fehleranfälligkeit bei Parameter-Reihenfolge; geringe Lesbarkeit; erschwerte Erweiterung.
- **Minimaler Lösungsansatz:** Einführung eines `MetadataBuilder` (oder gruppierter Parameterobjekte pro Container-Typ), danach schrittweise Migration der Aufrufe.
- **Akzeptanzkriterien (prüfbar):**
  - Konstruktoraufrufe in `MetadataReader` enthalten keine langen positional Argumentlisten mehr.
  - Objektaufbau erfolgt über benannte, domänenspezifische Builder-Methoden.

## 🎫 Ticket: DIP-/Testbarkeitsverstoß durch direkte Parser-Instanziierung in `Metadata`
- **Prinzipien:** SOLID (DIP), SoC
- **Priorität:** Hoch
- **Evidenz (Code):**
  - `/home/runner/work/imagemeta/imagemeta/src/Model/Metadata.php:116`
  - `/home/runner/work/imagemeta/imagemeta/src/Model/Metadata.php:139`
- **Warum Verstoß:** `Metadata` erzeugt `new XmpParser()` und `new IptcParser()` selbst in `selectiveXmpDocument()`/`selectiveIptcDocument()` statt Abhängigkeiten zu injizieren.
- **Risiko / Wartbarkeitskosten:** Harte Kopplung an konkrete Parser, erschwertes Mocking, gemischte Verantwortlichkeiten (Datenmodell + Parsing).
- **Minimaler Lösungsansatz:** Parser (oder Parser-Factory) per Konstruktor injizieren und lazy Nutzung beibehalten.
- **Akzeptanzkriterien (prüfbar):**
  - Keine direkte `new XmpParser()`/`new IptcParser()` Instanziierung in `Metadata`.
  - Unit-Tests können Parser-Verhalten über injizierte Doubles steuern.

## 🎫 Ticket: KISS/SRP-Verstoß durch überladene Methode `ValueFactory::createComponents()`
- **Prinzipien:** KISS, SOLID (SRP), GRASP (High Cohesion)
- **Priorität:** Hoch
- **Evidenz (Code):**
  - Methode mit sehr breiter Verantwortung: `/home/runner/work/imagemeta/imagemeta/src/Exif/Factory/ValueFactory.php:122-472`
- **Warum Verstoß:** Eine Methode orchestriert Erstellung und Ableitung von 30+ Value-Komponenten inkl. Parsing-Nebenlogik, Konvertierungen und Fallbacks.
- **Risiko / Wartbarkeitskosten:** Hohe kognitive Last, schwierige Fehlerlokalisierung, erhöhte Regressionsgefahr bei Änderungen.
- **Minimaler Lösungsansatz:** Schrittweise Extraktion nach Verantwortungsclustern (z. B. `createRights()`, `createAuthor()`, `createDerived()`, `createIntegrity()`).
- **Akzeptanzkriterien (prüfbar):**
  - `createComponents()` enthält primär Orchestrierung und delegiert an kleinere private Methoden.
  - Extrahierte Methoden sind thematisch kohäsiv und testbar.

## 🎫 Ticket: CoC/DRY-Verstoß durch harte XMP-Namespace-Strings in `ValueFactory`
- **Prinzipien:** Convention over Configuration, DRY
- **Priorität:** Mittel
- **Evidenz (Code):**
  - Mehrfache Stringliterale: `/home/runner/work/imagemeta/imagemeta/src/Exif/Factory/ValueFactory.php:347-348`, `357-359`, `362`, `368`, `405`, `415`, `423`, `426`
- **Warum Verstoß:** Namespace-URIs sind verstreut als Magic Strings hinterlegt statt zentral konventioniert.
- **Risiko / Wartbarkeitskosten:** Inkonsistenzen und Tippfehler bei späteren Erweiterungen; erschwerte globale Änderungen.
- **Minimaler Lösungsansatz:** Zentrale Namespace-Konstanten (`XmpNamespaces` Enum/Klasse) einführen und alle Zugriffe darauf umstellen.
- **Akzeptanzkriterien (prüfbar):**
  - In `ValueFactory` werden keine rohen HTTP-URI-Namespaces mehr direkt verwendet.
  - Alle XMP-Namespace-Zugriffe referenzieren zentrale Konstanten.

## 🎫 Ticket: DIP/KISS-Verstoß durch zyklische Initialisierungslogik in `ConverterFactory`
- **Prinzipien:** SOLID (DIP), KISS
- **Priorität:** Mittel
- **Evidenz (Code):**
  - Temporäre Konstruktion + Re-Konstruktion: `/home/runner/work/imagemeta/imagemeta/src/Exif/Converters/ConverterFactory.php:58-65`
- **Warum Verstoß:** Der Aufbau mit `tempNumericConverter`/`tempRationalConverter` zeigt eine fragile Abhängigkeitsverkettung und implizite Initialisierungsreihenfolge.
- **Risiko / Wartbarkeitskosten:** Hohe Fragilität bei Refactoring; unintuitive Objektgraph-Erstellung.
- **Minimaler Lösungsansatz:** Abhängigkeiten entkoppeln (z. B. über klar gerichtete Converter-Verträge oder Lazy-Resolver) und temporäre Konstruktionen entfernen.
- **Akzeptanzkriterien (prüfbar):**
  - Keine temporären Converter-Instanzen für Graph-Aufbau erforderlich.
  - Initialisierungsreihenfolge ist direkt aus Konstruktorcode verständlich.

## 🎫 Ticket: ISP/GRASP-Verstoß durch "God Model" `ParsedExif`
- **Prinzipien:** SOLID (ISP, SRP), GRASP (High Cohesion)
- **Priorität:** Hoch
- **Evidenz (Code):**
  - Mehrfach-Interface + breite API: `/home/runner/work/imagemeta/imagemeta/src/Exif/Model/ParsedExif.php:99`
  - Sehr große Klasse: `/home/runner/work/imagemeta/imagemeta/src/Exif/Model/ParsedExif.php` (über 5.000 LOC)
  - Adapter-Caches: `:131-143`
- **Warum Verstoß:** Eine Klasse vereint sehr viele EXIF-Domänen (IFD0, EXIF, GPS, Interop, Thumbnail, Derived) und liefert zahlreiche Convenience-Zugriffe.
- **Risiko / Wartbarkeitskosten:** Niedrige Kohäsion, breite Änderungsfläche, hohe Regressionsgefahr.
- **Minimaler Lösungsansatz:** Fachlich segmentierte Reader/Facades einführen (z. B. Kamera, Optik, GPS) und `ParsedExif` auf Kernzustand + minimale Zugriffsschicht reduzieren.
- **Akzeptanzkriterien (prüfbar):**
  - Domänenbezogene APIs sind in getrennten, kleineren Komponenten testbar.
  - `ParsedExif` enthält primär Datenhaltung und zentrale Kernzugriffe.

## 🎫 Ticket: YAGNI-Tradeoff – Adapter-Schicht wirkt redundant und intern ungenutzt
- **Prinzipien:** YAGNI, DRY
- **Priorität:** Niedrig
- **Typ:** Design-Tradeoff – Review nötig
- **Evidenz (Code):**
  - Adapter-Klassen: `/home/runner/work/imagemeta/imagemeta/src/Exif/Adapter/*MetadataAdapter.php`
  - Interne Nutzung im `src/`-Code fast ausschließlich via `ParsedExif`-Erzeugung: `/home/runner/work/imagemeta/imagemeta/src/Exif/Model/ParsedExif.php:247-297`
- **Warum möglicher Verstoß:** Adapter enthalten überwiegend reine Delegation auf `ParsedExif` ohne zusätzliche Policy/Validierung.
- **Risiko / Wartbarkeitskosten:** Zusätzliche API-Oberfläche ohne klaren Mehrwert erhöht Pflegeaufwand.
- **Minimaler Lösungsansatz:** API-Nutzungsanalyse (inkl. externe Consumer) durchführen; bei fehlendem Nutzen Deprecation-Plan erstellen.
- **Akzeptanzkriterien (prüfbar):**
  - Dokumentierte Entscheidung: Adapter behalten (begründet) oder deprecaten (mit Migrationspfad).
  - Keine unbegründeten Parallel-APIs mit identischer Semantik.

## 🎫 Ticket: OCP-Verstoß durch tag-spezifischen Switch in `SamsungDecoder`
- **Prinzipien:** SOLID (OCP), GRASP (Low Coupling)
- **Priorität:** Mittel
- **Evidenz (Code):**
  - `/home/runner/work/imagemeta/imagemeta/src/MakerNotes/SamsungDecoder.php:139-150`
- **Warum Verstoß:** Neue unterstützte MakerNote-Tags erfordern direkte Änderung am Switch-Block.
- **Risiko / Wartbarkeitskosten:** Hohe Änderungsfrequenz in zentraler Methode; erhöhte Merge-Konfliktwahrscheinlichkeit.
- **Minimaler Lösungsansatz:** Tag-Handler-Mapping (Tag-ID → Parser-Callback) einführen und bestehende Fälle migrieren.
- **Akzeptanzkriterien (prüfbar):**
  - Neue Tag-Unterstützung kann über Mapping ergänzt werden ohne Switch-Erweiterung.
  - Bestehende Tag-Ergebnisse bleiben bit-identisch.

## 🎫 Ticket: CoC/KISS-Verstoß durch uneinheitliche Hard-Limits in `ItemLocationResolver`
- **Prinzipien:** Convention over Configuration, KISS
- **Priorität:** Mittel
- **Evidenz (Code):**
  - `MAX_ILOC_ITEMS`: `/home/runner/work/imagemeta/imagemeta/src/Parse/IsoBmff/ItemLocationResolver.php:60`
  - `MAX_ILOC_EXTENTS`: `/home/runner/work/imagemeta/imagemeta/src/Parse/IsoBmff/ItemLocationResolver.php:65`
  - `MAX_IINF_ENTRIES`: `/home/runner/work/imagemeta/imagemeta/src/Parse/IsoBmff/ItemLocationResolver.php:70`
  - `MAX_IREF_REFERENCES`: `/home/runner/work/imagemeta/imagemeta/src/Parse/IsoBmff/ItemLocationResolver.php:75`
  - `MAX_IREF_ENTRIES`: `/home/runner/work/imagemeta/imagemeta/src/Parse/IsoBmff/ItemLocationResolver.php:80`
  - `MAX_DREF_ENTRIES`: `/home/runner/work/imagemeta/imagemeta/src/Parse/IsoBmff/ItemLocationResolver.php:85`
- **Warum Verstoß:** Mehrere numerische Limits sind lokal hart kodiert; Policy ist nicht zentralisiert und nur teilweise konsistent (`MAX_DREF_ENTRIES=1000`, sonst meist `10000`).
- **Risiko / Wartbarkeitskosten:** Sicherheits-/Performance-Policies schwer zentral steuerbar; inkonsistente Anpassungen möglich.
- **Minimaler Lösungsansatz:** Limits in zentralem Policy-Objekt oder Shared-Constants bündeln (inkl. begründeter Defaultwerte).
- **Akzeptanzkriterien (prüfbar):**
  - Resolver nutzt zentrale Limit-Definitionen statt lokaler Magic Numbers.
  - Ein Ort definiert und dokumentiert alle parserweiten DoS-Limits.
