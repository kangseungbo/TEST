<?php
defined('VG_ADMIN') || exit;
$items = [
    'villages' => ['모든 마을 삭제', '모든 플레이어의 마을·자원·건물·기록을 지운다. 다음 접속 때 새 마을이 만들어진다.'],
    'settings' => ['설정 기본값으로', '설정 탭의 모든 값을 코드 기본값으로 되돌린다.'],
    'bdefs'    => ['건물 정의 기본값으로', '기본 건물들의 비용·시간·생산량 등을 기본값으로 되돌린다. 직접 추가한 건물은 그대로 둔다.'],
];
?>
<section class="card">
  <h2>초기화</h2>
  <p class="warn">되돌릴 수 없습니다. 확인란에 <b>초기화</b> 라고 입력해야 실행됩니다.</p>
  <?php foreach ($items as $k => [$t, $desc]): ?>
    <form method="post" class="resetrow" onsubmit="return confirm('<?= h($t) ?> — 정말 실행할까요?')">
      <?= csrf_field() ?><input type="hidden" name="do" value="reset"><input type="hidden" name="what" value="<?= $k ?>">
      <div><b><?= h($t) ?></b><div class="muted small"><?= h($desc) ?></div></div>
      <input name="confirm" placeholder="초기화" autocomplete="off">
      <button class="btn danger">실행</button>
    </form>
  <?php endforeach; ?>
</section>
