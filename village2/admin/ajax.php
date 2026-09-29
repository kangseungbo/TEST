<?php
// 관리자 맵 편집용 JSON 엔드포인트 (클릭 한 번마다 저장)
const VG_ADMIN = true;
require __DIR__ . '/../lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $user = vg_current_user();
    if (!$user || !$user['admin']) throw new VgAuthError('관리자만 사용할 수 있습니다.');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new VgError('POST 전용');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    vg_check_csrf($in['csrf'] ?? null);
    $q = (int)($in['q'] ?? 0);
    $r = (int)($in['r'] ?? 0);
    switch ($in['do'] ?? '') {
        case 'paint':
            vg_admin_map_paint($q, $r, $in['terrain'] ?? null, $in['feature'] ?? null);
            break;
        case 'move_village':
            vg_map_move_village((int)($in['vid'] ?? 0), $q, $r);
            break;
        default:
            throw new VgError('알 수 없는 요청');
    }
    echo json_encode(['ok' => true, 'map' => vg_state_map()], JSON_UNESCAPED_UNICODE);
} catch (VgError | VgAuthError $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[village2 admin ajax] ' . $e);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '서버 오류'], JSON_UNESCAPED_UNICODE);
}

/** 타일 하나 칠하기. 마을 타일은 지형·특수 지점을 바꿀 수 없다 */
function vg_admin_map_paint(int $q, int $r, ?string $terrain, ?string $feature): void
{
    $t = vg_map_tiles()[vg_tkey($q, $r)] ?? null;
    if (!$t) throw new VgError('맵 밖입니다.');
    if ($t['v'] !== null) throw new VgError('마을이 있는 타일입니다. 마을을 먼저 옮기세요.');
    $pdo = vg_db();
    if ($terrain !== null) {
        if (!isset(vg_tdefs()[$terrain])) throw new VgError('알 수 없는 지형');
        $pdo->prepare('UPDATE vg_map_tiles SET terrain = ? WHERE q = ? AND r = ?')->execute([$terrain, $q, $r]);
        // 강이 아니게 되면 다리는 의미 없음
        if ($terrain !== 'river' && $t['f'] === 'bridge') $pdo->prepare("UPDATE vg_map_tiles SET feature = '' WHERE q = ? AND r = ?")->execute([$q, $r]);
    }
    if ($feature !== null) {
        if ($feature !== '' && (!isset(vg_map_features()[$feature]) || $feature === 'village')) throw new VgError('알 수 없는 특수 지점');
        if ($feature === 'bridge' && ($terrain ?? $t['t']) !== 'river') throw new VgError('다리는 강 타일에만 놓을 수 있습니다.');
        $pdo->prepare('UPDATE vg_map_tiles SET feature = ? WHERE q = ? AND r = ?')->execute([$feature, $q, $r]);
    }
    vg_map_tiles(true);
    vg_map_bump();
}
