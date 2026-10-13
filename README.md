# home

Arbeitsverzeichnis für die Hilfe bei unserem Home-Assistant-Server im Heimnetz.

Claude Code greift von hier aus über die Home-Assistant-REST-API auf den Server zu, um Einstellungen und Probleme zu prüfen. Es gibt bewusst keine Skripte und keine dauerhafte Überwachung, nur Abfragen bei Bedarf.

## Zugriff

- **Server:** `http://homeassistant.local:8123`
- **Token:** Long-Lived Access Token in der Windows-Benutzer-Umgebungsvariable `HA_TOKEN`
  - Erstellen: Home Assistant → Profil → Sicherheit → Langlebige Zugriffstoken
  - Setzen (PowerShell):
    ```powershell
    [Environment]::SetEnvironmentVariable("HA_TOKEN", "<token>", "User")
    ```
  - Den Token **niemals** ins Repository einchecken.

Kurzer Verbindungstest:

```powershell
Invoke-RestMethod "http://homeassistant.local:8123/api/" -Headers @{ Authorization = "Bearer $env:HA_TOKEN" }
# → message: API running.
```

## Was Claude hier tut

- Zustände von Entitäten abfragen und nicht erreichbare Geräte finden
- Fehlerprotokoll und Konfiguration prüfen
- Bei Automationen, Integrationen und YAML-Konfiguration helfen

Geräte werden nur nach Rückfrage geschaltet und Einstellungen nur nach Rückfrage geändert.
