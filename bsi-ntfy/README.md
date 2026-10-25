# BSI-Warnungen per ntfy

Holt die Sicherheitswarnungen des BSI (CERT-Bund, Warn- und Informationsdienst WID) aus dem
CSAF-Feed, filtert sie nach Risikostufe und Produkten und schickt passende per ntfy aufs Handy.
Einstellen lässt sich alles unter https://ntfy.fbortz.de (Login mit Passwort und TOTP-Code).

## Wo läuft was

- **Hetzner-Webhosting** `www692.your-server.de`, ssh-Alias `hetzner-micha`
- Code liegt in `~/public_html/ntfy` (= `/usr/www/users/aijnuy/ntfy`), die Domain zeigt auf `ntfy/public`
- `src/` Logik, `bin/` Cron und CLI, `public/` einziger Teil im Web, `data/` SQLite-DB und Sitzungen (nur auf dem Server, nicht im Repo)
- Cronjob alle 15 Minuten: `bin/poll.php`
- Die Zustellung läuft über **ntfy.sh** an einen zufälligen Topic. Auf Webhosting kann kein eigener ntfy-Server laufen.

## Datenquelle

- `https://wid.cert-bund.de/.well-known/csaf/white/changes.csv` wird mit `If-Modified-Since` abgerufen und liefert meist 304
- Die Zeitstempel dort sind nur tagesgenau, und Dokumente kommen auch nachträglich mit älterem Stempel. Deshalb merkt sich `seen_paths` jedes Paar (Pfad, Stempel) der letzten 10 Tage, statt nur „neuer als zuletzt“ zu prüfen.
- Das BSI liefert **keinen CVSS-Wert** im CSAF, nur die eigene Risikostufe (`aggregate_severity`: niedrig/mittel/hoch/kritisch). Danach wird gefiltert.
- Beim allerersten Abruf werden nur die neuesten 150 Dokumente für die Vorschau eingelesen, es wird nichts verschickt.

## Filter

- Mindest-Risikostufe; Updates bestehender Warnungen ein- oder ausschaltbar
- Stichwörter zum Einschließen und Ausschließen werden mit Titel, Herstellern und Produktnamen verglichen: ganzes Wort, ohne Groß-/Kleinschreibung („Go“ trifft nicht „Google“)
- Die Vorschau zeigt, wie viele Warnungen der letzten 30 Tage mit dem aktuellen Filter gepasst hätten

## Bedienung

- Deploy: `bash bsi-ntfy/deploy.sh` (spielt `src`, `bin`, `public` auf und setzt den Cronjob, `data/` bleibt)
- Zugang einrichten oder neu einrichten, z. B. bei neuem Handy: `ssh hetzner-micha php ~/public_html/ntfy/bin/enroll.php` und den Link innerhalb von 30 Minuten öffnen. Ein neuer Zugang ersetzt den alten.
- Manuell abrufen: `ssh hetzner-micha php ~/public_html/ntfy/bin/poll.php` oder „Jetzt abrufen“ in der Oberfläche
- Login-Schutz: 5 Fehlversuche pro IP bzw. 30 insgesamt in 15 Minuten sperren; jeder TOTP-Code gilt nur einmal; Sitzung endet nach 30 Minuten Inaktivität bzw. nach 12 Stunden
