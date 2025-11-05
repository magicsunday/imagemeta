# Coverage Badge Setup

## Übersicht

Das Coverage-Badge wird automatisch von der GitHub Actions CI aktualisiert und zeigt den aktuellen Code-Coverage-Prozentsatz an. Das Badge wird über einen GitHub Gist bereitgestellt und benötigt eine einmalige Einrichtung.

## Einrichtung

### 1. GitHub Personal Access Token erstellen

1. Gehe zu GitHub Settings → Developer settings → Personal access tokens → Tokens (classic)
2. Klicke auf "Generate new token (classic)"
3. Gib dem Token einen Namen (z.B. "Coverage Badge Token")
4. Wähle die **gist** Berechtigung aus
5. Klicke auf "Generate token"
6. **Wichtig:** Kopiere den Token sofort, er wird nur einmal angezeigt

### 2. Gist erstellen

1. Gehe zu https://gist.github.com/
2. Erstelle einen neuen **öffentlichen** Gist:
   - Filename: `imagemeta-coverage.json`
   - Content: `{"schemaVersion": 1, "label": "coverage", "message": "0%", "color": "red"}`
3. Klicke auf "Create public gist"
4. Kopiere die **Gist ID** aus der URL (z.B. `https://gist.github.com/magicsunday/abc123def456` → ID ist `abc123def456`)

### 3. Repository Secrets konfigurieren

1. Gehe zu deinem Repository → Settings → Secrets and variables → Actions
2. Klicke auf "New repository secret"
3. Erstelle zwei Secrets:
   - Name: `GIST_SECRET`, Value: [Dein Personal Access Token aus Schritt 1]
   - Name: `GIST_ID`, Value: [Deine Gist ID aus Schritt 2]

### 4. README.md aktualisieren

Ersetze in der README.md die Platzhalter:
```markdown
[![Coverage](https://img.shields.io/endpoint?url=https://gist.githubusercontent.com/magicsunday/GIST_ID/raw/imagemeta-coverage.json)](https://github.com/magicsunday/imagemeta/actions/workflows/ci.yml)
```

Ersetze `GIST_ID` mit deiner tatsächlichen Gist ID.

## Funktionsweise

1. Bei jedem Push auf den `main` Branch:
   - PHPUnit generiert Coverage-Daten
   - Der Coverage-Prozentsatz wird aus dem Text-Report extrahiert
   - Der Gist wird mit den neuen Daten aktualisiert
   - Das Badge zeigt automatisch den neuen Wert an

2. Badge-Farben (automatisch):
   - > 90%: Hellgrün (brightgreen)
   - > 80%: Grün (green)
   - > 70%: Gelbgrün (yellowgreen)
   - > 60%: Gelb (yellow)
   - > 50%: Orange (orange)
   - ≤ 50%: Rot (red)

## Fehlerbehebung

### Badge zeigt "unknown" oder "invalid"
- Überprüfe, ob der Gist öffentlich ist
- Überprüfe die Gist ID in den Repository Secrets
- Überprüfe die URL im README.md Badge

### Badge wird nicht aktualisiert
- Das Badge wird nur bei Pushes auf den `main` Branch aktualisiert
- Überprüfe, ob die GitHub Action erfolgreich durchgelaufen ist
- Überprüfe, ob `GIST_SECRET` korrekt konfiguriert ist und die `gist` Berechtigung hat

### "Bad credentials" Fehler in GitHub Actions
- Das `GIST_SECRET` ist ungültig oder abgelaufen
- Erstelle ein neues Personal Access Token und aktualisiere das Secret

## Alternative ohne Gist (einfacher, aber weniger dynamisch)

Falls du keine Gists verwenden möchtest, kannst du ein statisches Badge verwenden:

```markdown
[![Coverage](https://img.shields.io/badge/coverage-check%20artifacts-blue)](https://github.com/magicsunday/imagemeta/actions/workflows/ci.yml)
```

Dieses zeigt einen statischen Badge mit einem Link zu den GitHub Actions, wo die Coverage-Berichte heruntergeladen werden können.
