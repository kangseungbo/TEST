<?php
defined('VG_ADMIN') || exit;
$keys = vg_admin_image_keys();
$imgs = vg_images_all(true);
$defs = vg_bdefs();
$groups = [];
foreach ($keys as $k => $label) {
    if (preg_match('/^bld_(.+)_(\d)$/', $k, $m)) $groups[$m[1]][$k] = $label;
    else $groups['_wall'][$k] = $label;
}
$slot = function (string $k, string $label) use ($imgs) {
    $url = isset($imgs[$k]) ? vg_image_url($k, '../') : null; ?>
    <div class="imgslot">
      <div class="thumb"><?= $url ? '<img src="' . h($url) . '" alt="">' : '<span class="muted">기본 그림</span>' ?></div>
      <div class="small"><?= h($label) ?></div>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?><input type="hidden" name="do" value="upload_image"><input type="hidden" name="key" value="<?= h($k) ?>">
        <input type="file" name="file" accept="image/png,image/jpeg,image/gif,image/webp" required onchange="this.form.submit()">
      </form>
      <?php if ($url): ?>
        <form method="post" onsubmit="return confirm('이미지를 지울까요?')">
          <?= csrf_field() ?><input type="hidden" name="do" value="delete_image"><input type="hidden" name="key" value="<?= h($k) ?>">
          <button class="btn small danger">삭제</button>
        </form>
      <?php endif; ?>
    </div>
<?php };
?>
<section class="card">
  <h2>성벽 이미지</h2>
  <p class="muted">뒤 성벽(마을 뒤쪽 두 변)과 앞 성벽(앞쪽 두 변)을 각각 한 장씩 통째로 올린다. 가로 폭은 마을 둘레 폭에 맞춰지고 아래쪽 기준으로 배치된다.
    한 장만 올리면 나머지는 기본 도형으로 그린다. 크기·위치 보정은 설정 탭 &gt; 화면·표시.</p>
  <div class="imgrow"><?php foreach ($groups['_wall'] as $k => $l) $slot($k, $l); ?></div>
</section>
<section class="card">
  <h2>건물 이미지</h2>
  <p class="muted">레벨 3단계 (Lv1~3 / Lv4~7 / Lv8+). 투명 배경 PNG 권장, 정사각형에 가깝게, 건물 바닥이 이미지 아래쪽에 오도록.
    해당 단계 이미지가 없으면 Lv1~3 이미지 → 기본 그림 순으로 쓴다.</p>
  <?php foreach ($groups as $code => $ks): if ($code === '_wall') continue; ?>
    <div class="imggroup">
      <h3><?= h($defs[$code]['name'] ?? $code) ?> <code><?= h($code) ?></code></h3>
      <div class="imgrow"><?php foreach ($ks as $k => $l) $slot($k, $l); ?></div>
    </div>
  <?php endforeach; ?>
</section>
