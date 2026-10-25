<?php
// Erzeugt einen Einrichtungslink (30 Minuten gültig) für Benutzer, Passwort und TOTP.
// Ein neuer Zugang ersetzt den bisherigen – so lässt sich auch ein verlorenes Handy ersetzen.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../src/bootstrap.php';

$token = bin2hex(random_bytes(24));
db()->exec('DELETE FROM enroll_tokens');
db()->prepare('INSERT INTO enroll_tokens (token_hash, expires_at) VALUES (?, ?)')
    ->execute([hash('sha256', $token), time() + 1800]);
app_log('info', 'Einrichtungslink erzeugt');

echo "Einrichtungslink (30 Minuten gültig, nur einmal verwendbar):\n";
echo "https://ntfy.fbortz.de/?enroll=$token\n";
