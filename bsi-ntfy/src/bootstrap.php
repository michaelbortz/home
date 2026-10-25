<?php
// Gemeinsame Basis für Weboberfläche und Cron: Datenbank, Einstellungen, HTTP, Log.

declare(strict_types=1);

date_default_timezone_set('Europe/Berlin');

const APP_DIR  = __DIR__ . '/..';
const DATA_DIR = APP_DIR . '/data';
const CSAF_BASE = 'https://wid.cert-bund.de/.well-known/csaf/white/';

// Risikostufen des BSI (document.aggregate_severity.text) und ntfy-Prioritäten
const SEVERITIES = ['niedrig' => 1, 'mittel' => 2, 'hoch' => 3, 'kritisch' => 4];
const NTFY_PRIORITY = ['niedrig' => 2, 'mittel' => 3, 'hoch' => 4, 'kritisch' => 5];

const DEFAULT_SETTINGS = [
    'min_severity'     => 'hoch',
    'notify_updates'   => '1',
    'include_keywords' => '',
    'exclude_keywords' => '',
    'ntfy_url'         => 'https://ntfy.sh',
    'ntfy_topic'       => '',
    'ntfy_token'       => '',
    'paused'           => '0',
];

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0700, true);
    }
    $pdo = new PDO('sqlite:' . DATA_DIR . '/app.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY, username TEXT UNIQUE NOT NULL, pw_hash TEXT NOT NULL,
            totp_secret TEXT NOT NULL, last_totp_step INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS enroll_tokens (token_hash TEXT PRIMARY KEY, expires_at INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS login_failures (ip TEXT NOT NULL, ts INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS advisories (
            id TEXT NOT NULL, version TEXT NOT NULL, title TEXT NOT NULL, severity TEXT NOT NULL,
            products TEXT NOT NULL, summary TEXT NOT NULL, revision TEXT NOT NULL, cves TEXT NOT NULL,
            url TEXT NOT NULL, released_at TEXT NOT NULL, seen_at INTEGER NOT NULL,
            matched INTEGER NOT NULL, sent INTEGER NOT NULL, PRIMARY KEY (id, version));
        CREATE TABLE IF NOT EXISTS log (ts INTEGER NOT NULL, level TEXT NOT NULL, msg TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS seen_paths (path TEXT NOT NULL, ts INTEGER NOT NULL, PRIMARY KEY (path, ts));
        SQL);
    return $pdo;
}

function setting(string $key): string
{
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? (DEFAULT_SETTINGS[$key] ?? '') : $v;
}

function set_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}

function app_log(string $level, string $msg): void
{
    db()->prepare('INSERT INTO log (ts, level, msg) VALUES (?, ?, ?)')->execute([time(), $level, $msg]);
}

/** @return array{status:int, body:string, headers:array<string,string>} */
function http_request(string $url, array $headers = [], ?string $body = null): array
{
    $respHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'bsi-ntfy (fbortz.de)',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$respHeaders) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $res = curl_exec($ch);
    if ($res === false) {
        $err = curl_error($ch);
        throw new RuntimeException("HTTP-Fehler bei $url: $err");
    }
    return ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => (string) $res, 'headers' => $respHeaders];
}

/** Stichwortliste aus dem Textfeld: eine Zeile oder ein Komma pro Eintrag. */
function keyword_list(string $raw): array
{
    $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
    return array_values(array_filter(array_map('trim', $parts), fn($k) => $k !== ''));
}
