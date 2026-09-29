<?php
defined('VG_ADMIN') || exit;
$cnt = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();
$villages = $cnt('SELECT COUNT(*) FROM vg_villages');
$active = $cnt('SELECT COUNT(*) FROM vg_villages WHERE last_seen > UNIX_TIMESTAMP() - 86400');
$blds = $cnt('SELECT COUNT(*) FROM vg_buildings');
$building = $cnt('SELECT COUNT(*) FROM vg_buildings WHERE build_finish IS NOT NULL');
$troops = $cnt('SELECT COALESCE(SUM(count), 0) FROM vg_village_units');
$vilCount = $cnt('SELECT COUNT(*) FROM vg_villagers');
$training = $cnt('SELECT COALESCE(SUM(total - done), 0) FROM vg_train_queue');
$recent = $pdo->query('SELECT v.name, v.user_name, l.t, l.msg FROM vg_logs l JOIN vg_villages v ON v.id = l.village_id
    WHERE l.kind <> \'crit\' ORDER BY l.id DESC LIMIT 15')->fetchAll();
?>
<section class="card">
  <h2>요약</h2>
  <div class="stats">
    <div><b><?= $villages ?></b><span>마을</span></div>
    <div><b><?= $active ?></b><span>24시간 내 접속</span></div>
    <div><b><?= $blds ?></b><span>건물</span></div>
    <div><b><?= $building ?></b><span>공사 중</span></div>
    <div><b><?= number_format($vilCount) ?></b><span>주민</span></div>
    <div><b><?= number_format($troops) ?></b><span>병력</span></div>
    <div><b><?= number_format($training) ?></b><span>훈련 대기</span></div>
  </div>
  <p class="muted">DB <?= h($VG_CONFIG['db_name']) ?> · 스키마 v<?= h(vg_meta_get($pdo, 'schema_version')) ?>
    · 기본값 v<?= h(vg_meta_get($pdo, 'defaults_version')) ?> · PHP <?= PHP_VERSION ?> · 서버 시각 <?= date('Y-m-d H:i:s') ?></p>
</section>
<section class="card">
  <h2>구현 단계</h2>
  <ol class="stages">
    <li class="done">기반: DB 자동 생성, SSO, 설정, 관리자 골격</li>
    <li class="done">마을: 자원 축적, 건설·철거·배치, 격자 확장, 아이소메트릭 화면</li>
    <li class="done">주민·병종·훈련·대장간 연구</li>
    <li class="done">육각 맵·이동</li>
    <li>합류·동맹·전투</li>
    <li>안개·시야·PvP 안전장치</li>
    <li>협동 몬스터</li>
  </ol>
</section>
<section class="card">
  <h2>최근 활동</h2>
  <table class="grid">
    <tr><th>시각</th><th>마을</th><th>내용</th></tr>
    <?php foreach ($recent as $r): ?>
      <tr><td class="nowrap"><?= date('m-d H:i', (int)$r['t']) ?></td><td><?= h($r['name']) ?> <small class="muted"><?= h($r['user_name']) ?></small></td><td><?= h($r['msg']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$recent): ?><tr><td colspan="3" class="muted">아직 활동이 없습니다.</td></tr><?php endif; ?>
  </table>
</section>
