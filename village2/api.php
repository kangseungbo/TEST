<?php
// 게임 JSON API. GET ?a=state, POST ?a=행동 (csrf 필수)
// 행동: build upgrade cancel demolish move rename / hire fire assign / train train_cancel disband / research research_cancel
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
            case 'hire':     vg_act_hire($vid); break;
            case 'fire':     vg_act_fire($vid, (int)($in['vil'] ?? 0)); break;
            case 'assign':   vg_act_assign($vid, (int)($in['vil'] ?? 0), (int)($in['bid'] ?? 0)); break;
            case 'train':    vg_act_train($vid, (string)($in['unit'] ?? ''), (int)($in['count'] ?? 0)); break;
            case 'train_cancel': vg_act_train_cancel($vid, (int)($in['qid'] ?? 0)); break;
            case 'disband':  vg_act_disband($vid, (string)($in['unit'] ?? ''), (int)($in['count'] ?? 0)); break;
            case 'research': vg_act_research($vid, (string)($in['code'] ?? '')); break;
            case 'research_cancel': vg_act_research_cancel($vid, (string)($in['code'] ?? '')); break;
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
