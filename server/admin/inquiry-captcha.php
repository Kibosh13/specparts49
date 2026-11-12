<?php
declare(strict_types=1);

if (!defined('SP49_ADMIN')) { http_response_code(404); exit; }

// ALTCHA v2 challenge format. Limit this issuer and verifier to PBKDF2/SHA-256.
const INQUIRY_CAPTCHA_ALGORITHM = 'PBKDF2/SHA-256';
const INQUIRY_CAPTCHA_COST = 4000;
const INQUIRY_CAPTCHA_COUNTER_MIN = 220;
const INQUIRY_CAPTCHA_COUNTER_MAX = 500;
const INQUIRY_CAPTCHA_TTL = 300;

function inquiry_captcha_key(bool $create = false): string {
    $file = data_dir().'/inquiry-captcha.key';
    if ($create && !is_file($file)) {
        locked(function () use ($file) {
            if (!is_file($file)) atomic_write($file, random_bytes(32));
        });
    }
    $key = is_file($file) ? file_get_contents($file) : false;
    if (!is_string($key) || strlen($key) !== 32) {
        fail('Проверка от спама временно недоступна. Обновите страницу и попробуйте ещё раз.', 503);
    }
    return $key;
}

function inquiry_captcha_signature(array $parameters, string $key): string {
    ksort($parameters);
    return hash_hmac('sha256', json_encode($parameters, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $key);
}

function inquiry_captcha_derive(string $nonce, string $salt, int $counter): string {
    return hash_pbkdf2('sha256', hex2bin($nonce).pack('N', $counter), hex2bin($salt), INQUIRY_CAPTCHA_COST, 64);
}

function inquiry_captcha_challenge(): array {
    $nonce = bin2hex(random_bytes(16));
    $salt = bin2hex(random_bytes(16));
    $counter = random_int(INQUIRY_CAPTCHA_COUNTER_MIN, INQUIRY_CAPTCHA_COUNTER_MAX);
    $parameters = [
        'algorithm' => INQUIRY_CAPTCHA_ALGORITHM,
        'cost' => INQUIRY_CAPTCHA_COST,
        'expiresAt' => time() + INQUIRY_CAPTCHA_TTL,
        'keyLength' => 32,
        'keyPrefix' => substr(inquiry_captcha_derive($nonce, $salt, $counter), 0, 32),
        'nonce' => $nonce,
        'salt' => $salt,
    ];
    return ['parameters' => $parameters, 'signature' => inquiry_captcha_signature($parameters, inquiry_captcha_key(true))];
}

function inquiry_captcha_verify(mixed $proof, array $rows): string {
    $error = 'Проверка от спама не пройдена. Обновите страницу и отправьте обращение ещё раз.';
    if (!is_string($proof) || strlen($proof) > 2048) fail($error, 403);
    $data = json_decode($proof, true, 8);
    $p = $data['challenge']['parameters'] ?? null;
    $signature = $data['challenge']['signature'] ?? null;
    $solution = $data['solution'] ?? null;
    $hex = static fn(mixed $value, int $length): bool =>
        is_string($value) && strlen($value) === $length && preg_match('/\A[0-9a-f]+\z/', $value) === 1;
    if (!is_array($p) || count($p) !== 7 || ($p['algorithm'] ?? '') !== INQUIRY_CAPTCHA_ALGORITHM ||
        ($p['cost'] ?? 0) !== INQUIRY_CAPTCHA_COST || ($p['keyLength'] ?? 0) !== 32 ||
        !is_int($p['expiresAt'] ?? null) || $p['expiresAt'] <= time() ||
        !$hex($p['nonce'] ?? null, 32) || !$hex($p['salt'] ?? null, 32) || !$hex($p['keyPrefix'] ?? null, 32) ||
        !$hex($signature, 64) || !is_array($solution) || !is_int($counter = $solution['counter'] ?? null) ||
        $counter < INQUIRY_CAPTCHA_COUNTER_MIN || $counter > INQUIRY_CAPTCHA_COUNTER_MAX ||
        !$hex($solution['derivedKey'] ?? null, 64) ||
        !hash_equals(inquiry_captcha_signature($p, inquiry_captcha_key()), $signature)) {
        fail($error, 403);
    }
    $key = inquiry_captcha_derive($p['nonce'], $p['salt'], $counter);
    if (!hash_equals($key, $solution['derivedKey']) || !hash_equals(substr($key, 0, 32), $p['keyPrefix'])) fail($error, 403);
    foreach ($rows as $row) {
        if (($row['captchaNonce'] ?? null) === $p['nonce']) fail($error, 403);
    }
    return $p['nonce'];
}
