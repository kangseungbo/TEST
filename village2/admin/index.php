<?php
const VG_ADMIN = true;
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/actions.php';

$user = vg_current_user();
if (!$user || !$user['admin']) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><p style="font-family:sans-serif;padding:2em">관리자만 접근할 수 있습니다. <a href="../">게임으로</a></p>';
    exit;
}

$TABS = [
    'summary'  => ['요약', null],
    'settings' => ['설정', null],
    'buildings' => ['건물', null],
    'units'    => ['병종', 3],
    'research' => ['연구', 3],
    'map'      => ['지형·맵', 4],
    'art'      => ['그림', null],
    'players'  => ['플레이어', null],
    'armies'   => ['부대·전투기록', 5],
    'events'   => ['이벤트(몬스터)', 7],
    'reset'    => ['초기화', null],
];
$tab = $_GET['tab'] ?? 'summary';
if (!isset($TABS[$tab])) $tab = 'summary';

// POST → 처리 후 같은 탭으로 리다이렉트 (새로고침 시 재전송 방지)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        vg_check_csrf($_POST['csrf'] ?? null);
        $msg = vg_admin_action((string)($_POST['do'] ?? ''), $_POST, $_FILES);
        $_SESSION['vg_flash'] = ['ok', $msg ?: '저장했습니다.'];
    } catch (VgError | VgAuthError $e) {
        $_SESSION['vg_flash'] = ['err', $e->getMessage()];
    } catch (Throwable $e) {
        error_log('[village2 admin] ' . $e);
        $_SESSION['vg_flash'] = ['err', '처리 중 오류: ' . $e->getMessage()];
    }
    $q = ['tab' => $tab];
    if (!empty($_POST['open'])) $q['open'] = $_POST['open'];
    header('Location: ?' . http_build_query($q) . (!empty($_POST['anchor']) ? '#' . rawurlencode($_POST['anchor']) : ''));
    exit;
}

$flash = $_SESSION['vg_flash'] ?? null;
unset($_SESSION['vg_flash']);
$csrf = vg_csrf_token();
$pdo = vg_db();
$names = vg_res_names();

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(vg_csrf_token()) . '">';
}

$cssv = filemtime(__DIR__ . '/../assets/css/admin.css');
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>마을 전략 관리자</title>
<link rel="icon" href="data:,">
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= $cssv ?>">
</head>
<body>
<header>
  <h1>마을 전략 · 관리자</h1>
  <a href="../">게임으로 ›</a>
</header>
<nav class="tabs">
  <?php foreach ($TABS as $k => [$label, $stage]): ?>
    <a href="?tab=<?= $k ?>" class="<?= $k === $tab ? 'on' : '' ?><?= $stage ? ' later' : '' ?>"><?= h($label) ?></a>
  <?php endforeach; ?>
</nav>
<?php if ($flash): ?><div class="flash <?= h($flash[0]) ?>"><?= h($flash[1]) ?></div><?php endif; ?>
<main>
<?php
$stage = $TABS[$tab][1];
if ($stage) {
    echo '<section class="card"><h2>' . h($TABS[$tab][0]) . '</h2><p class="muted">' . $stage . '단계에서 구현합니다.</p></section>';
} else {
    require __DIR__ . "/tab_$tab.php";
}
?>
</main>
</body>
</html>
