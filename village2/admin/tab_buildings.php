<?php
defined('VG_ADMIN') || exit;
$defs = vg_bdefs(true);
$cats = vg_building_categories();
$fmtDur = function (float $s): string {
    $s = (int)round($s);
    if ($s >= 3600) return sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
    return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
};
$fmtCost = function (array $c) use ($names): string {
    $p = [];
    foreach ($c as $r => $a) $p[] = $names[$r] . ' ' . number_format($a);
    return implode(' · ', $p) ?: '-';
};
$udefsAll = vg_udefs(true);
$inUse = [];
foreach ($pdo->query('SELECT code, COUNT(*) n FROM vg_buildings GROUP BY code') as $r) $inUse[$r['code']] = (int)$r['n'];

// 제련소 점검: 같은 레벨 광산 1채 생산 vs 제련소 1채 철광석 소모
$ratio = (float)S('smelt_iron_per_gold');
$smeltRows = [];
if (isset($defs['smelter'], $defs['mine'])) {
    for ($l = 1; $l <= 10; $l++) {
        $mine = vg_level_rate($defs['mine'], $l);
        $use = vg_level_rate($defs['smelter'], $l) * $ratio;
        $smeltRows[] = [$l, $mine, $use, $mine - $use];
    }
}
$smeltWarn = array_filter($smeltRows, fn($r) => $r[3] < 0);
?>
<?php if ($smeltRows): ?>
<section class="card<?= $smeltWarn ? ' warnbox' : '' ?>">
  <h2>제련소 점검 <?= $smeltWarn ? '<span class="badge warn">철광석 순감소</span>' : '<span class="badge ok">정상</span>' ?></h2>
  <p class="muted">같은 레벨 광산 1채와 제련소 1채를 함께 돌릴 때의 초당 철광석 (배수·보너스 제외). 금괴 1개 = 철광석 <?= h($ratio) ?>개.
    순감소면 철광석이 줄어들다가 재고가 바닥나면 제련소는 광산이 캐는 만큼만 돌아간다.</p>
  <table class="grid compact">
    <tr><th>레벨</th><?php foreach ($smeltRows as $r): ?><td>Lv<?= $r[0] ?></td><?php endforeach; ?></tr>
    <tr><th>광산 생산</th><?php foreach ($smeltRows as $r): ?><td><?= round($r[1], 2) ?></td><?php endforeach; ?></tr>
    <tr><th>제련소 소모</th><?php foreach ($smeltRows as $r): ?><td><?= round($r[2], 2) ?></td><?php endforeach; ?></tr>
    <tr><th>순증감</th><?php foreach ($smeltRows as $r): ?><td class="<?= $r[3] < 0 ? 'neg' : 'pos' ?>"><?= ($r[3] >= 0 ? '+' : '') . round($r[3], 2) ?></td><?php endforeach; ?></tr>
  </table>
</section>
<?php endif; ?>

<section class="card">
  <h2>건물 정의</h2>
  <p class="muted">비용·시간은 레벨마다 (증가율)^(레벨-1) 배. 생산량 = 기본 × 레벨 × (증가율)^(레벨-1) (창고는 보관량).
    건물 상한은 회관 레벨 + <?= (int)S('hall_headroom') ?>. 성벽 분류는 칸을 쓰지 않는 둘레 건물.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="save_bdefs">
    <div class="bdefs">
    <?php foreach ($defs as $code => $d): $f = "d[$code]"; ?>
      <details class="bdef" id="b-<?= h($code) ?>">
        <summary>
          <b><?= h($d['name']) ?></b> <code><?= h($code) ?></code>
          <span class="muted"><?= h($cats[$d['category']] ?? $d['category']) ?><?= $d['produces'] ? ' · ' . h($names[$d['produces']]) : '' ?></span>
          <?= $d['enabled'] ? '' : '<span class="badge">사용 안 함</span>' ?>
          <?= !empty($inUse[$code]) ? '<small class="muted">' . $inUse[$code] . '채</small>' : '' ?>
        </summary>
        <div class="fields">
          <label>이름 <input name="<?= $f ?>[name]" value="<?= h($d['name']) ?>"></label>
          <label>분류 <select name="<?= $f ?>[category]" <?= $code === 'hall' ? 'disabled' : '' ?>>
            <?php foreach ($cats as $ck => $cn): ?><option value="<?= $ck ?>" <?= $ck === $d['category'] ? 'selected' : '' ?>><?= h($cn) ?></option><?php endforeach; ?>
          </select></label>
          <label>생산 자원 <select name="<?= $f ?>[produces]">
            <option value="">없음</option>
            <?php foreach ($names as $rk => $rn): ?><option value="<?= $rk ?>" <?= $rk === $d['produces'] ? 'selected' : '' ?>><?= h($rn) ?></option><?php endforeach; ?>
          </select></label>
          <label>기본 생산(초당)/보관 <input type="number" step="any" name="<?= $f ?>[base_rate]" value="<?= h($d['base_rate']) ?>"></label>
          <label>생산 증가율 <input type="number" step="any" name="<?= $f ?>[rate_growth]" value="<?= h($d['rate_growth']) ?>"></label>
          <?php foreach ($names as $rk => $rn): ?>
            <label>비용 <?= h($rn) ?> <input type="number" step="any" min="0" name="<?= $f ?>[cost][<?= $rk ?>]" value="<?= h($d['cost'][$rk] ?? 0) ?>"></label>
          <?php endforeach; ?>
          <label>비용 증가율 <input type="number" step="any" name="<?= $f ?>[cost_growth]" value="<?= h($d['cost_growth']) ?>"></label>
          <label>기본 시간(초) <input type="number" step="any" name="<?= $f ?>[base_time]" value="<?= h($d['base_time']) ?>"></label>
          <label>시간 증가율 <input type="number" step="any" name="<?= $f ?>[time_growth]" value="<?= h($d['time_growth']) ?>"></label>
          <label>최대 레벨 <input type="number" name="<?= $f ?>[max_level]" value="<?= h($d['max_level']) ?>"></label>
          <label>필요 회관 Lv <input type="number" name="<?= $f ?>[req_hall]" value="<?= h($d['req_hall']) ?>"></label>
          <label>정렬 <input type="number" name="<?= $f ?>[sort_order]" value="<?= h($d['sort_order']) ?>"></label>
          <label class="chk"><input type="checkbox" name="<?= $f ?>[multi]" value="1" <?= $d['multi'] ? 'checked' : '' ?>> 여러 채</label>
          <label class="chk"><input type="checkbox" name="<?= $f ?>[enabled]" value="1" <?= $d['enabled'] ? 'checked' : '' ?> <?= $code === 'hall' ? 'disabled checked' : '' ?>> 사용</label>
          <label class="wide">설명 <input name="<?= $f ?>[descr]" value="<?= h($d['descr']) ?>"></label>
          <?php if (in_array($d['category'], ['military', 'hall'], true)): ?>
            <div class="wide checks"><input type="hidden" name="<?= $f ?>[train_link]" value="1">
              <span class="small">훈련 병종 (병종 탭과 같은 값):</span>
              <?php foreach ($udefsAll as $uc => $u): ?>
                <label class="chk"><input type="checkbox" name="<?= $f ?>[train_units][]" value="<?= h($uc) ?>" <?= $u['train_bld'] === $code ? 'checked' : '' ?>>
                  <?= h($u['name']) ?><?= $u['train_bld'] !== '' && $u['train_bld'] !== $code ? ' <small class="muted">(' . h($defs[$u['train_bld']]['name'] ?? $u['train_bld']) . ')</small>' : '' ?></label>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <details class="lvprev"><summary>레벨별 미리보기</summary>
          <table class="grid compact">
            <tr><th>Lv</th><th>비용</th><th>시간</th><th><?= $d['category'] === 'storage' ? '보관' : '생산/초' ?></th></tr>
            <?php for ($l = 1; $l <= min($d['max_level'], 12); $l++): ?>
              <tr><td><?= $l ?></td><td><?= h($fmtCost(vg_level_cost($d, $l))) ?></td><td><?= $fmtDur(vg_level_time($d, $l)) ?></td>
                <td><?= $d['base_rate'] > 0 ? round(vg_level_rate($d, $l), 2) : '-' ?></td></tr>
            <?php endfor; ?>
          </table>
          <?php if ($code !== 'hall' && empty($inUse[$code])): ?>
            <button class="btn danger small" form="del-<?= h($code) ?>">이 건물 정의 삭제</button>
          <?php endif; ?>
        </details>
      </details>
    <?php endforeach; ?>
    </div>
    <div class="sticky-save"><button class="btn primary">건물 정의 저장</button></div>
  </form>
  <?php foreach ($defs as $code => $d): if ($code === 'hall' || !empty($inUse[$code])) continue; ?>
    <form method="post" id="del-<?= h($code) ?>" onsubmit="return confirm('<?= h($d['name']) ?> 정의를 삭제할까요?')">
      <?= csrf_field() ?><input type="hidden" name="do" value="delete_bdef"><input type="hidden" name="code" value="<?= h($code) ?>">
    </form>
  <?php endforeach; ?>
</section>

<section class="card">
  <h2>새 건물 추가</h2>
  <form method="post" class="fields">
    <?= csrf_field() ?><input type="hidden" name="do" value="add_bdef">
    <label>코드 <input name="code" required pattern="[a-z][a-z0-9_]{1,30}" placeholder="예: fishery"></label>
    <label>이름 <input name="name" required></label>
    <label>분류 <select name="category"><?php foreach ($cats as $ck => $cn): if ($ck === 'hall') continue; ?><option value="<?= $ck ?>"><?= h($cn) ?></option><?php endforeach; ?></select></label>
    <label>생산 자원 <select name="produces"><option value="">없음</option><?php foreach ($names as $rk => $rn): ?><option value="<?= $rk ?>"><?= h($rn) ?></option><?php endforeach; ?></select></label>
    <label>기본 생산 <input type="number" step="any" name="base_rate" value="1"></label>
    <?php foreach ($names as $rk => $rn): ?><label>비용 <?= h($rn) ?> <input type="number" step="any" name="cost[<?= $rk ?>]" value="0"></label><?php endforeach; ?>
    <label>기본 시간(초) <input type="number" name="base_time" value="30"></label>
    <label>필요 회관 Lv <input type="number" name="req_hall" value="1"></label>
    <input type="hidden" name="rate_growth" value="1.08"><input type="hidden" name="cost_growth" value="1.5"><input type="hidden" name="time_growth" value="1.5">
    <input type="hidden" name="max_level" value="20"><input type="hidden" name="enabled" value="1"><input type="hidden" name="sort_order" value="500">
    <label class="chk"><input type="checkbox" name="multi" value="1" checked> 여러 채</label>
    <label class="wide">설명 <input name="descr"></label>
    <div><button class="btn primary">추가</button></div>
  </form>
</section>
