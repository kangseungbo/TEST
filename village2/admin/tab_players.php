<?php
defined('VG_ADMIN') || exit;
$q = trim((string)($_GET['q'] ?? ''));
$open = (int)($_GET['open'] ?? 0);
$sql = 'SELECT id FROM vg_villages';
$args = [];
if ($q !== '') {
    $sql .= ' WHERE name LIKE ? OR user_name LIKE ? OR user_id LIKE ?';
    $args = array_fill(0, 3, '%' . $q . '%');
}
$sql .= ' ORDER BY last_seen DESC LIMIT 100';
$st = $pdo->prepare($sql);
$st->execute($args);
$ids = $st->fetchAll(PDO::FETCH_COLUMN);
$defs = vg_bdefs();
$now = vg_now();
$ago = function (float $t) use ($now): string {
    $d = $now - $t;
    if ($d < 120) return '방금';
    if ($d < 3600) return floor($d / 60) . '분 전';
    if ($d < 86400) return floor($d / 3600) . '시간 전';
    return floor($d / 86400) . '일 전';
};
?>
<section class="card">
  <h2>플레이어</h2>
  <form class="searchbar" method="get">
    <input type="hidden" name="tab" value="players">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="마을·사용자 검색">
    <button class="btn small">검색</button>
    <span class="muted small">최근 접속 순 최대 100개. 펼치면 건물 레벨·자원을 직접 바꿀 수 있다.</span>
  </form>
  <?php foreach ($ids as $vid):
    $vid = (int)$vid;
    $s = vg_tx(fn() => vg_settle($vid));
    $v = $s['village']; $res = $s['res']; $blds = $s['blds'];
    $hall = vg_hall_level($blds);
    $busy = count(array_filter($blds, fn($b) => $b['build_finish'] !== null));
    $nr = vg_net_rates($res, $blds);
    $ironNeg = $nr['rates']['smelt']['iron'] > 0 && $nr['net']['iron'] < 0;
    usort($blds, fn($a, $b) => $a['slot'] <=> $b['slot']);
  ?>
    <details class="player" id="v<?= $vid ?>" <?= $open === $vid ? 'open' : '' ?>>
      <summary>
        <b><?= h($v['name']) ?></b> <span class="muted"><?= h($v['user_name']) ?> (<?= h($v['user_id']) ?>)</span>
        <span class="pill">회관 Lv<?= $hall ?></span>
        <span class="pill">건물 <?= count($blds) ?></span>
        <?php if ($busy): ?><span class="pill hot">공사 <?= $busy ?></span><?php endif; ?>
        <?php if ($ironNeg): ?><span class="pill warn" title="제련소 소모가 광산 생산보다 많음">철광석 순감소</span><?php endif; ?>
        <span class="resmini"><?php foreach (VG_RES as $r): ?><span><?= h($names[$r]) ?> <?= number_format($res[$r]) ?></span><?php endforeach; ?></span>
        <span class="muted small"><?= $ago((float)$v['last_seen']) ?></span>
      </summary>
      <form method="post" class="pform">
        <?= csrf_field() ?><input type="hidden" name="do" value="player_save"><input type="hidden" name="vid" value="<?= $vid ?>">
        <input type="hidden" name="open" value="<?= $vid ?>"><input type="hidden" name="anchor" value="v<?= $vid ?>">
        <div class="minirow">
          <label>마을 이름 <input name="vname" value="<?= h($v['name']) ?>"></label>
          <?php foreach (VG_RES as $r): ?>
            <label><?= h($names[$r]) ?> <input type="number" step="any" min="0" name="res[<?= $r ?>]" value="<?= floor($res[$r]) ?>"></label>
          <?php endforeach; ?>
        </div>
        <div class="minigrid">
          <?php foreach ($blds as $b): $d = $defs[$b['code']] ?? ['name' => $b['code']]; ?>
            <div class="mini<?= $b['build_finish'] !== null ? ' busy' : '' ?>">
              <div class="mh"><span class="slot">#<?= $b['slot'] === VG_SLOT_HALL ? '회관' : ($b['slot'] === VG_SLOT_WALL ? '둘레' : $b['slot']) ?></span> <?= h($d['name']) ?></div>
              <div class="mb">Lv <input type="number" min="0" name="lv[<?= $b['id'] ?>]" value="<?= $b['level'] ?>">
                <?php if ($b['code'] !== 'hall'): ?><label class="del" title="삭제"><input type="checkbox" name="del[<?= $b['id'] ?>]" value="1">삭제</label><?php endif; ?>
              </div>
              <?php if ($b['build_finish'] !== null): ?><div class="small hot">→Lv<?= $b['target_level'] ?> <?= gmdate('H:i:s', (int)max(0, $b['build_finish'] - $now)) ?></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="muted small">레벨을 바꾸면 그 건물의 진행 중 공사는 환급 없이 사라진다. 0 또는 삭제 체크 = 건물 제거.</p>
        <button class="btn primary">이 마을 저장</button>
      </form>
      <div class="pactions">
        <form method="post" class="inline">
          <?= csrf_field() ?><input type="hidden" name="do" value="player_add_building"><input type="hidden" name="vid" value="<?= $vid ?>">
          <input type="hidden" name="open" value="<?= $vid ?>"><input type="hidden" name="anchor" value="v<?= $vid ?>">
          <select name="code"><?php foreach ($defs as $c => $d): if ($c === 'hall') continue; ?><option value="<?= h($c) ?>"><?= h($d['name']) ?></option><?php endforeach; ?></select>
          <label>칸 <input type="number" name="slot" min="1" max="16" value="1" class="w4"></label>
          <label>Lv <input type="number" name="level" min="1" value="1" class="w4"></label>
          <button class="btn small">건물 추가</button>
        </form>
        <?php if ($busy): ?>
        <form method="post" class="inline">
          <?= csrf_field() ?><input type="hidden" name="do" value="player_finish"><input type="hidden" name="vid" value="<?= $vid ?>">
          <input type="hidden" name="open" value="<?= $vid ?>"><input type="hidden" name="anchor" value="v<?= $vid ?>">
          <button class="btn small">공사 즉시 완료</button>
        </form>
        <?php endif; ?>
        <form method="post" class="inline" onsubmit="return confirm('<?= h($v['name']) ?> 마을을 삭제할까요? 되돌릴 수 없습니다.')">
          <?= csrf_field() ?><input type="hidden" name="do" value="player_delete"><input type="hidden" name="vid" value="<?= $vid ?>">
          <button class="btn small danger">마을 삭제</button>
        </form>
      </div>
    </details>
  <?php endforeach; ?>
  <?php if (!$ids): ?><p class="muted">마을이 없습니다.</p><?php endif; ?>
</section>
