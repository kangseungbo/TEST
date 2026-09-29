<?php
require __DIR__ . '/lib/bootstrap.php';

$user = null;
$fatal = null;
try {
    $user = vg_current_user();
    if ($user) vg_db(); // 첫 실행 시 DB·스키마 생성
} catch (Throwable $e) {
    error_log('[village2] ' . $e);
    $fatal = 'DB 연결 또는 초기화에 실패했습니다. config.local.php 의 DB 설정을 확인하세요.';
}
$v = max(array_map('filemtime', glob(__DIR__ . '/assets/{js,css}/*.{js,css}', GLOB_BRACE)));
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>마을 전략</title>
<link rel="icon" href="data:,">
<link rel="stylesheet" href="assets/css/game.css?v=<?= h($v) ?>">
<link rel="stylesheet" href="assets/css/art.css?v=<?= h($v) ?>">
</head>
<body>
<?php if (!$user || $fatal): ?>
  <div class="gate">
    <div class="parch card">
      <h1>마을 전략</h1>
      <?php if ($fatal): ?>
        <p><?= h($fatal) ?></p>
      <?php else: ?>
        <p>포털에 로그인한 뒤 이용할 수 있습니다.</p>
        <?php if (!empty($VG_CONFIG['login_url'])): ?><p><a class="btn" href="<?= h($VG_CONFIG['login_url']) ?>">포털로 이동</a></p><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>
  <header class="topbar parch">
    <div class="vname">
      <button class="linkish" id="vname" title="마을 이름 바꾸기">…</button>
      <span class="hall" id="hallinfo"></span>
    </div>
    <div class="resbar" id="resbar"></div>
    <nav class="tabs">
      <button data-tab="village" class="on">마을</button>
      <button data-tab="people">주민</button>
      <button data-tab="army">병력</button>
      <button data-tab="research">연구</button>
      <button data-tab="map">세계 맵</button>
      <button data-tab="log">기록</button>
      <?php if ($user['admin']): ?><a href="admin/" class="adminlink">관리자</a><?php endif; ?>
    </nav>
  </header>

  <main>
    <section id="tab-village" class="tab on">
      <div class="stage" id="stage">
        <svg id="vsvg" xmlns="http://www.w3.org/2000/svg"><g id="world"></g></svg>
        <div class="camctl">
          <button id="zin" title="확대">＋</button>
          <button id="zout" title="축소">－</button>
          <button id="zfit" title="맞춤">맞춤</button>
        </div>
        <div class="stagebar">
          <span id="buildinfo"></span>
          <button class="btn small" id="wallbtn">성벽</button>
        </div>
        <div class="movehint" id="movehint" hidden>
          옮길 칸을 누르세요 (건물이 있으면 맞바꿈) <button class="btn small" id="movecancel">취소</button>
        </div>
      </div>
      <aside class="panel parch" id="panel" hidden>
        <button class="close" id="panelclose" aria-label="닫기">×</button>
        <div id="panelbody"></div>
      </aside>
    </section>

    <section id="tab-people" class="tab pane"></section>
    <section id="tab-army" class="tab pane"></section>
    <section id="tab-research" class="tab pane"></section>

    <section id="tab-map" class="tab">
      <div class="parch card soon">세계 맵은 4단계에서 열립니다.</div>
    </section>

    <section id="tab-log" class="tab">
      <div class="parch card"><h2>마을 기록</h2><ul class="logs" id="logs"></ul></div>
    </section>
  </main>

  <div class="toasts" id="toasts"></div>

  <script>
    window.VG_BOOT = <?= json_encode(['csrf' => vg_csrf_token(), 'user' => ['name' => $user['name']]], JSON_UNESCAPED_UNICODE) ?>;
  </script>
  <script src="assets/js/camera.js?v=<?= h($v) ?>"></script>
  <script src="assets/js/art.js?v=<?= h($v) ?>"></script>
  <script src="assets/js/tabs.js?v=<?= h($v) ?>"></script>
  <script src="assets/js/village.js?v=<?= h($v) ?>"></script>
<?php endif; ?>
</body>
</html>
