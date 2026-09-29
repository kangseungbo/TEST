<?php
defined('VG_ADMIN') || exit;
$udefs = vg_udefs(true);
$bdefs = vg_bdefs(true);
$cats = vg_unit_categories();
$inUse = [];
foreach ($pdo->query('SELECT unit_code, SUM(count) n FROM vg_village_units GROUP BY unit_code') as $r) $inUse[$r['unit_code']] = (int)$r['n'];
$trainBlds = array_filter($bdefs, fn($d) => in_array($d['category'], ['military', 'hall'], true));
?>
<section class="card">
  <h2>병종</h2>
  <p class="muted">훈련 건물 연결은 여기와 건물 탭(훈련 건물 카드의 '훈련 병종') 어느 쪽에서 바꿔도 같은 값이다.
    유지비 = 1명당 시간당 식량, 이동 = 평원 1타일 초(4단계), 운반 = 약탈량(5단계).
    상성: 체크한 병종에게 강함 (공격 +<?= h(S('counter_bonus_pct')) ?>%, 상대 부대 구성 비율로 가중평균, 5단계 전투).</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="save_udefs">
    <div class="bdefs">
    <?php foreach ($udefs as $code => $u): $f = "u[$code]"; ?>
      <details class="bdef">
        <summary>
          <span class="uprev" data-unit="<?= h($code) ?>" data-cat="<?= h($u['category']) ?>"></span>
          <b><?= h($u['name']) ?></b> <code><?= h($code) ?></code>
          <span class="muted"><?= h($cats[$u['category']] ?? $u['category']) ?> · <?= $u['train_bld'] !== '' ? h(($bdefs[$u['train_bld']]['name'] ?? $u['train_bld']) . ' Lv' . $u['req_level']) : '<span class="warn">훈련 건물 없음</span>' ?></span>
          <?= $u['enabled'] ? '' : '<span class="badge">사용 안 함</span>' ?>
          <?= !empty($inUse[$code]) ? '<small class="muted">' . number_format($inUse[$code]) . '명 보유</small>' : '' ?>
        </summary>
        <div class="fields">
          <label>이름 <input name="<?= $f ?>[name]" value="<?= h($u['name']) ?>"></label>
          <label>분류 <select name="<?= $f ?>[category]"><?php foreach ($cats as $ck => $cn): ?><option value="<?= $ck ?>" <?= $ck === $u['category'] ? 'selected' : '' ?>><?= h($cn) ?></option><?php endforeach; ?></select></label>
          <label>훈련 건물 <select name="<?= $f ?>[train_bld]">
            <option value="">(훈련 불가)</option>
            <?php foreach ($trainBlds as $bc => $bd): ?><option value="<?= h($bc) ?>" <?= $bc === $u['train_bld'] ? 'selected' : '' ?>><?= h($bd['name']) ?></option><?php endforeach; ?>
          </select></label>
          <label>필요 건물 Lv <input type="number" name="<?= $f ?>[req_level]" value="<?= h($u['req_level']) ?>"></label>
          <?php foreach ($names as $rk => $rn): ?>
            <label>비용 <?= h($rn) ?> <input type="number" step="any" min="0" name="<?= $f ?>[cost][<?= $rk ?>]" value="<?= h($u['cost'][$rk] ?? 0) ?>"></label>
          <?php endforeach; ?>
          <label>훈련 시간(초/명) <input type="number" step="any" name="<?= $f ?>[train_time]" value="<?= h($u['train_time']) ?>"></label>
          <label>유지비(식량/시간) <input type="number" step="any" name="<?= $f ?>[upkeep]" value="<?= h($u['upkeep']) ?>"></label>
          <label>공격 <input type="number" step="any" name="<?= $f ?>[attack]" value="<?= h($u['attack']) ?>"></label>
          <label>방어 <input type="number" step="any" name="<?= $f ?>[defense]" value="<?= h($u['defense']) ?>"></label>
          <label>이동(초/타일) <input type="number" step="any" name="<?= $f ?>[speed]" value="<?= h($u['speed']) ?>"></label>
          <label>운반 <input type="number" step="any" name="<?= $f ?>[carry]" value="<?= h($u['carry']) ?>"></label>
          <label>정렬 <input type="number" name="<?= $f ?>[sort_order]" value="<?= h($u['sort_order']) ?>"></label>
          <label class="chk"><input type="checkbox" name="<?= $f ?>[ranged]" value="1" <?= $u['ranged'] ? 'checked' : '' ?>> 원거리</label>
          <label class="chk"><input type="checkbox" name="<?= $f ?>[enabled]" value="1" <?= $u['enabled'] ? 'checked' : '' ?>> 사용</label>
          <div class="wide checks"><span class="small">상성상 강한 상대:</span>
            <?php foreach ($udefs as $oc => $o): if ($oc === $code) continue; ?>
              <label class="chk"><input type="checkbox" name="<?= $f ?>[counters][]" value="<?= h($oc) ?>" <?= in_array($oc, $u['counters'], true) ? 'checked' : '' ?>> <?= h($o['name']) ?></label>
            <?php endforeach; ?>
          </div>
          <label class="wide">설명 <input name="<?= $f ?>[descr]" value="<?= h($u['descr']) ?>"></label>
        </div>
        <?php if (empty($inUse[$code])): ?><button class="btn danger small" form="udel-<?= h($code) ?>">이 병종 삭제</button><?php endif; ?>
      </details>
    <?php endforeach; ?>
    </div>
    <div class="sticky-save"><button class="btn primary">병종 저장</button></div>
  </form>
  <?php foreach ($udefs as $code => $u): if (!empty($inUse[$code])) continue; ?>
    <form method="post" id="udel-<?= h($code) ?>" onsubmit="return confirm('<?= h($u['name']) ?> 병종을 삭제할까요?')">
      <?= csrf_field() ?><input type="hidden" name="do" value="delete_udef"><input type="hidden" name="code" value="<?= h($code) ?>">
    </form>
  <?php endforeach; ?>
</section>

<section class="card">
  <h2>새 병종 추가</h2>
  <p class="muted small">전용 그림이 없으면 분류별 대표 그림(보병=검사, 원거리=궁수, 기병=기병, 공성=투석기, 일꾼=일꾼)으로 그려진다.</p>
  <form method="post" class="fields">
    <?= csrf_field() ?><input type="hidden" name="do" value="add_udef">
    <label>코드 <input name="code" required pattern="[a-z][a-z0-9_]{1,30}" placeholder="예: pikeman"></label>
    <label>이름 <input name="name" required></label>
    <label>분류 <select name="category"><?php foreach ($cats as $ck => $cn): ?><option value="<?= $ck ?>"><?= h($cn) ?></option><?php endforeach; ?></select></label>
    <label>훈련 건물 <select name="train_bld"><option value="">(훈련 불가)</option><?php foreach ($trainBlds as $bc => $bd): ?><option value="<?= h($bc) ?>"><?= h($bd['name']) ?></option><?php endforeach; ?></select></label>
    <label>필요 건물 Lv <input type="number" name="req_level" value="1"></label>
    <?php foreach ($names as $rk => $rn): ?><label>비용 <?= h($rn) ?> <input type="number" step="any" name="cost[<?= $rk ?>]" value="0"></label><?php endforeach; ?>
    <label>훈련 시간(초/명) <input type="number" name="train_time" value="30"></label>
    <label>유지비(식량/시간) <input type="number" step="any" name="upkeep" value="10"></label>
    <label>공격 <input type="number" step="any" name="attack" value="10"></label>
    <label>방어 <input type="number" step="any" name="defense" value="10"></label>
    <label>이동(초/타일) <input type="number" step="any" name="speed" value="60"></label>
    <label>운반 <input type="number" step="any" name="carry" value="10"></label>
    <input type="hidden" name="enabled" value="1"><input type="hidden" name="sort_order" value="500">
    <label class="chk"><input type="checkbox" name="ranged" value="1"> 원거리</label>
    <label class="wide">설명 <input name="descr"></label>
    <div><button class="btn primary">추가</button></div>
  </form>
</section>
<link rel="stylesheet" href="../assets/css/art.css?v=<?= filemtime(__DIR__ . '/../assets/css/art.css') ?>">
<script src="../assets/js/art.js?v=<?= filemtime(__DIR__ . '/../assets/js/art.js') ?>"></script>
<script>
document.querySelectorAll('.uprev').forEach((n) => {
  const NS = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(NS, 'svg');
  svg.setAttribute('viewBox', '-34 -66 68 72'); svg.setAttribute('width', 34); svg.setAttribute('height', 34);
  const g = document.createElementNS(NS, 'g'); svg.appendChild(g);
  window.VgArt.unit(g, n.dataset.unit, { category: n.dataset.cat });
  n.appendChild(svg);
});
</script>
