# Plan: Cloud-Agent auf Hetzner

Stand: 2026-11-02 · Status: Planung

## Ziel

Ein Claude-Code-Agent läuft dauerhaft auf einem Hetzner-Cloud-Server. Er ist auch dann erreichbar,
wenn mein Rechner aus ist. Unterwegs kann ich ihn vom Handy aus (Claude-App, Remote Control) die
Firmenserver und Arbeitsrechner prüfen lassen und bei Bedarf Probleme beheben, ohne erst den Laptop
aufzuklappen.

Langfristig wandert auch der letzte Rechner im Büro in die Cloud. Der Agent-Server ist dann einfach
ein Teil dieser Infrastruktur.

## Entscheidungen

| Thema | Entscheidung | Begründung |
|---|---|---|
| Servertyp | **CAX21** (ARM, 4 vCPU / 8 GB) | Mehr Reserve als der CPX12 (x86, AMD) für wenig Aufpreis. ARM ist für Docker, Node und Claude Code kein Problem. Der CPX12 würde nur für den Agent allein reichen. |
| Eigentümer | **Firma**: Hetzner-Projekt, Claude-Zugang (Team-Plan oder API-Key), Tailnet | Nichts hängt an meinen privaten Konten. Beim Ausscheiden lässt sich alles übergeben oder entfernen. |
| Private Projekte (Paperless o. ä.) | **Getrennt**, eigenes privates Hetzner-Projekt bzw. eigener Account | Private und Firmendaten bleiben getrennt. |
| Netzwerk | **Tailscale** (gibt es schon für die internen Rechner) | Keine offenen Ports, Zugriff über ACLs steuerbar, Not-Aus mit einem Klick. |
| Anmeldung auf den Zielen | **Linux: Tailscale SSH** (keine SSH-Schlüssel) · **Windows: OpenSSH mit eigenem Agent-Schlüssel** | Tailscale SSH funktioniert auf Windows nicht als Ziel. |
| Alarmierung | **ntfy** (gibt es in der Firma schon) + Monitoring von außen | Der Agent merkt selbst nicht, dass etwas kaputt ist. |

## Architektur

```
Monitoring ──Alarm──▶ ntfy ──▶ Handy
                                 │
                       Claude-App / claude.ai (Remote Control)
                                 │
                                 ▼
Hetzner CAX21 (tag:agent, keine offenen Ports)
  └─ Claude Code in tmux, User "ops"
        │ Tailscale SSH (Linux) / OpenSSH (Windows), per ACL begrenzt
        ▼
  tag:servers (Cloud-Server + Büro-Rechner)
        └─ User "ops-agent" mit eingeschränktem sudo
```

## Sicherheit

### Agent-Server
- Hetzner-Firewall: **alle eingehenden Verbindungen gesperrt**, auch Port 22. Zugriff nur über Tailscale.
  Für Notfälle bleiben die Hetzner-Konsole und das Rescue-System.
- Automatische Sicherheitsupdates (`unattended-upgrades`).
- Claude Code läuft als eigener User `ops`, nicht als root.
- Der Server ist der wertvollste Rechner im Netz. Dort liegen Zugänge ohne Passwort.

### Tailscale
- Der Server kommt mit einem **getaggten Auth-Key** ins Tailnet (`tag:agent`). Damit gehört das Gerät dem
  Tailnet und nicht meinem Account, und sein Schlüssel läuft nicht ab.
- Die Zielrechner bekommen `tag:servers`.
- Für die Agent-Regel `"action": "accept"` verwenden, nicht `"check"`. `check` verlangt eine Anmeldung im Browser.
- Optional: Tailscale Session Recording, um nachvollziehen zu können, was der Agent getan hat.

ACL-Entwurf (Tailnet-Policy):

```jsonc
"tagOwners": {
  "tag:agent":   ["autogroup:admin"],
  "tag:servers": ["autogroup:admin"],
},
"grants": [
  // Agent darf nur auf Server, nur SSH (ggf. weitere Ports für Healthchecks)
  { "src": ["tag:agent"], "dst": ["tag:servers"], "ip": ["tcp:22"] },
],
"ssh": [
  // Tailscale SSH: Agent meldet sich als ops-agent an, ohne SSH-Keys
  { "action": "accept", "src": ["tag:agent"], "dst": ["tag:servers"], "users": ["ops-agent"] },
],
```

Wenn die Policy noch das ältere `acls`-Format nutzt, den `grants`-Eintrag entsprechend als `acls`-Regel anlegen.

### Zielrechner
- Eigener User **`ops-agent`**, damit jede Aktion im Log als Agent erkennbar ist.
- **Linux:** `tailscale up --ssh`, sudo nur für ausgewählte Befehle in `/etc/sudoers.d/ops-agent`.
- **Windows:** OpenSSH-Server, Schlüssel `claude-agent@hetzner-ops` mit Herkunfts-Einschränkung:
  ```
  from="<Tailscale-IP des Agent-Servers>" ssh-ed25519 AAAA... claude-agent@hetzner-ops
  ```

### Berechtigungen für Claude Code (`.claude/settings.json` auf dem Server)
| Stufe | Befehle |
|---|---|
| Ohne Rückfrage | `docker ps`, `docker logs`, `df`, `free`, `journalctl`, `systemctl status`, Config-Dateien lesen |
| Mit Rückfrage | `docker compose restart`, `systemctl restart`, Config-Dateien ändern |
| Gesperrt | `rm -rf`, Volumes löschen, Firewall-/SSH-Config ändern, Backups anfassen |

Kein Bypass-Permission-Modus.

## Umsetzung

### Phase 1: Agent-Server
- [ ] Hetzner-Projekt auf die Firma anlegen
- [ ] CAX21 anlegen (Debian/Ubuntu), Firewall: alle eingehenden Verbindungen sperren
- [ ] Getaggten Auth-Key (`tag:agent`) erzeugen, `tailscale up --auth-key=... --advertise-tags=tag:agent --ssh`
- [ ] User `ops` anlegen, Node.js und Claude Code installieren
- [ ] Claude-Login über den Firmen-Zugang
- [ ] tmux-Session mit Autostart (systemd-Unit)
- [ ] Remote Control einrichten und vom Handy testen. Vorher in der aktuellen Doku prüfen, wie es mit einer headless Session funktioniert.
- [ ] `.claude/settings.json` mit Allow-/Ask-/Deny-Listen

### Phase 2: Zielrechner
- [ ] Tags `tag:servers` vergeben, ACL-Block in die Tailnet-Policy einfügen
- [ ] Linux: User `ops-agent`, sudoers-Datei, `tailscale up --ssh`
- [ ] Windows: OpenSSH-Server, User `ops-agent`, Agent-Schlüssel mit `from=`
- [ ] Vom Agent-Server aus testen: Lesen klappt, gesperrte Befehle werden abgelehnt

### Phase 3: Alarmierung
- [ ] Monitoring von außen (z. B. Uptime Kuma auf einem anderen Rechner oder ein externer Dienst) → ntfy
- [ ] Testalarm → Handy → Claude-App → Diagnose durch den Agent

### Phase 4 (optional): Agent reagiert selbst
- [ ] Monitoring-Webhook startet `claude -p "Dienst X ist down, diagnostizieren und Ergebnis per ntfy melden"`
- [ ] Nur Diagnose, Reparaturen weiter erst nach meiner Bestätigung
- [ ] Dafür wäre ein API-Key der Firma besser als das Abo

## Offboarding / Not-Aus

**Not-Aus (sofort):** Den Agent-Server in der Tailscale-Admin-Konsole entfernen. Dann kommt er nirgends mehr hin.

**Komplett entfernen:**
1. Agent-Server aus dem Tailnet entfernen, ACL-Regeln für `tag:agent` löschen
2. Auf allen Zielen User `ops-agent` und `/etc/sudoers.d/ops-agent` löschen
3. Windows: Zeile `claude-agent@hetzner-ops` aus den `authorized_keys` entfernen
4. Hetzner-Server löschen, Claude-Zugang bzw. API-Key widerrufen

## Offene Punkte
- [ ] Welche Zielrechner sind Linux, welche Windows? Davon hängen die Skripte ab.
- [ ] Welcher Claude-Zugang für die Firma: Team-Plan oder API-Key?
- [ ] Wo läuft das Monitoring von außen?
- [ ] Skripte schreiben: Setup Agent-Server, Setup Ziel (+ Gegenstück zum Entfernen), ACL-Block
