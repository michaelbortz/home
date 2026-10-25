<?php
// Weboberfläche: Login mit Passwort + TOTP, Einstellungen, Vorschau und Protokoll.

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/poller.php';

const ISSUER = 'BSI-Warnungen';
const IDLE_TIMEOUT = 1800;
const MAX_SESSION = 43200;

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function start_session(): void
{
    $dir = DATA_DIR . '/sessions';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) MAX_SESSION);
    session_save_path($dir);
    session_name('bsintfy');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function logged_in(): bool
{
    if (empty($_SESSION['uid'])) {
        return false;
    }
    $now = time();
    if ($now - ($_SESSION['last_seen'] ?? 0) > IDLE_TIMEOUT || $now - ($_SESSION['auth_at'] ?? 0) > MAX_SESSION) {
        $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
        session_regenerate_id(true);
        flash('Sitzung abgelaufen, bitte neu anmelden.');
        return false;
    }
    $_SESSION['last_seen'] = $now;
    return true;
}

function flash(string $msg, string $kind = 'info'): void
{
    $_SESSION['flash'][] = [$kind, $msg];
}

function redirect(string $to = '/'): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

function check_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Ungültiges Formular-Token. Bitte Seite neu laden.');
    }
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '?');
}

function login_blocked(): bool
{
    $since = time() - 900;
    $st = db()->prepare('SELECT COUNT(*) FROM login_failures WHERE ip = ? AND ts > ?');
    $st->execute([client_ip(), $since]);
    $perIp = (int) $st->fetchColumn();
    $st = db()->prepare('SELECT COUNT(*) FROM login_failures WHERE ts > ?');
    $st->execute([$since]);
    return $perIp >= 5 || (int) $st->fetchColumn() >= 30;
}

function enroll_token_valid(string $token): bool
{
    $st = db()->prepare('SELECT 1 FROM enroll_tokens WHERE token_hash = ? AND expires_at > ?');
    $st->execute([hash('sha256', $token), time()]);
    return (bool) $st->fetchColumn();
}

// ---------------------------------------------------------------- Aktionen

function handle_post(bool $auth): never
{
    check_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'login' && !$auth) {
        do_login();
    }
    if ($action === 'enroll') {
        do_enroll();
    }
    if (!$auth) {
        redirect();
    }

    switch ($action) {
        case 'save':
            $sev = (string) ($_POST['min_severity'] ?? '');
            if (isset(SEVERITIES[$sev])) {
                set_setting('min_severity', $sev);
            }
            set_setting('notify_updates', isset($_POST['notify_updates']) ? '1' : '0');
            set_setting('paused', isset($_POST['paused']) ? '1' : '0');
            set_setting('include_keywords', implode("\n", keyword_list((string) ($_POST['include_keywords'] ?? ''))));
            set_setting('exclude_keywords', implode("\n", keyword_list((string) ($_POST['exclude_keywords'] ?? ''))));
            $url = trim((string) ($_POST['ntfy_url'] ?? ''));
            if (preg_match('#^https://[a-z0-9.-]+(:\d+)?/?$#i', $url)) {
                set_setting('ntfy_url', rtrim($url, '/'));
            } else {
                flash('ntfy-Server muss eine https-Adresse ohne Pfad sein, z. B. https://ntfy.sh', 'error');
            }
            $topic = trim((string) ($_POST['ntfy_topic'] ?? ''));
            if ($topic === '' || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $topic)) {
                set_setting('ntfy_topic', $topic);
            } else {
                flash('Topic darf nur Buchstaben, Ziffern, - und _ enthalten (max. 64).', 'error');
            }
            if (isset($_POST['clear_token'])) {
                set_setting('ntfy_token', '');
            } elseif (($tok = trim((string) ($_POST['ntfy_token'] ?? ''))) !== '') {
                set_setting('ntfy_token', $tok);
            }
            app_log('info', 'Einstellungen geändert');
            flash('Einstellungen gespeichert.');
            break;

        case 'gen_topic':
            set_setting('ntfy_topic', 'bsi-' . bin2hex(random_bytes(12)));
            flash('Neuer Topic erzeugt. Bitte in der ntfy-App neu abonnieren.');
            break;

        case 'test':
            try {
                flash('Testnachricht verschickt: ' . send_test_advisory());
            } catch (Throwable $e) {
                flash('Test fehlgeschlagen: ' . $e->getMessage(), 'error');
            }
            break;

        case 'poll':
            set_time_limit(300);
            flash('Abruf: ' . poll());
            break;

        case 'logout':
            app_log('info', 'Abgemeldet');
            $_SESSION = [];
            session_regenerate_id(true);
            break;
    }
    redirect();
}

function do_login(): never
{
    if (login_blocked()) {
        flash('Zu viele Fehlversuche. Bitte 15 Minuten warten.', 'error');
        redirect();
    }
    $st = db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([trim((string) ($_POST['username'] ?? ''))]);
    $user = $st->fetch();
    // Auch ohne Benutzer einen Hash prüfen, damit die Antwortzeit nichts verrät
    $hash = $user['pw_hash'] ?? password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $pwOk = password_verify((string) ($_POST['password'] ?? ''), $hash);
    $step = ($user && $pwOk) ? totp_verify($user['totp_secret'], (string) ($_POST['code'] ?? ''), (int) $user['last_totp_step']) : null;

    if (!$user || !$pwOk || $step === null) {
        db()->prepare('INSERT INTO login_failures (ip, ts) VALUES (?, ?)')->execute([client_ip(), time()]);
        app_log('warn', 'Fehlgeschlagene Anmeldung von ' . client_ip());
        flash('Anmeldung fehlgeschlagen.', 'error');
        redirect();
    }
    db()->prepare('UPDATE users SET last_totp_step = ? WHERE id = ?')->execute([$step, $user['id']]);
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET pw_hash = ? WHERE id = ?')
            ->execute([password_hash((string) $_POST['password'], PASSWORD_DEFAULT), $user['id']]);
    }
    session_regenerate_id(true);
    $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'uid' => (int) $user['id'], 'auth_at' => time(), 'last_seen' => time()];
    app_log('info', 'Angemeldet von ' . client_ip());
    redirect();
}

function do_enroll(): never
{
    $token = (string) ($_POST['token'] ?? '');
    $secret = $_SESSION['enroll'][$token] ?? null;
    if (!enroll_token_valid($token) || !$secret) {
        flash('Einrichtungslink ungültig oder abgelaufen.', 'error');
        redirect();
    }
    $back = '/?enroll=' . rawurlencode($token);
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._@-]{3,64}$/', $username)) {
        flash('Benutzername: 3–64 Zeichen, Buchstaben, Ziffern, . _ @ -', 'error');
        redirect($back);
    }
    if (mb_strlen($password) < 12 || $password !== ($_POST['password2'] ?? '')) {
        flash('Passwort muss mindestens 12 Zeichen haben und zweimal gleich eingegeben werden.', 'error');
        redirect($back);
    }
    $step = totp_verify($secret, (string) ($_POST['code'] ?? ''));
    if ($step === null) {
        flash('Der Code aus der Authenticator-App stimmt nicht. Uhrzeit am Handy prüfen und erneut versuchen.', 'error');
        redirect($back);
    }
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM users');
    $pdo->exec('DELETE FROM enroll_tokens');
    $pdo->prepare('INSERT INTO users (username, pw_hash, totp_secret, last_totp_step, created_at) VALUES (?,?,?,?,?)')
        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $secret, $step, time()]);
    $pdo->commit();
    unset($_SESSION['enroll']);
    app_log('info', "Zugang für $username eingerichtet");
    flash('Zugang eingerichtet. Bitte jetzt anmelden (mit einem neuen Code).');
    redirect();
}

// ---------------------------------------------------------------- Seiten

function page(string $title, string $body): never
{
    $flash = '';
    foreach ($_SESSION['flash'] ?? [] as [$kind, $msg]) {
        $flash .= '<div class="flash ' . h($kind) . '">' . h($msg) . '</div>';
    }
    unset($_SESSION['flash']);
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<title>' . h($title) . '</title><link rel="stylesheet" href="/style.css"></head><body>'
        . '<main>' . $flash . $body . '</main></body></html>';
    exit;
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function page_login(): never
{
    $hasUser = (bool) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $body = '<h1>BSI-Warnungen</h1>';
    if (!$hasUser) {
        $body .= '<p class="card">Noch kein Zugang eingerichtet. Auf dem Server <code>php ~/public_html/ntfy/bin/enroll.php</code> ausführen und den angezeigten Link öffnen.</p>';
    } else {
        $body .= '<form method="post" class="card narrow">' . csrf_field() . '<input type="hidden" name="action" value="login">'
            . '<label>Benutzername<input name="username" autocomplete="username" required autofocus></label>'
            . '<label>Passwort<input type="password" name="password" autocomplete="current-password" required></label>'
            . '<label>Code aus der Authenticator-App<input name="code" autocomplete="off" data-otp required></label>'
            . '<button>Anmelden</button></form><script src="/otp.js"></script>';
    }
    page('Anmelden – BSI-Warnungen', $body);
}

function page_enroll(string $token): never
{
    if (!enroll_token_valid($token)) {
        flash('Einrichtungslink ungültig oder abgelaufen.', 'error');
        redirect();
    }
    $_SESSION['enroll'] = [$token => $_SESSION['enroll'][$token] ?? totp_new_secret()];
    $secret = $_SESSION['enroll'][$token];
    $uri = totp_uri($secret, 'ntfy.fbortz.de', ISSUER);
    $body = '<h1>Zugang einrichten</h1><form method="post" class="card narrow">' . csrf_field()
        . '<input type="hidden" name="action" value="enroll"><input type="hidden" name="token" value="' . h($token) . '">'
        . '<p>1. QR-Code mit einer Authenticator-App scannen (z. B. Aegis, 2FAS, Google Authenticator).</p>'
        . '<div id="qr" data-uri="' . h($uri) . '"></div>'
        . '<p class="muted">Oder manuell eingeben: <code class="secret">' . h(trim(chunk_split($secret, 4, ' '))) . '</code></p>'
        . '<p>2. Benutzername und Passwort festlegen, dann den aktuellen Code eingeben.</p>'
        . '<label>Benutzername<input name="username" autocomplete="username" required></label>'
        . '<label>Passwort (mind. 12 Zeichen)<input type="password" name="password" minlength="12" autocomplete="new-password" required></label>'
        . '<label>Passwort wiederholen<input type="password" name="password2" minlength="12" autocomplete="new-password" required></label>'
        . '<label>Code aus der App<input name="code" autocomplete="off" data-otp required></label>'
        . '<button>Einrichten</button></form>'
        . '<script src="/qrcode.min.js"></script><script src="/enroll.js"></script><script src="/otp.js"></script>';
    page('Einrichten – BSI-Warnungen', $body);
}

function page_dashboard(): never
{
    $s = fn(string $k) => setting($k);
    $filter = current_filter();
    $lastPoll = (int) $s('last_poll');

    $sevOptions = '';
    foreach (array_keys(SEVERITIES) as $sev) {
        $sevOptions .= '<option value="' . $sev . '"' . ($sev === $filter['min_severity'] ? ' selected' : '') . '>ab ' . $sev . '</option>';
    }

    $topic = $s('ntfy_topic');
    $subscribe = $topic === ''
        ? '<span class="bad">Kein Topic eingestellt – es werden keine Meldungen verschickt.</span>'
        : 'In der ntfy-App abonnieren: Server <code>' . h($s('ntfy_url')) . '</code>, Topic <code>' . h($topic) . '</code>';

    $rows = db()->query('SELECT * FROM advisories ORDER BY seen_at DESC, released_at DESC LIMIT 400')->fetchAll();
    $since = time() - 30 * 86400;
    $recent = array_filter($rows, fn($r) => $r['seen_at'] >= $since);
    $wouldMatch = count(array_filter($recent, fn($r) => advisory_matches($r, $filter)));

    $table = '';
    foreach (array_slice($rows, 0, 80) as $r) {
        $match = advisory_matches($r, $filter);
        $title = $r['url'] !== '' ? '<a href="' . h($r['url']) . '" rel="noreferrer" target="_blank">' . h($r['title']) . '</a>' : h($r['title']);
        $table .= '<tr class="' . ($match ? 'hit' : '') . '"><td>' . h(substr($r['released_at'], 0, 10)) . '</td>'
            . '<td><span class="sev ' . h($r['severity']) . '">' . h($r['severity']) . '</span></td>'
            . '<td>' . $title . ($r['version'] !== '1' ? ' <span class="muted">(Update v' . h($r['version']) . ')</span>' : '')
            . '<div class="muted small">' . h(mb_strimwidth($r['products'], 0, 160, '…')) . '</div></td>'
            . '<td>' . ($match ? '✓' : '') . '</td><td>' . ($r['sent'] ? '✓' : '') . '</td></tr>';
    }

    $log = '';
    foreach (db()->query('SELECT * FROM log ORDER BY ts DESC LIMIT 25') as $l) {
        $log .= '<tr><td>' . date('d.m. H:i', (int) $l['ts']) . '</td><td class="' . h($l['level']) . '">' . h($l['level']) . '</td><td>' . h($l['msg']) . '</td></tr>';
    }

    $body = '<header><h1>BSI-Warnungen</h1><form method="post">' . csrf_field()
        . '<input type="hidden" name="action" value="logout"><button class="link">Abmelden</button></form></header>'

        . '<section class="card"><h2>Status</h2>'
        . '<p>Letzter Abruf: ' . ($lastPoll ? date('d.m.Y H:i', $lastPoll) : 'noch nie') . ' – ' . h($s('last_poll_status') ?: '–') . '</p>'
        . ($s('paused') === '1' ? '<p class="bad">Meldungen sind pausiert.</p>' : '')
        . '<p>' . $subscribe . '</p>'
        . '<div class="row"><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="test"><button title="Schickt die neueste Warnung, die zum Filter passt">Testnachricht senden</button></form>'
        . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="poll"><button class="secondary">Jetzt abrufen</button></form></div></section>'

        . '<form method="post" class="card">' . csrf_field() . '<input type="hidden" name="action" value="save"><h2>Filter</h2>'
        . '<label>Risikostufe laut BSI<select name="min_severity">' . $sevOptions . '</select></label>'
        . '<label class="check"><input type="checkbox" name="notify_updates"' . ($filter['notify_updates'] ? ' checked' : '') . '> Auch bei Aktualisierungen bestehender Warnungen melden</label>'
        . '<label>Nur diese Produkte/Hersteller (ein Eintrag pro Zeile, leer = alle)'
        . '<textarea name="include_keywords" rows="7" placeholder="Fortinet&#10;Citrix&#10;Microsoft Exchange&#10;VMware">' . h($s('include_keywords')) . '</textarea></label>'
        . '<label>Ausschließen (ein Eintrag pro Zeile)<textarea name="exclude_keywords" rows="3" placeholder="Oracle Linux">' . h($s('exclude_keywords')) . '</textarea></label>'
        . '<p class="muted small">Verglichen wird mit Titel, Herstellern und Produktnamen der Warnung, als ganzes Wort und ohne Groß-/Kleinschreibung.</p>'
        . '<h2>Zustellung</h2>'
        . '<label>ntfy-Server<input name="ntfy_url" value="' . h($s('ntfy_url')) . '"></label>'
        . '<label>Topic<input name="ntfy_topic" value="' . h($topic) . '" autocomplete="off"></label>'
        . '<label>Access-Token (optional, leer lassen = unverändert)<input type="password" name="ntfy_token" autocomplete="off" placeholder="' . ($s('ntfy_token') !== '' ? '•••••• gesetzt' : 'tk_…') . '"></label>'
        . ($s('ntfy_token') !== '' ? '<label class="check"><input type="checkbox" name="clear_token"> Token löschen</label>' : '')
        . '<label class="check"><input type="checkbox" name="paused"' . ($s('paused') === '1' ? ' checked' : '') . '> Meldungen pausieren</label>'
        . '<div class="row"><button>Speichern</button></div></form>'
        . '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="action" value="gen_topic">'
        . '<button class="link">Zufälligen geheimen Topic erzeugen</button></form>'

        . '<section class="card"><h2>Vorschau</h2>'
        . '<p>Mit dem aktuellen Filter hätten in den letzten 30 Tagen <strong>' . $wouldMatch . '</strong> von ' . count($recent) . ' Warnungen eine Meldung ausgelöst. Gespeichert wird erst mit „Speichern“.</p>'
        . '<div class="scroll"><table><thead><tr><th>Stand</th><th>Risiko</th><th>Warnung</th><th>Filter</th><th>Gemeldet</th></tr></thead><tbody>'
        . ($table ?: '<tr><td colspan="5" class="muted">Noch keine Daten – „Jetzt abrufen“ drücken.</td></tr>') . '</tbody></table></div></section>'

        . '<section class="card"><h2>Protokoll</h2><div class="scroll"><table><tbody>' . ($log ?: '<tr><td class="muted">leer</td></tr>') . '</tbody></table></div></section>';
    page('BSI-Warnungen', $body);
}

// ---------------------------------------------------------------- Einstieg

function run_web(): never
{
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Strict-Transport-Security: max-age=31536000');
    header('Cache-Control: no-store');

    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        redirect('https://' . ($_SERVER['HTTP_HOST'] ?? 'ntfy.fbortz.de') . '/');
    }
    start_session();
    $auth = logged_in();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        handle_post($auth);
    }
    if (isset($_GET['enroll'])) {
        page_enroll((string) $_GET['enroll']);
    }
    $auth ? page_dashboard() : page_login();
}
