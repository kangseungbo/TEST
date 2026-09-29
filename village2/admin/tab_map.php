<?php
defined('VG_ADMIN') || exit;
vg_map_ensure();
$tdefs = vg_tdefs(true);
$feats = vg_map_features();
$seedNow = vg_meta_get($pdo, 'map_seed');
$radiusNow = vg_meta_get($pdo, 'map_radius');
$v = filemtime(__DIR__ . '/../assets/js/map.js') . filemtime(__DIR__ . '/../assets/js/camera.js');
?>
<section class="card">
  <h2>지형·맵 <small class="muted">시드 <?= h($seedNow) ?> · 반지름 <?= h($radiusNow) ?></small></h2>
  <div class="mapedit">
    <div class="mapbox"><canvas id="amap"></canvas>
      <div class="mctl"><button class="btn small" id="azin">＋</button><button class="btn small" id="azout">－</button><button class="btn small" id="afit">전체</button></div>
    </div>
    <div class="maptools">
      <h3>편집 도구 <small class="muted">타일을 누르면 바로 저장</small></h3>
      <label class="chk"><input type="radio" name="tool" value="view" checked> 보기</label>
      <label class="chk"><input type="radio" name="tool" value="terrain"> 지형 칠하기</label>
      <div class="brushes" id="tbrush">
        <?php foreach ($tdefs as $c => $d): ?>
          <label class="chk"><input type="radio" name="terrain" value="<?= h($c) ?>" <?= $c === 'plain' ? 'checked' : '' ?>>
            <i class="sw" style="background:<?= h($d['color']) ?>"></i><?= h($d['name']) ?></label>
        <?php endforeach; ?>
      </div>
      <label class="chk"><input type="radio" name="tool" value="feature"> 특수 지점</label>
      <div class="brushes">
        <label class="chk"><input type="radio" name="feature" value="" checked> 없음(지우기)</label>
        <?php foreach ($feats as $c => [$n]): if ($c === 'village') continue; ?>
          <label class="chk"><input type="radio" name="feature" value="<?= h($c) ?>"> <?= h($n) ?></label>
        <?php endforeach; ?>
      </div>
      <label class="chk"><input type="radio" name="tool" value="village"> 마을 옮기기</label>
      <select id="vsel" class="wide">
        <?php foreach ($pdo->query('SELECT id, name, user_name, q, r FROM vg_villages ORDER BY name') as $vv): ?>
          <option value="<?= (int)$vv['id'] ?>"><?= h($vv['name']) ?> (<?= h($vv['user_name']) ?>) <?= $vv['q'] === null ? '미배치' : '(' . $vv['q'] . ', ' . $vv['r'] . ')' ?></option>
        <?php endforeach; ?>
      </select>
      <div class="tinfo small" id="atile">타일을 누르면 정보가 보입니다.</div>
    </div>
  </div>
</section>

<section class="card">
  <h2>맵 재생성</h2>
  <p class="warn small">맵 전체를 새로 만든다. 모든 부대는 즉시 마을로 돌아오고(짐은 사라짐), 모든 마을을 다시 배치한다. 강·거점 수 등은 설정 탭 &gt; 세계 맵.</p>
  <form method="post" class="inline" onsubmit="return confirm('맵을 새로 만들까요?')">
    <?= csrf_field() ?><input type="hidden" name="do" value="map_regen">
    <label>시드 <input type="number" name="seed" value="0" title="0 = 무작위"></label>
    <label>반지름 <input type="number" name="radius" value="<?= h(S('map_radius')) ?>" min="6" max="40"></label>
    <input name="confirm" placeholder="재생성" autocomplete="off" class="w8">
    <button class="btn danger">재생성</button>
  </form>
</section>

<section class="card">
  <h2>지형 정의</h2>
  <p class="muted small">이동 비용 = 그 타일에 들어갈 때 걸리는 시간 배수. 방어·기병 보너스는 5단계 전투에서 적용. 강은 통행 불가여도 다리가 놓이면 지날 수 있다.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="save_tdefs">
    <table class="grid">
      <tr><th>지형</th><th>이름</th><th>색</th><th>이동 비용</th><th>방어 %</th><th>기병 %</th><th>통행</th><th>설명</th></tr>
      <?php foreach ($tdefs as $c => $d): $f = "t[$c]"; ?>
        <tr>
          <td><code><?= h($c) ?></code></td>
          <td><input name="<?= $f ?>[name]" value="<?= h($d['name']) ?>" class="w8"></td>
          <td><input type="color" name="<?= $f ?>[color]" value="<?= h($d['color']) ?>"></td>
          <td><input type="number" step="any" name="<?= $f ?>[move_cost]" value="<?= h($d['move_cost']) ?>" class="w4"></td>
          <td><input type="number" step="any" name="<?= $f ?>[def_bonus_pct]" value="<?= h($d['def_bonus_pct']) ?>" class="w4"></td>
          <td><input type="number" step="any" name="<?= $f ?>[cav_bonus_pct]" value="<?= h($d['cav_bonus_pct']) ?>" class="w4"></td>
          <td><input type="checkbox" name="<?= $f ?>[passable]" value="1" <?= $d['passable'] ? 'checked' : '' ?>></td>
          <td><input name="<?= $f ?>[descr]" value="<?= h($d['descr']) ?>" style="width:100%"></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <div class="sticky-save"><button class="btn primary">지형 저장</button></div>
  </form>
</section>

<script src="../assets/js/camera.js?v=<?= $v ?>"></script>
<script src="../assets/js/art.js?v=<?= filemtime(__DIR__ . '/../assets/js/art.js') ?>"></script>
<script src="../assets/js/map.js?v=<?= $v ?>"></script>
<script>
(function () {
  const csrf = <?= json_encode($csrf) ?>;
  let data = <?= json_encode(vg_state_map(), JSON_UNESCAPED_UNICODE) ?>;
  const val = (name) => (document.querySelector(`input[name="${name}"]:checked`) || {}).value;
  const info = document.getElementById('atile');
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const m = new VgMap(document.getElementById('amap'), {
    onClick: async (t) => {
      if (!t) return;
      m.sel = VgMap.key(t.q, t.r);
      const tool = val('tool');
      let body = null;
      if (tool === 'terrain') body = { do: 'paint', q: t.q, r: t.r, terrain: val('terrain') };
      else if (tool === 'feature') body = { do: 'paint', q: t.q, r: t.r, feature: val('feature') };
      else if (tool === 'village') body = { do: 'move_village', q: t.q, r: t.r, vid: +document.getElementById('vsel').value };
      if (body) {
        const r = await fetch('ajax.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ csrf }, body)) });
        const j = await r.json();
        if (!j.ok) { alert(j.error); return; }
        data = j.map;
        m.setMap(data);
      }
      const tile = m.tile(t.q, t.r), d = data.terrains[tile.t] || {};
      info.innerHTML = `<b>(${t.q}, ${t.r})</b> ${esc(d.name || tile.t)}${tile.f ? ' · ' + esc((data.features[tile.f] || {}).name || tile.f) : ''}${tile.v ? '<br>마을: ' + esc(tile.v.name) + ' (' + esc(tile.v.owner) + ')' : ''}`;
      m.draw();
    },
  });
  m.setMap(data);
  document.getElementById('azin').onclick = () => m.cam.zoomCenter(1.25);
  document.getElementById('azout').onclick = () => m.cam.zoomCenter(0.8);
  document.getElementById('afit').onclick = () => m.fit();
})();
</script>
