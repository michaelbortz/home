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

### Aqara W600 (Schlafzimmer) übernimmt keine niedrigeren Sollwerte

**Symptom:** Home Assistant zeigt eine niedrigere Solltemperatur an (z. B. 5 °C bei offenem Fenster, 17 °C Absenkung), aber der W600 regelt weiter auf den alten Wert. Das Ventil bleibt offen, das Display zeigt den alten Sollwert. Das war auch das Problem bei der ersten Einbindung 2025.

**Was getestet ist (2026-10-14):**
- Zigbee2MQTT schreibt den Sollwert, und der W600 meldet ihn als `occupied_heating_setpoint` zurück. Trotzdem zeigt das Display weiter den alten Wert, und nach etwa 1 Minute regelt das Ventil wieder darauf.
- Das gilt für jede Absenkung, auch über der Raumtemperatur (21 → 19 °C bei 18,5 °C im Raum). Erhöhungen kommen an, ebenso **off** und das Wiedereinschalten.
- Mit dem Drehen am Gerät hat es nichts zu tun: Auch ein Wert, den Home Assistant gesetzt hat, wird nicht mehr abgesenkt.
- Ohne Wirkung: Moduswechsel off → heat, `temperature_setpoint_hold_duration` 65535 statt 1. `temperature_setpoint_hold` (steht auf `true`) lässt sich nicht schreiben („No converter available“).
- Die Firmware-Version meldet Zigbee2MQTT als unbekannt (-1). Updates gehen laut Aqara nur über ein Aqara-Gateway.

Beim alten Zigbee2MQTT (Edge-Add-on 2.7.2-dev) kam dazu, dass es den W600 nur unvollständig kannte (kein `system_mode`, kein `override_active`, keine Batterie). Seit 2026-10-13 läuft das stabile Add-on 2.14.2, siehe `zigbee2mqtt/addon-options.yaml`.

**Notlösung (2026-10-14):** Statt abzusenken wird der W600 **ausgeschaltet**: beim Öffnen des Fensters (`lueften-fenster.yaml`) und im Skript „Modus Absenkung“ (`script-modus-absenkung.yaml`, also nachts, im Sommermodus und mit Minus an der Fernbedienung). Der Tagmodus schaltet ihn über die Sicherungsszene wieder auf `heat` mit seinem Tageswert.

Die Automation „W600 Schlafzimmer: Drehen am Gerät übernehmen“ (`w600-dreh-uebernehmen.yaml`, off → heat nach jeder Änderung) beruhte auf einer falschen Annahme, hilft nicht und ist seit 2026-10-14 deaktiviert.

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
