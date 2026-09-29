<?php
defined('VG_ADMIN') || exit;
$defs = vg_bdefs(true);
$artv = filemtime(__DIR__ . '/../assets/js/art.js');
$list = [];
foreach ($defs as $code => $d) {
    if ($d['category'] === 'wall') continue;
    $list[] = ['code' => $code, 'name' => $d['name'], 'category' => $d['category']];
}
?>
<section class="card">
  <h2>그림</h2>
  <p class="muted">게임 그림은 이미지 파일 없이 전부 코드(assets/js/art.js)로 그린다. 건물은 레벨 3단계(Lv1~3 / Lv4~7 / Lv8+).
    관리자가 새로 추가한 건물은 분류에 맞는 기본 집 모양으로 그려진다.</p>
</section>
<section class="card">
  <h2>건물</h2>
  <div class="artgrid" id="art-buildings"></div>
</section>
<section class="card">
  <h2>성벽</h2>
  <div class="artgrid wide" id="art-walls"></div>
</section>
<section class="card">
  <h2>병종 <small class="muted">(3단계부터 훈련·부대에 사용)</small></h2>
  <div class="artgrid" id="art-units"></div>
</section>
<link rel="stylesheet" href="../assets/css/art.css?v=<?= filemtime(__DIR__ . '/../assets/css/art.css') ?>">
<script src="../assets/js/art.js?v=<?= $artv ?>"></script>
<script>
(function () {
  const NS = 'http://www.w3.org/2000/svg';
  const A = window.VgArt;
  const mk = (tag, a, p) => { const n = document.createElementNS(NS, tag); for (const k in a) n.setAttribute(k, a[k]); if (p) p.appendChild(n); return n; };
  function card(host, title, w, h, draw) {
    const d = document.createElement('div');
    d.className = 'artcard';
    const svg = mk('svg', { viewBox: `${-w / 2} ${-h + 50} ${w} ${h}`, width: w, height: h });
    d.appendChild(svg);
    const cap = document.createElement('div');
    cap.textContent = title;
    d.appendChild(cap);
    host.appendChild(d);
    draw(mk('g', {}, svg));
  }
  const tile = (g) => mk('polygon', { points: '-80,0 0,-40 80,0 0,40', fill: '#c8b27e', stroke: '#8f7440', 'stroke-width': 1.5 }, g);

  const bl = <?= json_encode($list, JSON_UNESCAPED_UNICODE) ?>;
  const bh = document.getElementById('art-buildings');
  card(bh, '공사 중', 180, 200, (g) => { tile(g); A.scaffold(mk('g', {}, g)); });
  for (const b of bl) for (const [lv, lab] of [[1, 'Lv1~3'], [4, 'Lv4~7'], [8, 'Lv8+']]) {
    card(bh, `${b.name} ${lab}`, 180, 200, (g) => { tile(g); A.building(mk('g', {}, g), b.code, lv, b.category); });
  }

  const wh = document.getElementById('art-walls');
  for (const [lv, lab] of [[1, 'Lv1~3 목책'], [4, 'Lv4~7 석벽'], [8, 'Lv8+ 높은 석벽·성문']]) {
    card(wh, `성벽 ${lab}`, 360, 260, (g) => {
      const iso = (u, v) => A.iso(u, v, 0);
      const Cn = { top: iso(-70, -70), right: iso(70, -70), bottom: iso(70, 70), left: iso(-70, 70) };
      mk('polygon', { points: [Cn.top, Cn.right, Cn.bottom, Cn.left].map((p) => p.join(',')).join(' '), fill: '#cdb98a', stroke: '#a88f5c' }, g);
      A.wall(mk('g', {}, g), 'back', lv, Cn);
      A.building(mk('g', {}, g), 'hall', lv, 'hall');
      A.wall(mk('g', {}, g), 'front', lv, Cn);
    });
  }

  const uh = document.getElementById('art-units');
  for (const code in A.UNIT_NAMES) {
    card(uh, A.UNIT_NAMES[code], 130, 120, (g) => {
      mk('ellipse', { cx: 0, cy: 0, rx: 30, ry: 7, fill: 'rgba(60,40,20,.18)' }, g);
      A.unit(mk('g', { transform: 'scale(1.4)' }, g), code);
    });
  }
})();
</script>
