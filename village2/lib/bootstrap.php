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
