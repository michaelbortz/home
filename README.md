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
| `automations/heizung/` | Heizungsautomationen und -skripte (Tag/Absenkung, Lüften, Fernbedienung, Tag-Werte sichern, Boost, W600-Dreh übernehmen) |
| `dashboards/` | Dashboards im Speichermodus als JSON, unverändert aus Home Assistant gelesen (`dashboard-test.json` = „Standard Dashboard“ mit Zuhause, Sensoren, Heizung; `map.json` = Karte). Die Übersicht ist automatisch erzeugt und hat keine eigene Konfiguration |
| `zigbee2mqtt/addon-options.yaml` | Add-on-Optionen von Zigbee2MQTT (Verbindung zum Zigbee-Dongle) |
| `temp/` | Lokale Ablage für Logs und Ähnliches, wird nicht eingecheckt |

Wenn in Home Assistant etwas geändert wird, die passende Datei hier mit aktualisieren.

## Bekannte Probleme und Lösungen

### Aqara W600 (Schlafzimmer) ignoriert Home Assistant nach Drehen am Gerät

**Symptom:** Home Assistant zeigt eine neue Solltemperatur an (z. B. 5 °C bei offenem Fenster), aber der W600 regelt weiter auf den alten Wert. Das Ventil bleibt offen, das Display zeigt den alten Sollwert. Das war auch das Problem bei der ersten Einbindung 2025.

**Ursache:** Wird am W600 gedreht, hält er den gedrehten Wert intern fest und ignoriert danach alle Sollwerte, die per Zigbee kommen. Zigbee2MQTT meldet die neuen Werte trotzdem als übernommen, und `override_active` steht sogar auf `false`. Ein Batteriewechsel hilft nicht.

Dazu kam, dass das alte Zigbee2MQTT (Edge-Add-on, Stand 2.7.2-dev) den W600 nur unvollständig kannte: kein `system_mode`, kein `override_active`, keine Batterie. Home Assistant zeigte deshalb oft fälschlich „off“ an.

**Lösung (2026-10-13):**
1. Wechsel auf das stabile Zigbee2MQTT-Add-on (2.14.2) mit vollständiger W600-Unterstützung, siehe `zigbee2mqtt/addon-options.yaml`.
2. Ein kurzer Moduswechsel **off → heat** hebt die Sperre auf. Danach übernimmt der W600 wieder jeden Sollwert.
3. Die Automation **„W600 Schlafzimmer: Drehen am Gerät übernehmen“** (`automations/heizung/w600-dreh-uebernehmen.yaml`) macht das automatisch: Nach jeder Änderung der Solltemperatur wartet sie 10 s, schaltet off → heat und setzt den gedrehten Wert erneut. Der Wert bleibt erhalten, Home Assistant behält die Kontrolle, und „Tag-Werte sichern“ speichert ihn als neuen Tageswert.

**Hinweise:**
- Der W600 kennt kein Boost. Im Skript „Modus Tag“ bekommt er seinen Tageswert nur über die Sicherungsszene.
- In der Sicherungsszene steht für den W600 fest `heat`, damit ein falsch gemeldetes „off“ ihn nicht ausschaltet.
- Nach dem Einsetzen der Batterien zeigt er **F1**, bis er am Heizkörper kalibriert ist (`button.schlafzimmer_aqara_thermostat_calibrate`).

### Zigbee2MQTT startet nicht (HOST_FATAL_ERROR, ETIMEDOUT fe80::…)

**Ursache:** Zigbee2MQTT erreichte den SONOFF Dongle Max über eine IPv6-Link-Local-Adresse. Nach einem Neustart des Dongles war sie nicht mehr erreichbar.

**Lösung (2026-10-13):** Verbindung per IPv4 (`tcp://192.168.178.75:6638`) mit fester Adresse in der Fritzbox, siehe `zigbee2mqtt/addon-options.yaml`.

## Was Claude hier tut

- Zustände von Entitäten abfragen und nicht erreichbare Geräte finden
- Fehlerprotokoll und Konfiguration prüfen
- Bei Automationen, Integrationen und YAML-Konfiguration helfen

Geräte werden nur nach Rückfrage geschaltet und Einstellungen nur nach Rückfrage geändert.
