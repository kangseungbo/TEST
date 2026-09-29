<?php
defined('VG_ADMIN') || exit;
$rdefs = vg_rdefs(true);
$effects = vg_research_effects();
$targets = ['all' => '전체'] + vg_unit_categories();
$inUse = [];
foreach ($pdo->query('SELECT code, COUNT(*) n FROM vg_research WHERE level > 0 OR finish IS NOT NULL GROUP BY code') as $r) $inUse[$r['code']] = (int)$r['n'];
$fmtDur = function (float $s): string {
    $s = (int)round($s);
    return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
};
?>
<section class="card">
  <h2>대장간 연구</h2>
  <p class="muted">레벨 L 연구에 필요한 대장간 레벨 = 필요 대장간 Lv + (L − 1). 비용·시간은 레벨마다 증가율^(L−1) 배.
    효과는 레벨마다 쌓인다. 적용 시점: <?php foreach ($effects as $e) echo h($e[0]) . ' → ' . h($e[1]) . ' · '; ?></p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="save_rdefs">
    <div class="bdefs">
    <?php foreach ($rdefs as $code => $d): $f = "r[$code]"; ?>
      <details class="bdef">
        <summary>
          <b><?= h($d['name']) ?></b> <code><?= h($code) ?></code>
          <span class="muted"><?= h($effects[$d['effect']][0] ?? $d['effect']) ?> +<?= h($d['value_per_level']) ?>%/Lv · <?= h($targets[$d['target']] ?? $d['target']) ?> · 최대 Lv<?= $d['max_level'] ?></span>
          <?= $d['enabled'] ? '' : '<span class="badge">사용 안 함</span>' ?>
          <?= !empty($inUse[$code]) ? '<small class="muted">' . $inUse[$code] . '개 마을</small>' : '' ?>
        </summary>
        <div class="fields">
          <label>이름 <input name="<?= $f ?>[name]" value="<?= h($d['name']) ?>"></label>
          <label>효과 <select name="<?= $f ?>[effect]"><?php foreach ($effects as $ek => $ev): ?><option value="<?= $ek ?>" <?= $ek === $d['effect'] ? 'selected' : '' ?>><?= h($ev[0]) ?></option><?php endforeach; ?></select></label>
          <label>대상 <select name="<?= $f ?>[target]"><?php foreach ($targets as $tk => $tn): ?><option value="<?= $tk ?>" <?= $tk === $d['target'] ? 'selected' : '' ?>><?= h($tn) ?></option><?php endforeach; ?></select></label>
          <label>레벨당 % <input type="number" step="any" name="<?= $f ?>[value_per_level]" value="<?= h($d['value_per_level']) ?>"></label>
          <label>최대 레벨 <input type="number" name="<?= $f ?>[max_level]" value="<?= h($d['max_level']) ?>"></label>
          <label>필요 대장간 Lv <input type="number" name="<?= $f ?>[req_smithy]" value="<?= h($d['req_smithy']) ?>"></label>
          <?php foreach ($names as $rk => $rn): ?>
            <label>비용 <?= h($rn) ?> <input type="number" step="any" min="0" name="<?= $f ?>[cost][<?= $rk ?>]" value="<?= h($d['cost'][$rk] ?? 0) ?>"></label>
          <?php endforeach; ?>
          <label>비용 증가율 <input type="number" step="any" name="<?= $f ?>[cost_growth]" value="<?= h($d['cost_growth']) ?>"></label>
          <label>기본 시간(초) <input type="number" step="any" name="<?= $f ?>[base_time]" value="<?= h($d['base_time']) ?>"></label>
          <label>시간 증가율 <input type="number" step="any" name="<?= $f ?>[time_growth]" value="<?= h($d['time_growth']) ?>"></label>
          <label>정렬 <input type="number" name="<?= $f ?>[sort_order]" value="<?= h($d['sort_order']) ?>"></label>
          <label class="chk"><input type="checkbox" name="<?= $f ?>[enabled]" value="1" <?= $d['enabled'] ? 'checked' : '' ?>> 사용</label>
          <label class="wide">설명 <input name="<?= $f ?>[descr]" value="<?= h($d['descr']) ?>"></label>
        </div>
        <details class="lvprev"><summary>레벨별 미리보기</summary>
          <table class="grid compact">
            <tr><th>Lv</th><th>대장간</th><th>비용</th><th>시간</th><th>누적 효과</th></tr>
            <?php for ($l = 1; $l <= $d['max_level']; $l++): ?>
              <tr><td><?= $l ?></td><td>Lv<?= vg_research_req($d, $l) ?></td><td><?= h(vg_fmt_cost(vg_research_cost($d, $l))) ?></td>
                <td><?= $fmtDur(vg_research_time($d, $l)) ?></td><td>+<?= round($l * $d['value_per_level'], 1) ?>%</td></tr>
            <?php endfor; ?>
          </table>
          <?php if (empty($inUse[$code])): ?><button class="btn danger small" form="rdel-<?= h($code) ?>">이 연구 삭제</button><?php endif; ?>
        </details>
      </details>
    <?php endforeach; ?>
    </div>
    <div class="sticky-save"><button class="btn primary">연구 저장</button></div>
  </form>
  <?php foreach ($rdefs as $code => $d): if (!empty($inUse[$code])) continue; ?>
    <form method="post" id="rdel-<?= h($code) ?>" onsubmit="return confirm('<?= h($d['name']) ?> 연구를 삭제할까요?')">
      <?= csrf_field() ?><input type="hidden" name="do" value="delete_rdef"><input type="hidden" name="code" value="<?= h($code) ?>">
    </form>
  <?php endforeach; ?>
</section>

<section class="card">
  <h2>새 연구 추가</h2>
  <form method="post" class="fields">
    <?= csrf_field() ?><input type="hidden" name="do" value="add_rdef">
    <label>코드 <input name="code" required pattern="[a-z][a-z0-9_]{1,30}"></label>
    <label>이름 <input name="name" required></label>
    <label>효과 <select name="effect"><?php foreach ($effects as $ek => $ev): ?><option value="<?= $ek ?>"><?= h($ev[0]) ?></option><?php endforeach; ?></select></label>
    <label>대상 <select name="target"><?php foreach ($targets as $tk => $tn): ?><option value="<?= $tk ?>"><?= h($tn) ?></option><?php endforeach; ?></select></label>
    <label>레벨당 % <input type="number" step="any" name="value_per_level" value="5"></label>
    <label>최대 레벨 <input type="number" name="max_level" value="10"></label>
    <label>필요 대장간 Lv <input type="number" name="req_smithy" value="1"></label>
    <?php foreach ($names as $rk => $rn): ?><label>비용 <?= h($rn) ?> <input type="number" step="any" name="cost[<?= $rk ?>]" value="0"></label><?php endforeach; ?>
    <label>기본 시간(초) <input type="number" name="base_time" value="60"></label>
    <input type="hidden" name="cost_growth" value="1.6"><input type="hidden" name="time_growth" value="1.5">
    <input type="hidden" name="enabled" value="1"><input type="hidden" name="sort_order" value="500">
    <label class="wide">설명 <input name="descr"></label>
    <div><button class="btn primary">추가</button></div>
  </form>
</section>
