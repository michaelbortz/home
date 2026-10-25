<?php
// Holt neue/aktualisierte WID-Advisories aus dem CSAF-Feed des BSI und meldet passende per ntfy.

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const MAX_DOCS_PER_RUN = 300;
const FIRST_RUN_DOCS = 150;
const SEEN_WINDOW_DAYS = 10;

/** @return string Kurzbericht für Cron-Ausgabe und Oberfläche */
function poll(): string
{
    $lock = fopen(DATA_DIR . '/poll.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return 'Abruf läuft bereits.';
    }
    try {
        $report = poll_locked();
        set_setting('last_poll_status', $report);
        return $report;
    } catch (Throwable $e) {
        $msg = 'Fehler: ' . $e->getMessage();
        set_setting('last_poll_status', $msg);
        app_log('error', $msg);
        return $msg;
    } finally {
        set_setting('last_poll', (string) time());
        flock($lock, LOCK_UN);
    }
}

function poll_locked(): string
{
    $headers = [];
    $lastModified = setting('changes_last_modified');
    if ($lastModified !== '') {
        $headers[] = 'If-Modified-Since: ' . $lastModified;
    }
    $res = http_request(CSAF_BASE . 'changes.csv', $headers);
    if ($res['status'] === 304) {
        return 'Keine Änderungen beim BSI.';
    }
    if ($res['status'] !== 200) {
        throw new RuntimeException("changes.csv lieferte HTTP {$res['status']}");
    }

    // Zeilen: "2026/wid-sec-w-2026-4118.json","2026-10-22T22:00:00+0000"
    $entries = [];
    foreach (preg_split('/\r?\n/', trim($res['body'])) as $line) {
        $row = str_getcsv($line, ',', '"', '');
        if (count($row) >= 2 && ($ts = strtotime($row[1])) !== false) {
            $entries[] = ['path' => $row[0], 'ts' => $ts];
        }
    }
    if (!$entries) {
        throw new RuntimeException('changes.csv ist leer oder unlesbar');
    }
    usort($entries, fn($a, $b) => $b['ts'] <=> $a['ts']);

    // Die Zeitstempel sind nur tagesgenau und Dokumente tauchen auch nachträglich mit älterem
    // Stempel auf. Deshalb jedes Paar (Pfad, Stempel) merken und ein Fenster von Tagen prüfen.
    $window = array_values(array_filter($entries, fn($e) => $e['ts'] >= time() - SEEN_WINDOW_DAYS * 86400));
    $seen = [];
    foreach (db()->query('SELECT path, ts FROM seen_paths') as $row) {
        $seen[$row['path'] . '|' . $row['ts']] = true;
    }
    $firstRun = !$seen;
    $unseen = array_values(array_filter($window, fn($e) => !isset($seen[$e['path'] . '|' . $e['ts']])));

    if ($firstRun) {
        // Beim ersten Lauf alles im Fenster als bekannt markieren, nur die neuesten für die
        // Vorschau einlesen und nichts verschicken
        $todo = array_slice($entries, 0, FIRST_RUN_DOCS);
        $markSeen = db()->prepare('INSERT OR IGNORE INTO seen_paths (path, ts) VALUES (?, ?)');
        foreach ($window as $e) {
            $markSeen->execute([$e['path'], $e['ts']]);
        }
    } else {
        $todo = $unseen;
        if (count($todo) > MAX_DOCS_PER_RUN) {
            app_log('warn', count($todo) . ' Änderungen, diesmal nur ' . MAX_DOCS_PER_RUN . ' verarbeitet, Rest folgt');
            $todo = array_slice($todo, 0, MAX_DOCS_PER_RUN);
        }
    }

    $new = $matched = $sent = 0;
    $markSeen = db()->prepare('INSERT OR IGNORE INTO seen_paths (path, ts) VALUES (?, ?)');
    foreach (array_reverse($todo) as $e) {
        try {
            $r = process_document($e['path'], !$firstRun);
        } catch (Throwable $ex) {
            // Nicht als gesehen markieren, damit es beim nächsten Lauf erneut versucht wird
            app_log('error', "{$e['path']}: " . $ex->getMessage());
            continue;
        }
        $markSeen->execute([$e['path'], $e['ts']]);
        $new += $r['new'];
        $matched += $r['matched'];
        $sent += $r['sent'];
    }

    if (isset($res['headers']['last-modified'])) {
        set_setting('changes_last_modified', $res['headers']['last-modified']);
    }
    prune();

    $report = $firstRun
        ? "Erster Abruf: $new Advisories eingelesen, keine Meldungen verschickt."
        : "$new neu/aktualisiert, $matched passend, $sent gemeldet.";
    if ($new > 0) {
        app_log('info', $report);
    }
    return $report;
}

/** @return array{new:int, matched:int, sent:int} */
function process_document(string $path, bool $notify): array
{
    if (!preg_match('#^\d{4}/[a-z0-9-]+\.json$#', $path)) {
        throw new RuntimeException('Unerwarteter Pfad');
    }
    $res = http_request(CSAF_BASE . $path);
    if ($res['status'] !== 200) {
        throw new RuntimeException("HTTP {$res['status']}");
    }
    $adv = parse_csaf(json_decode($res['body'], true, 64, JSON_THROW_ON_ERROR));

    $st = db()->prepare('SELECT 1 FROM advisories WHERE id = ? AND version = ?');
    $st->execute([$adv['id'], $adv['version']]);
    if ($st->fetchColumn()) {
        return ['new' => 0, 'matched' => 0, 'sent' => 0];
    }

    $isMatch = advisory_matches($adv, current_filter());
    $wasSent = false;
    if ($notify && $isMatch && setting('paused') !== '1' && setting('ntfy_topic') !== '') {
        try {
            send_advisory($adv);
            $wasSent = true;
        } catch (Throwable $e) {
            app_log('error', "{$adv['id']} nicht gemeldet: " . $e->getMessage());
        }
    }
    db()->prepare('INSERT INTO advisories (id, version, title, severity, products, summary, revision, cves, url,
                   released_at, seen_at, matched, sent) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$adv['id'], $adv['version'], $adv['title'], $adv['severity'], $adv['products'], $adv['summary'],
            $adv['revision'], implode(' ', $adv['cves']), $adv['url'], $adv['released_at'], time(),
            (int) $isMatch, (int) $wasSent]);
    return ['new' => 1, 'matched' => (int) $isMatch, 'sent' => (int) $wasSent];
}

function parse_csaf(array $d): array
{
    $doc = $d['document'] ?? throw new RuntimeException('Kein CSAF-Dokument');
    $tracking = $doc['tracking'];

    $summary = '';
    foreach ($doc['notes'] ?? [] as $n) {
        if (($n['category'] ?? '') === 'summary') {
            $summary = $n['text'];
            break;
        }
    }
    $url = '';
    foreach ($doc['references'] ?? [] as $r) {
        if (($r['category'] ?? '') === 'self' && str_contains($r['url'], '/portal/')) {
            $url = $r['url'];
        }
    }
    $history = $tracking['revision_history'] ?? [];
    $revision = $history ? (string) (end($history)['summary'] ?? '') : '';

    // Hersteller und Produktnamen aus dem Produktbaum (ohne einzelne Versionen)
    $names = [];
    $walk = function (array $branches) use (&$walk, &$names) {
        foreach ($branches as $b) {
            if (in_array($b['category'] ?? '', ['vendor', 'product_name', 'product_family'], true)) {
                $names[$b['name']] = true;
            }
            if (!empty($b['branches'])) {
                $walk($b['branches']);
            }
        }
    };
    $walk($d['product_tree']['branches'] ?? []);

    $cves = array_values(array_unique(array_filter(array_map(fn($v) => $v['cve'] ?? '', $d['vulnerabilities'] ?? []))));

    return [
        'id' => $tracking['id'],
        'version' => (string) $tracking['version'],
        'title' => $doc['title'],
        'severity' => strtolower($doc['aggregate_severity']['text'] ?? 'unbekannt'),
        'products' => implode(' | ', array_keys($names)),
        'summary' => $summary,
        'revision' => $revision,
        'cves' => $cves,
        'url' => $url,
        'released_at' => $tracking['current_release_date'] ?? '',
    ];
}

function current_filter(): array
{
    return [
        'min_severity' => setting('min_severity'),
        'notify_updates' => setting('notify_updates') === '1',
        'include' => keyword_list(setting('include_keywords')),
        'exclude' => keyword_list(setting('exclude_keywords')),
    ];
}

/** $adv braucht title, products, severity, version */
function advisory_matches(array $adv, array $f): bool
{
    if ((SEVERITIES[$adv['severity']] ?? 0) < (SEVERITIES[$f['min_severity']] ?? 3)) {
        return false;
    }
    if (!$f['notify_updates'] && $adv['version'] !== '1') {
        return false;
    }
    $haystack = $adv['title'] . ' | ' . $adv['products'];
    if ($f['exclude'] && keyword_hit($haystack, $f['exclude'])) {
        return false;
    }
    return !$f['include'] || keyword_hit($haystack, $f['include']);
}

/** Ganze Wörter, ohne Groß-/Kleinschreibung: "Go" trifft "Golang Go", aber nicht "Google". */
function keyword_hit(string $haystack, array $keywords): bool
{
    foreach ($keywords as $k) {
        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($k, '/') . '(?![\p{L}\p{N}])/iu', $haystack)) {
            return true;
        }
    }
    return false;
}

function send_advisory(array $adv): void
{
    $isUpdate = $adv['version'] !== '1';
    $lines = [];
    if ($isUpdate && $adv['revision'] !== '') {
        $lines[] = "Update: {$adv['revision']}";
    }
    if ($adv['summary'] !== '') {
        $lines[] = $adv['summary'];
    }
    if ($adv['cves']) {
        $cves = array_slice($adv['cves'], 0, 6);
        $more = count($adv['cves']) - count($cves);
        $lines[] = implode(', ', $cves) . ($more > 0 ? " (+$more)" : '');
    }
    $sev = strtoupper($adv['severity']);
    $msg = [
        'title' => ($isUpdate ? "[$sev · Update] " : "[$sev] ") . $adv['title'],
        'message' => implode("\n\n", $lines) ?: $adv['id'],
        'priority' => NTFY_PRIORITY[$adv['severity']] ?? 3,
        'tags' => [$adv['severity'] === 'kritisch' ? 'rotating_light' : 'warning', 'bsi'],
    ];
    if ($adv['url'] !== '') {
        $msg['click'] = $adv['url'];
        $msg['actions'] = [['action' => 'view', 'label' => 'Im WID öffnen', 'url' => $adv['url']]];
    }
    ntfy_send($msg);
}

function ntfy_send(array $msg): void
{
    $topic = setting('ntfy_topic');
    if ($topic === '') {
        throw new RuntimeException('Kein ntfy-Topic eingestellt');
    }
    $headers = ['Content-Type: application/json'];
    if (($token = setting('ntfy_token')) !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $body = json_encode(['topic' => $topic] + $msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $res = http_request(rtrim(setting('ntfy_url'), '/'), $headers, $body);
    if ($res['status'] !== 200) {
        throw new RuntimeException("ntfy lieferte HTTP {$res['status']}: " . substr($res['body'], 0, 200));
    }
}

function prune(): void
{
    db()->prepare('DELETE FROM advisories WHERE seen_at < ?')->execute([time() - 120 * 86400]);
    db()->prepare('DELETE FROM log WHERE ts < ?')->execute([time() - 60 * 86400]);
    db()->prepare('DELETE FROM login_failures WHERE ts < ?')->execute([time() - 86400]);
    db()->prepare('DELETE FROM seen_paths WHERE ts < ?')->execute([time() - 2 * SEEN_WINDOW_DAYS * 86400]);
}
