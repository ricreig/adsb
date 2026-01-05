<?php
declare(strict_types=1);

function requireAuth(array $config): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    $serverAddr = $_SERVER['SERVER_ADDR'] ?? '';
    if (in_array($remoteAddr, ['127.0.0.1', '::1'], true) || ($serverAddr !== '' && $remoteAddr === $serverAddr)) {
        return;
    }
    $auth = $config['auth'] ?? [];
    if (empty($auth['enabled'])) {
        return;
    }
    $type = strtolower((string)($auth['type'] ?? 'basic'));
    if ($type === 'token') {
        $expected = (string)($auth['token'] ?? '');
        if ($expected === '') {
            return;
        }
        $provided = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/Bearer\s+(.+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
            $provided = trim($m[1]);
        } elseif (isset($_SERVER['HTTP_X_AUTH_TOKEN'])) {
            $provided = trim((string)$_SERVER['HTTP_X_AUTH_TOKEN']);
        } elseif (isset($_GET['token'])) {
            $provided = trim((string)$_GET['token']);
        }
        if (hash_equals($expected, $provided)) {
            return;
        }
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $user = (string)($auth['user'] ?? '');
    $pass = (string)($auth['pass'] ?? '');
    $providedUser = $_SERVER['PHP_AUTH_USER'] ?? null;
    $providedPass = $_SERVER['PHP_AUTH_PW'] ?? null;

    if ($providedUser === null && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Basic\s+(.+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
            $decoded = base64_decode($m[1], true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$providedUser, $providedPass] = explode(':', $decoded, 2);
            }
        }
    }

    if ($user !== '' && $pass !== '' && $providedUser !== null && $providedPass !== null) {
        if (hash_equals($user, $providedUser) && hash_equals($pass, $providedPass)) {
            return;
        }
    }

    header('WWW-Authenticate: Basic realm="ADSB"');
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_SLASHES);
    exit;
}
