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

## Struktur

Sicherungen der Konfiguration, die in der Home-Assistant-Oberfläche gepflegt wird. Jede Datei nennt im Kopf, wo sie eingetragen wird.

| Pfad | Inhalt |
|---|---|
| `pixoo64/pages.yaml` | Seiten der Divoom Pixoo 64 im Wohnzimmer (Uhr, Datum, Wetter, Temperaturen) |
| `automations/pixoo64-helligkeit.yaml` | Pixoo-Helligkeit nach Sonnenstand (1 % nachts, 30 % tagsüber, schaltet nie ein) |
| `automations/heizung/` | Heizungsautomationen und -skripte (Tag/Absenkung, Lüften, Fernbedienung, Tag-Werte sichern, Boost) |
| `zigbee2mqtt/addon-options.yaml` | Add-on-Optionen von Zigbee2MQTT Edge (Verbindung zum Zigbee-Dongle) |
| `temp/` | Lokale Ablage für Logs und Ähnliches, wird nicht eingecheckt |

Wenn in Home Assistant etwas geändert wird, die passende Datei hier mit aktualisieren.

## Was Claude hier tut

- Zustände von Entitäten abfragen und nicht erreichbare Geräte finden
- Fehlerprotokoll und Konfiguration prüfen
- Bei Automationen, Integrationen und YAML-Konfiguration helfen

Geräte werden nur nach Rückfrage geschaltet und Einstellungen nur nach Rückfrage geändert.
