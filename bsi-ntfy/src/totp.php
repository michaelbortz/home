<?php
// TOTP nach RFC 6238 (SHA1, 6 Stellen, 30 s) – kompatibel mit Google Authenticator, Aegis, 2FAS usw.

declare(strict_types=1);

const B32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function totp_new_secret(): string
{
    $bytes = random_bytes(20);
    $bits = '';
    foreach (str_split($bytes) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= B32_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function base32_decode_str(string $b32): string
{
    $bits = '';
    foreach (str_split(strtoupper(rtrim($b32, '='))) as $c) {
        $i = strpos(B32_ALPHABET, $c);
        if ($i === false) {
            throw new InvalidArgumentException('Ungültiges Base32');
        }
        $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

function totp_code(string $secret, int $step): string
{
    $hash = hash_hmac('sha1', pack('J', $step), base32_decode_str($secret), true);
    $offset = ord($hash[19]) & 0x0f;
    $num = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16)
         | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string) ($num % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Liefert den gültigen Zeitschritt (±1 Schritt Toleranz) oder null. */
function totp_verify(string $secret, string $code, int $lastStep = 0): ?int
{
    // Eingefügte Codes enthalten oft Leerzeichen, Bindestriche oder Zeilenumbrüche
    $code = preg_replace('/\D+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return null;
    }
    $now = intdiv(time(), 30);
    foreach ([0, -1, 1] as $d) {
        $step = $now + $d;
        // Ein Code darf nur einmal verwendet werden
        if ($step > $lastStep && hash_equals(totp_code($secret, $step), $code)) {
            return $step;
        }
    }
    return null;
}

function totp_uri(string $secret, string $account, string $issuer): string
{
    return 'otpauth://totp/' . rawurlencode("$issuer:$account")
        . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}
