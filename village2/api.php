<?php
// 게임 JSON API. GET ?a=state, POST ?a=build|upgrade|cancel|demolish|move|rename (csrf 필수)
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function vg_api_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

try {
    $user = vg_require_user();
    $a = $_GET['a'] ?? 'state';
    $in = $_POST;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
    }
    $village = vg_village_for_user($user);
    $vid = (int)$village['id'];

    if ($a !== 'state') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') vg_api_out(['ok' => false, 'error' => 'POST 전용'], 405);
        vg_check_csrf($in['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null));
        switch ($a) {
            case 'build':    vg_act_build($vid, (string)($in['code'] ?? ''), (int)($in['slot'] ?? 0)); break;
            case 'upgrade':  vg_act_upgrade($vid, (int)($in['bid'] ?? 0)); break;
            case 'cancel':   vg_act_cancel($vid, (int)($in['bid'] ?? 0)); break;
            case 'demolish': vg_act_demolish($vid, (int)($in['bid'] ?? 0)); break;
            case 'move':     vg_act_move($vid, (int)($in['bid'] ?? 0), (int)($in['slot'] ?? 0)); break;
            case 'rename':   vg_act_rename($vid, (string)($in['name'] ?? '')); break;
            default: vg_api_out(['ok' => false, 'error' => '알 수 없는 요청'], 400);
        }
    }
    vg_api_out(['ok' => true, 'state' => vg_settle_and_state($vid)]);
} catch (VgError $e) {
    vg_api_out(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (VgAuthError $e) {
    vg_api_out(['ok' => false, 'error' => $e->getMessage(), 'auth' => true], 401);
} catch (Throwable $e) {
    error_log('[village2] ' . $e);
    vg_api_out(['ok' => false, 'error' => '서버 오류가 발생했습니다.'], 500);
}
