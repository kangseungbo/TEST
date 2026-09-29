<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/defs.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/village.php';

date_default_timezone_set($VG_CONFIG['timezone'] ?? 'Asia/Seoul');

/** 사용자에게 그대로 보여줘도 되는 게임 규칙 오류 */
class VgError extends RuntimeException {}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function vg_now(): float
{
    return microtime(true);
}

/** 업로드 이미지 URL (없으면 null). 캐시 무효화용 ?v= 포함 */
function vg_image_url(string $key, string $base = ''): ?string
{
    $imgs = vg_images_all();
    if (!isset($imgs[$key])) return null;
    global $VG_CONFIG;
    return $base . $VG_CONFIG['upload_url'] . '/' . $imgs[$key]['path'] . '?v=' . (int)$imgs[$key]['updated_at'];
}

function vg_images_all(bool $reload = false): array
{
    static $cache = null;
    if ($cache !== null && !$reload) return $cache;
    $cache = [];
    foreach (vg_db()->query('SELECT ikey, path, updated_at FROM vg_images') as $r) $cache[$r['ikey']] = $r;
    return $cache;
}
