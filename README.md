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
| `pixoo64/pages.yaml` | Seiten der Divoom Pixoo 64 im Wohnzimmer (Uhr, Datum, Wetter, Temperaturen, Handys im WLAN, offene Fenster) |
| `automations/pixoo64-helligkeit.yaml` | Pixoo-Helligkeit nach Sonnenstand (1 % nachts, 30 % tagsüber, schaltet nie ein) |
| `automations/wassersensor.yaml` | Wasserleck-Meldung über ntfy mit höchster Priorität |
| `automations/steckdose-5-ausschalten.yaml` | Steckdose 5 nach eingestellter Zeit ausschalten, Meldung über ntfy |
| `automations/heizung/` | Heizungsautomationen und -skripte (Tag/Absenkung, Lüften, Fernbedienung, Tag-Werte sichern, Boost, Anpassungsfahrt des Ally) |
| `dashboards/` | Dashboards im Speichermodus als JSON, wie in Home Assistant gespeichert (`dashboard-test.json` = „Standard Dashboard“ mit Zuhause, Sensoren, Heizung; `map.json` = Karte). Die Übersicht ist automatisch erzeugt und hat keine eigene Konfiguration |
| `zigbee2mqtt/addon-options.yaml` | Add-on-Optionen von Zigbee2MQTT (Verbindung zum Zigbee-Dongle) |
| `bsi-ntfy/` | Beruflich: BSI-Sicherheitswarnungen (CERT-Bund WID) per ntfy, läuft auf dem Hetzner-Webhosting unter https://ntfy.fbortz.de, eigener Topic. Details in `bsi-ntfy/README.md` |
| `temp/` | Lokale Ablage für Logs und Ähnliches, wird nicht eingecheckt |

Wenn in Home Assistant etwas geändert wird, die passende Datei hier mit aktualisieren.

## Benachrichtigungen

- **Beide** über die ntfy-App (Pixel 8 Pro und FP5 haben dasselbe Topic abonniert). Integration „ntfy“ mit Server `https://ntfy.sh`, Entität `notify.handy_frau`. Senden mit `ntfy.publish` (Titel, Priorität 1–5, Tags). Das Topic steht in der Integration und in der ntfy-App. Es ist geheim, weil jeder, der es kennt, mitlesen und senden kann, deshalb steht es nicht hier.
- Die HA-App auf dem FP5 stellte nicht zuverlässig zu (meldet sich nur sporadisch). Deshalb seit 2026-10-24 ntfy. `notify.mobile_app_*` wird von keiner Automation mehr genutzt, und neue Meldungen sollen nur über ntfy gehen, sonst kommen sie bei Micha doppelt.
- **Neues Handy dazu:** ntfy-App aus dem Play Store installieren, über **+** das Topic mit Server `ntfy.sh` abonnieren und die Akku-Optimierung für ntfy ausschalten. In Home Assistant muss nichts geändert werden.
- **Prioritäten:** 5 (max) für Alarme wie Wasserleck, ohne Angabe 3 (default) für Hinweise wie die Steckdose. Wie laut eine Priorität ankommt, wird in der ntfy-App eingestellt.
- **Einrichtung:** Eingerichtet wurde per REST-API über den Config Flow (`/api/config/config_entries/flow` mit `handler: ntfy`, danach `subentries/flow` mit `add_topic`). In der Oberfläche geht es über **Einstellungen → Geräte & Dienste → ntfy → Thema hinzufügen**. ntfy.sh ohne Konto erlaubt 250 Nachrichten pro Tag und hält sie 12 Stunden vor (Sensoren `sensor.ntfy_sh_*`).
- **Testen ohne Alarm:** `ntfy.publish` direkt aufrufen, z. B. `POST /api/services/ntfy/publish` mit `{"entity_id": "notify.handy_frau", "title": "Test", "message": "…"}`. Die Automationen nicht auslösen, sonst geht eine falsche WASSERLECK-Meldung raus.

## Bekannte Probleme und Lösungen

### Aqara W600 (Schlafzimmer) übernahm keine niedrigeren Sollwerte (ersetzt am 2026-10-16)

**Symptom:** Home Assistant zeigt eine niedrigere Solltemperatur an (z. B. 5 °C bei offenem Fenster, 17 °C Absenkung), aber der W600 regelt weiter auf den alten Wert. Das Ventil bleibt offen, das Display zeigt den alten Sollwert. Das war auch das Problem bei der ersten Einbindung 2025.

**Was getestet ist (2026-10-14):**
- Zigbee2MQTT schreibt den Sollwert, und der W600 meldet ihn als `occupied_heating_setpoint` zurück. Trotzdem zeigt das Display weiter den alten Wert, und nach etwa 1 Minute regelt das Ventil wieder darauf.
- Das gilt für jede Absenkung, auch über der Raumtemperatur (21 → 19 °C bei 18,5 °C im Raum). Erhöhungen kommen an, ebenso **off** und das Wiedereinschalten.
- Mit dem Drehen am Gerät hat es nichts zu tun: Auch ein Wert, den Home Assistant gesetzt hat, wird nicht mehr abgesenkt.
- Ohne Wirkung: Moduswechsel off → heat, `temperature_setpoint_hold_duration` 65535 statt 1. `temperature_setpoint_hold` (steht auf `true`) lässt sich nicht schreiben („No converter available“).
- Die Firmware-Version meldet Zigbee2MQTT als unbekannt (-1). Updates gehen laut Aqara nur über ein Aqara-Gateway.

Beim alten Zigbee2MQTT (Edge-Add-on 2.7.2-dev) kam dazu, dass es den W600 nur unvollständig kannte (kein `system_mode`, kein `override_active`, keine Batterie). Seit 2026-10-13 läuft das stabile Add-on 2.14.2, siehe `zigbee2mqtt/addon-options.yaml`.

**Lösung (2026-10-16):** Der W600 wurde durch einen **Danfoss Ally** (014G2461, Zigbee) ersetzt, `climate.schlafzimmer_danfoss_ally`. Er ist direkt an Zigbee2MQTT angelernt, ohne App und ohne Gateway, und sitzt mit dem M30-×-1,5-Adapter auf dem Ventil. Fenster, Absenkung, Tagmodus und Tag-Werte-Sicherung steuern ihn wie die anderen Thermostate über Sollwerte. Die Notlösung mit „off“ und die Automation „W600 Drehen übernehmen“ sind entfernt, der W600 ist aus Zigbee2MQTT gelöscht.

**Hinweise zum Danfoss Ally:**
- In Home Assistant kennt er nur den Modus `heat`, also weder off noch Boost. Im Skript „Modus Tag“ bekommt er seinen Tageswert über die Sicherungsszene.
- Anlernen: Batterien rein, „o“ 3 s bis **M**, montieren, „o“ kurz (Kopplung), danach „o“ 3 s (Montage abschließen, Anpassungsfahrt). Fällt er nach dem Anlernen wieder raus, „o“ noch einmal kurz drücken und danach in Zigbee2MQTT die Konfiguration erneut anstoßen.
- Die automatische Anpassungsfahrt (sonst nachts) ist abgeschaltet (`switch.schlafzimmer_danfoss_ally_adaptation_run_settings`). Stattdessen löst `ally-anpassungsfahrt.yaml` sie sonntags um 14 Uhr aus.
- Nach dem Anlernen meldete er keine Änderungen von selbst; Home Assistant zeigte deshalb alte Sollwerte. Behoben am 2026-10-16, indem das Melden über `zigbee2mqtt/bridge/request/device/reporting/configure` (Cluster `hvacThermostat`, Endpunkt 1) eingerichtet wurde: `occupiedHeatingSetpoint` (min 0 s, max 3600 s, Änderung 50 = 0,5 °C), `localTemp` (300 s, 3600 s, 20 = 0,2 °C), `pIHeatingDemand` (300 s, 3600 s, 5 %). Nach erneutem Anlernen muss das wiederholt werden.
- Er verträgt laut Nutzerberichten nur etwa 650 Nachrichten pro Tag, also nicht ständig abfragen.

### Zigbee2MQTT startet nicht (HOST_FATAL_ERROR, ETIMEDOUT fe80::…)

**Ursache:** Zigbee2MQTT erreichte den SONOFF Dongle Max über eine IPv6-Link-Local-Adresse. Nach einem Neustart des Dongles war sie nicht mehr erreichbar.

**Lösung (2026-10-13):** Verbindung per IPv4 (`tcp://192.168.178.75:6638`) mit fester Adresse in der Fritzbox, siehe `zigbee2mqtt/addon-options.yaml`.

### Pixoo 64 nach Neustart mit 100 % Helligkeit und falscher Uhrzeit (2026-10-22)

**Symptom:** Der Pixoo leuchtete tagsüber mit 100 % statt 30 %, und die Uhrzeit stimmte eine Weile nicht.

**Ursache:** Am 2026-10-22 war `light.divoom_pixoo_64_light` fünfmal kurz `unavailable` (11:53, 13:54, 14:07, 15:02, 16:09 Ortszeit). Das sind Neustarts oder WLAN-Aussetzer. Nach einem Neustart steht der Pixoo auf 100 %, und die Uhr stimmt erst, wenn er wieder mit dem Divoom-Server (`app.divoom-gz.com`) verbunden ist. Die Helligkeitsautomation löste nur bei `off` → `on` aus, nach einem Neustart wechselt der Status aber von `unavailable` nach `on`.

**Lösung:** Der Trigger in `automations/pixoo64-helligkeit.yaml` reagiert jetzt auf `off` und `unavailable` → `on`. Die Helligkeit wurde von Hand auf 30 % gesetzt.

**PHP2Shell:** Am Vortag (2026-10-21) kamen die Notfall-Patches für PHP2Shell heraus (CVE-2026-109618/-109619, kritische RCE-Lücken in PHP). Der Pixoo selbst nutzt kein PHP. Möglich, aber nicht belegt ist, dass Divoom deswegen Server gepatcht hat und die Zeitsynchronisierung davon betroffen war. Eine Divoom-Störung war nicht gemeldet, und der Server antwortete am Nachmittag normal. Gehen die Ausfälle weiter, eher WLAN oder Netzteil des Pixoo prüfen.

## Was Claude hier tut

- Zustände von Entitäten abfragen und nicht erreichbare Geräte finden
- Fehlerprotokoll und Konfiguration prüfen
- Bei Automationen, Integrationen und YAML-Konfiguration helfen

Geräte werden nur nach Rückfrage geschaltet und Einstellungen nur nach Rückfrage geändert.
