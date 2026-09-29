<?php
defined('VG_ADMIN') || exit;
$rows = $pdo->query('SELECT * FROM vg_settings ORDER BY sort_order, skey')->fetchAll();
$defs = vg_setting_defs();
$byCat = [];
foreach ($rows as $r) $byCat[$r['category']][] = $r;
$cats = vg_setting_categories();
foreach (array_keys($byCat) as $c) if (!isset($cats[$c])) $cats[$c] = $c;
$sv = fn($k) => vg_settings_all()[$k] ?? $defs[$k][0];
?>
<section class="card">
  <h2>마을 격자 확장</h2>
  <p class="muted">회관 레벨별로 열리는 칸 수. 칸은 가운데(회관) 가까운 곳부터 열린다. 이미 건물이 있는 칸은 칸 수를 줄여도 그대로 보호된다.</p>
  <form method="post" class="gridcard">
    <?= csrf_field() ?><input type="hidden" name="do" value="save_settings">
    <div class="inline">
      <label>시작 칸 <input type="number" name="s[cells_base]" id="g_base" value="<?= h($sv('cells_base')) ?>" min="0" max="16"></label>
      <label>회관 레벨당 <input type="number" name="s[cells_per_hall_level]" id="g_per" value="<?= h($sv('cells_per_hall_level')) ?>" min="0" max="16"></label>
      <label>최대 <input type="number" name="s[cells_max]" id="g_max" value="<?= h($sv('cells_max')) ?>" min="1" max="16"></label>
      <label class="grow">레벨별 직접 지정 <input type="text" name="s[grid_expand_levels]" id="g_list" value="<?= h($sv('grid_expand_levels')) ?>" placeholder="비우면 공식 사용 (예: 4,6,8,10,12,14,16)"></label>
      <button class="btn primary">저장</button>
    </div>
    <div class="gridprev" id="gridprev"></div>
  </form>
</section>

<section class="card">
  <h2>설정</h2>
  <div class="searchbar"><input type="search" id="sfind" placeholder="이름·설명·키 검색"></div>
  <form method="post" id="sform">
    <?= csrf_field() ?><input type="hidden" name="do" value="save_settings">
    <?php foreach ($cats as $c => $cname): if (empty($byCat[$c]) || $c === 'grid') continue; // 격자는 위 카드에서 ?>
      <details class="scat" open>
        <summary><?= h($cname) ?> <small>(<?= count($byCat[$c]) ?>)</small></summary>
        <?php foreach ($byCat[$c] as $r):
          $def = $defs[$r['skey']][0] ?? null;
          $changed = $def !== null && vg_setting_to_str($def) !== $r['sval']; ?>
          <div class="srow<?= $changed ? ' changed' : '' ?>" data-search="<?= h(mb_strtolower($r['label'] . ' ' . $r['descr'] . ' ' . $r['skey'])) ?>">
            <div class="sl"><b><?= h($r['label']) ?></b> <code><?= h($r['skey']) ?></code>
              <?php if ($r['descr']): ?><div class="muted small"><?= h($r['descr']) ?></div><?php endif; ?></div>
            <div class="sv">
              <?php if ($r['stype'] === 'bool'): ?>
                <input type="hidden" name="s[<?= h($r['skey']) ?>]" value="0">
                <label class="sw"><input type="checkbox" name="s[<?= h($r['skey']) ?>]" value="1" <?= $r['sval'] === '1' ? 'checked' : '' ?>> 켜기</label>
              <?php else: ?>
                <input type="<?= $r['stype'] === 'str' ? 'text' : 'number' ?>" step="any" name="s[<?= h($r['skey']) ?>]" value="<?= h($r['sval']) ?>">
              <?php endif; ?>
              <?php if ($changed): ?><small class="muted">기본 <?= h(vg_setting_to_str($def)) ?></small><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </details>
    <?php endforeach; ?>
    <div class="sticky-save"><button class="btn primary">설정 저장</button></div>
  </form>
</section>

<script>
(function () {
  const find = document.getElementById('sfind');
  find.addEventListener('input', () => {
    const q = find.value.trim().toLowerCase();
    document.querySelectorAll('.scat').forEach((d) => {
      let any = false;
      d.querySelectorAll('.srow').forEach((r) => {
        const hit = !q || r.dataset.search.includes(q);
        r.hidden = !hit; any = any || hit;
      });
      d.hidden = !any;
      if (q && any) d.open = true;
    });
  });

  // 격자 미리보기: 서버와 같은 공식·같은 칸 순서
  const layout = <?= json_encode(vg_slot_layout()) ?>;
  const $ = (id) => document.getElementById(id);
  function cellsFor(h) {
    const max = Math.max(1, Math.min(16, +$('g_max').value || 16));
    const list = $('g_list').value.split(',').map((s) => s.trim()).filter((s) => s !== '');
    if (list.length) return Math.max(0, Math.min(max, +list[Math.max(0, Math.min(list.length - 1, h - 1))] || 0));
    return Math.max(0, Math.min(max, (+$('g_base').value || 0) + Math.max(0, h - 1) * (+$('g_per').value || 0)));
  }
  function draw() {
    let html = '';
    for (let h = 1; h <= 10; h++) {
      const n = cellsFor(h);
      let cells = '';
      for (let y = 0; y < 5; y++) for (let x = 0; x < 5; x++) {
        let slot = null;
        for (const s in layout) if (layout[s][0] === x && layout[s][1] === y) slot = +s;
        const cls = slot === 0 ? 'hall' : slot !== null && slot <= n ? 'open' : '';
        cells += `<i class="${cls}" title="${slot === 0 ? '회관' : slot ? '#' + slot : ''}"></i>`;
      }
      html += `<div class="gp"><div class="gpg">${cells}</div><div>회관 Lv${h}<br><b>${n}칸</b></div></div>`;
    }
    $('gridprev').innerHTML = html;
  }
  ['g_base', 'g_per', 'g_max', 'g_list'].forEach((id) => $(id).addEventListener('input', draw));
  draw();
})();
</script>
