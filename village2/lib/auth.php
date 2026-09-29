<?php
// 포털 SSO 연동. 포털의 getSessionUser() 결과를 id/name/admin 형태로 정규화한다.

class VgAuthError extends RuntimeException {}

function vg_current_user(): ?array
{
    static $done = false, $user = null;
    if ($done) return $user;
    $done = true;
    global $VG_CONFIG;

    $raw = null;
    if (is_file($VG_CONFIG['auth_file'])) {
        require_once $VG_CONFIG['auth_file'];
        if (session_status() === PHP_SESSION_NONE) @session_start();
        if (function_exists('getSessionUser')) $raw = getSessionUser();
    } elseif (!empty($VG_CONFIG['dev_user'])) {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $raw = $VG_CONFIG['dev_user'];
    }
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!$raw) return null;
    if (is_object($raw)) $raw = get_object_vars($raw);
    if (!is_array($raw)) return null;

    $id = $raw['id'] ?? $raw['user_id'] ?? $raw['uid'] ?? $raw['username'] ?? $raw['login'] ?? null;
    if ($id === null || $id === '') return null;
    $login = (string)($raw['username'] ?? $raw['login'] ?? $raw['login_id'] ?? $id);
    $name = (string)($raw['name'] ?? $raw['display_name'] ?? $raw['nickname'] ?? $login);

    $admins = array_map('strval', $VG_CONFIG['admins'] ?? []);
    $isAdmin = !empty($raw['admin']) || !empty($raw['is_admin'])
        || (isset($raw['role']) && strtolower((string)$raw['role']) === 'admin')
        || in_array((string)$id, $admins, true) || in_array($login, $admins, true);

    $user = ['id' => (string)$id, 'login' => $login, 'name' => $name, 'admin' => $isAdmin];
    return $user;
}

function vg_require_user(): array
{
    $u = vg_current_user();
    if (!$u) throw new VgAuthError('로그인이 필요합니다.');
    return $u;
}

function vg_csrf_token(): string
{
    if (empty($_SESSION['vg_csrf'])) $_SESSION['vg_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['vg_csrf'];
}

function vg_check_csrf(?string $t): void
{
    if (!$t || empty($_SESSION['vg_csrf']) || !hash_equals($_SESSION['vg_csrf'], $t)) {
        throw new VgAuthError('보안 토큰이 맞지 않습니다. 새로고침 후 다시 시도하세요.');
    }
}
