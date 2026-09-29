<?php
defined('VG_ADMIN') || exit;
vg_armies_resolve_due();
$now = vg_now();
$udefs = vg_udefs();
$vn = [];
foreach ($pdo->query('SELECT id, name, user_name FROM vg_villages') as $r) $vn[(int)$r['id']] = $r;
$armies = [];
foreach ($pdo->query('SELECT * FROM vg_armies ORDER BY village_id, id') as $a) $armies[(int)$a['id']] = vg_army_norm($a);
if ($armies) {
    foreach ($pdo->query('SELECT * FROM vg_army_units WHERE army_id IN (' . implode(',', array_keys($armies)) . ')') as $u) {
        $armies[(int)$u['army_id']]['units'][] = ['owner' => (int)$u['owner_village_id'], 'code' => $u['unit_code'], 'count' => (int)$u['count']];
    }
}
$fmtDur = fn(float $s) => sprintf('%d:%02d:%02d', intdiv((int)$s, 3600), intdiv((int)$s % 3600, 60), (int)$s % 60);
?>
<section class="card">
  <h2>부대 <small class="muted"><?= count($armies) ?>개</small></h2>
  <table class="grid">
    <tr><th>마을</th><th>부대</th><th>상태</th><th>위치</th><th>병력</th><th>짐</th><th></th></tr>
    <?php foreach ($armies as $a):
      $pos = vg_army_pos($a, $now);
      $state = $a['state'] === 'moving' ? ($a['returning'] ? '회군 중' : '이동 중') . ' · 남은 ' . $fmtDur(max(0, $a['arrive_at'] - $now)) : '주둔';
      if ($a['task'] === 'gather') $state .= ' · 채집';
      if ($a['task'] === 'bridge') $state .= ' · 다리 건설 (' . $a['task_q'] . ', ' . $a['task_r'] . ')';
      $cargo = array_filter($a['cargo'], fn($x) => $x >= 1); ?>
      <tr>
        <td><?= h($vn[$a['village_id']]['name'] ?? '?') ?> <small class="muted"><?= h($vn[$a['village_id']]['user_name'] ?? '') ?></small></td>
        <td><?= h($a['name']) ?></td>
        <td><?= h($state) ?></td>
        <td>(<?= $pos['cur'][0] ?>, <?= $pos['cur'][1] ?>)<?= $pos['next'] ? ' → (' . $pos['next'][0] . ', ' . $pos['next'][1] . ')' : '' ?></td>
        <td class="small"><?= h(implode(', ', array_map(fn($u) => ($udefs[$u['code']]['name'] ?? $u['code']) . ' ' . $u['count'], $a['units']))) ?></td>
        <td class="small"><?= $cargo ? h(vg_fmt_cost(array_map('floor', $cargo))) : '-' ?></td>
        <td><form method="post" onsubmit="return confirm('이 부대를 즉시 마을로 돌려보낼까요?')">
          <?= csrf_field() ?><input type="hidden" name="do" value="army_home"><input type="hidden" name="aid" value="<?= $a['id'] ?>">
          <button class="btn small">즉시 귀환</button></form></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$armies): ?><tr><td colspan="7" class="muted">나가 있는 부대가 없습니다.</td></tr><?php endif; ?>
  </table>
</section>
<section class="card">
  <h2>전투 기록</h2>
  <p class="muted">5단계(합류·동맹·전투)에서 구현합니다.</p>
</section>
