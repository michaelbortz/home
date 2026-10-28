# BSI-Warnungen per ntfy

Holt die Sicherheitswarnungen des BSI (CERT-Bund, Warn- und Informationsdienst WID) aus dem
CSAF-Feed, filtert sie nach Risikostufe und Produkten und schickt passende per ntfy aufs Handy.
Einstellen lässt sich alles unter https://ntfy.fbortz.de (Login mit Passwort und TOTP-Code).

## Wo läuft was

- **Hetzner-Webhosting** `www692.your-server.de`, ssh-Alias `hetzner-micha`. SSH läuft dort auf **Port 222**, auf Port 22 wird der Schlüssel abgelehnt. In `~/.ssh/config` stehen `Port 222` und der Schlüssel `hetzner-micha-openssh`, eine OpenSSH-Fassung von `hetzner-micha.ppk`. OpenSSH kann `.ppk` nicht lesen, PuTTY benutzt die `.ppk` direkt.
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
- Die Vorschau zeigt, wie viele Warnungen der letzten 30 Tage mit dem aktuellen Filter gepasst hätten. Die Checkbox „Nur passende anzeigen“ blendet die übrigen aus. Das geht nur über CSS (`:has`), weil die CSP kein Inline-Skript erlaubt. Die Tabelle zeigt sonst die neuesten 80 Warnungen. Mit Haken kommen ältere Treffer aus den letzten 400 dazu.

### Eingestellter Filter (seit 2026-10-28)

- **Ab „mittel“, Updates aus**, Einschlussliste mit der Software aus `D:\MichaArbeit\TODOS` (SERVER.md, README.md): Webserver, PHP-Stack, Datenbanken, Docker/Nextcloud, Mailserver, OpenSSH/OpenSSL, Java, Python, Tailscale, Windows und Bitwarden. Ausschlüsse: Red Hat, Google Linux, Google Chrome, Snipe-IT, IBM DB2, Docker Desktop, iOS, Android, SystemCenter.
- Der Test gegen alle 1.551 Warnungen vom 28.09. bis 27.10.2026 ergab 11 Meldungen. Ohne Filter wären es ab „mittel“ rund 1.470 gewesen.
- **Ubuntu, Debian, „Linux Kernel“ und „Windows“ nicht einschließen.** Das BSI führt die Distributionen bei fast jeder Warnung als betroffenes Produkt auf, damit passt praktisch alles. Getestet waren es ~700 statt 11.
- **Updates bleiben aus.** Bei „hoch“ waren es 97 Meldungen in 30 Tagen, davon nur 7 neue Warnungen. Der Rest sind Nachträge wie „Neue Updates von Ubuntu aufgenommen“ zu alten Warnungen (Apache v88, PHP v29). Neue Lücken bekommen beim BSI eine eigene Warnung.
- „Red Hat“ auszuschließen geht nur, solange Updates aus sind. In Updates stehen die Distributionen in der Produktliste, dann würden auch echte PHP- oder OpenSSH-Warnungen wegfallen.
- Bewusst nicht drin: Chrome und Firefox (aktualisieren sich selbst, fast wöchentlich eine Warnung), „Git“ (traf vor allem Red Hat OpenShift), „Java“ allein (traf Bouncy Castle und RabbitMQ, deshalb „Oracle Java SE“ und „OpenJDK“).

## Bedienung

- Deploy: `bash bsi-ntfy/deploy.sh` (spielt `src`, `bin`, `public` auf und setzt den Cronjob, `data/` bleibt)
- Zugang einrichten oder neu einrichten, z. B. bei neuem Handy: `ssh hetzner-micha php ~/public_html/ntfy/bin/enroll.php` und den Link innerhalb von 30 Minuten öffnen. Ein neuer Zugang ersetzt den alten.
- Manuell abrufen: `ssh hetzner-micha php ~/public_html/ntfy/bin/poll.php` oder „Jetzt abrufen“ in der Oberfläche
- Login-Schutz: 5 Fehlversuche pro IP bzw. 30 insgesamt in 15 Minuten sperren; jeder TOTP-Code gilt nur einmal; Sitzung endet nach 30 Minuten Inaktivität bzw. nach 12 Stunden
