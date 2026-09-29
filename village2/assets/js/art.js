// 게임 그림 전부를 코드로 그린다 (이미지 파일 없음).
//  - VgArt.building(g, code, level, category) : 건물 (레벨 3단계 Lv1~3 / 4~7 / 8+), 반환값 = 그림 높이(px)
//  - VgArt.scaffold(g)                        : 공사 중 비계
//  - VgArt.wall(g, which, level, corners)     : 성벽 뒤/앞 (레벨 3단계: 목책 / 석벽 / 높은 석벽+원탑)
//  - VgArt.unit(g, code, opts)                : 병종 그림 (발끝 기준, 오른쪽을 봄)
// 좌표: 타일 중심 기준 아이소메트릭 (u, v, z) → 화면 x = u - v, y = (u + v) / 2 - z. 타일은 u, v 가 ±40.
// 칠하는 순서 = 뒤(u+v 작음)에서 앞, 바닥 → 위.
(function (global) {
  'use strict';

  const NS = 'http://www.w3.org/2000/svg';
  const OUT = '#3a2616';
  const C = {
    wood: '#a8743f', woodDark: '#7c5230', plank: '#c08e57', log: '#8a5a32', logEnd: '#d8b27a',
    thatch: '#d4ae55', stone: '#c2b8a3', stoneDark: '#9d927d', stoneLight: '#d8cfbb', plaster: '#ecdfc0',
    roofRed: '#b0452e', roofSlate: '#58606b', roofBrown: '#7b5230', roofGreen: '#46703a', roofBlue: '#3f5f82',
    soil: '#8d6538', grass: '#8fb35a', crop: '#d8b845', cropLight: '#eed771', gold: '#e6b83a', iron: '#6f7a86',
    red: '#b3261e', white: '#f4ecd8', dark: '#2e2118', glow: '#f39c12', glowHot: '#ffd35a', skin: '#f0c8a0',
    leaf: '#4f7d38', leafDark: '#3b6429', steel: '#c9ced6', rope: '#b99a6a',
  };

  // 현재 그림의 가장 높은 점(화면 y 최소)을 추적 → 이름표 위치 계산용
  // 중첩 그룹(translate/scale) 안에서도 바깥 기준 높이로 기록하도록 현재 변환을 들고 다닌다
  let minY = 0;
  let off = { y: 0, s: 1 };
  const tk = (y) => { const yy = off.y + y * off.s; if (yy < minY) minY = yy; };

  function el(tag, a, parent) {
    const n = document.createElementNS(NS, tag);
    for (const k in a) if (a[k] != null) n.setAttribute(k, a[k]);
    if (parent) parent.appendChild(n);
    return n;
  }
  const iso = (u, v, z) => [u - v, (u + v) / 2 - (z || 0)];
  const P = (pts) => pts.map((p) => { tk(p[1]); return p[0].toFixed(1) + ',' + p[1].toFixed(1); }).join(' ');

  function shade(hex, f) {
    const n = parseInt(hex.slice(1), 16);
    const k = (c) => Math.max(0, Math.min(255, Math.round(f < 0 ? c * (1 + f) : c + (255 - c) * f)));
    return '#' + ((1 << 24) + (k(n >> 16) << 16) + (k((n >> 8) & 255) << 8) + k(n & 255)).toString(16).slice(1);
  }

  // ───────────── 기본 도형 ─────────────
  const poly = (g, pts, fill, sw, extra) => el('polygon', Object.assign({ points: P(pts), fill, stroke: sw === 0 ? 'none' : OUT, 'stroke-width': sw || 1, 'stroke-linejoin': 'round' }, extra), g);
  const line = (g, a, b, stroke, w, extra) => { tk(a[1]); tk(b[1]); return el('line', Object.assign({ x1: a[0], y1: a[1], x2: b[0], y2: b[1], stroke, 'stroke-width': w || 1, 'stroke-linecap': 'round' }, extra), g); };
  const circ = (g, x, y, r, fill, extra) => { tk(y - r); return el('circle', Object.assign({ cx: x, cy: y, r, fill, stroke: OUT, 'stroke-width': 1 }, extra), g); };
  const ell = (g, x, y, rx, ry, fill, extra) => { tk(y - ry); return el('ellipse', Object.assign({ cx: x, cy: y, rx, ry, fill, stroke: OUT, 'stroke-width': 1 }, extra), g); };
  const path = (g, d, fill, top, extra) => { if (top != null) tk(top); return el('path', Object.assign({ d, fill, stroke: OUT, 'stroke-width': 1, 'stroke-linejoin': 'round' }, extra), g); };
  /** 옮기고(x,y) 키운(s) 그룹 안에 fn 으로 그린다 */
  function inGrp(g, x, y, s, fn) {
    s = s || 1;
    const q = el('g', { transform: `translate(${x},${y})` + (s !== 1 ? ` scale(${s})` : '') }, g);
    const saved = off;
    off = { y: off.y + y * off.s, s: off.s * s };
    try { fn(q); } finally { off = saved; }
    return q;
  }

  /** 상자. col 은 색 하나(면 음영 자동) 또는 {l, r, t} */
  function box(g, u, v, z, du, dv, dz, col, o) {
    const c = typeof col === 'string' ? { l: col, r: shade(col, -0.22), t: shade(col, 0.16) } : col;
    const U = u + du, V = v + dv, Z = z + dz;
    poly(g, [iso(u, V, z), iso(U, V, z), iso(U, V, Z), iso(u, V, Z)], c.l);
    poly(g, [iso(U, v, z), iso(U, V, z), iso(U, V, Z), iso(U, v, Z)], c.r);
    if (!(o && o.noTop)) poly(g, [iso(u, v, Z), iso(U, v, Z), iso(U, V, Z), iso(u, V, Z)], c.t);
  }
  // 앞왼쪽 면(v 고정), 앞오른쪽 면(u 고정) 위의 사각형
  const qL = (v, u0, u1, z0, z1) => [iso(u0, v, z0), iso(u1, v, z0), iso(u1, v, z1), iso(u0, v, z1)];
  const qR = (u, v0, v1, z0, z1) => [iso(u, v0, z0), iso(u, v1, z0), iso(u, v1, z1), iso(u, v0, z1)];

  /** 앞왼쪽 면 아치문 */
  function archL(g, v, uc, w, h, fill) {
    const pts = [iso(uc - w / 2, v, 0)];
    for (let i = 0; i <= 8; i++) {
      const a = Math.PI - (Math.PI * i) / 8;
      pts.push(iso(uc + (w / 2) * Math.cos(a), v, h - w / 2 + (w / 2) * Math.sin(a)));
    }
    pts.push(iso(uc + w / 2, v, 0));
    return poly(g, pts, fill || C.dark);
  }
  function archR(g, u, vc, w, h, fill, z0) {
    z0 = z0 || 0;
    const pts = [iso(u, vc - w / 2, z0)];
    for (let i = 0; i <= 8; i++) {
      const a = Math.PI - (Math.PI * i) / 8;
      pts.push(iso(u, vc + (w / 2) * Math.cos(a), z0 + h - w / 2 + (w / 2) * Math.sin(a)));
    }
    pts.push(iso(u, vc + w / 2, z0));
    return poly(g, pts, fill || C.dark);
  }
  const winL = (g, v, u, z, w, h, fill) => poly(g, qL(v, u - w / 2, u + w / 2, z, z + h), fill || '#3d2c1c');
  const winR = (g, u, v, z, w, h, fill) => poly(g, qR(u, v - w / 2, v + w / 2, z, z + h), fill || '#2e2118');

  /** 벽면 가로줄(널빤지·벽돌 줄) */
  function stripesL(g, v, u0, u1, z0, z1, step, color) {
    for (let z = z0 + step; z < z1 - 0.5; z += step) line(g, iso(u0, v, z), iso(u1, v, z), color, 0.8);
  }
  function stripesR(g, u, v0, v1, z0, z1, step, color) {
    for (let z = z0 + step; z < z1 - 0.5; z += step) line(g, iso(u, v0, z), iso(u, v1, z), color, 0.8);
  }
  /** 목조 골조 (회벽 위 나무 기둥·가새) */
  function timberL(g, v, u0, u1, z0, z1, n) {
    for (let i = 0; i <= n; i++) { const u = u0 + ((u1 - u0) * i) / n; line(g, iso(u, v, z0), iso(u, v, z1), C.woodDark, 2); }
    line(g, iso(u0, v, z1), iso(u1, v, z1), C.woodDark, 2);
    for (let i = 0; i < n; i += 2) { const a = u0 + ((u1 - u0) * i) / n, b = u0 + ((u1 - u0) * (i + 1)) / n; line(g, iso(a, v, z0), iso(b, v, z1), C.woodDark, 1.5); }
  }
  function timberR(g, u, v0, v1, z0, z1, n) {
    for (let i = 0; i <= n; i++) { const v = v0 + ((v1 - v0) * i) / n; line(g, iso(u, v, z0), iso(u, v, z1), shade(C.woodDark, -0.2), 2); }
    line(g, iso(u, v0, z1), iso(u, v1, z1), shade(C.woodDark, -0.2), 2);
  }

  /** 박공지붕. axis 'u' 면 용마루가 u 방향(오른쪽 끝에 박공면), 'v' 면 v 방향(왼쪽 앞에 박공면) */
  function gable(g, u, v, z, du, dv, rh, col, axis, endCol, o) {
    o = o == null ? 3 : o;
    const U = u + du, V = v + dv;
    const back = shade(col, -0.3), front = col, side = shade(col, -0.14);
    if (axis === 'u') {
      const vm = v + dv / 2, r0 = iso(u - o, vm, z + rh), r1 = iso(U + o, vm, z + rh);
      poly(g, [iso(u - o, v - o, z), iso(U + o, v - o, z), r1, r0], back);
      // 박공 끝: 처마 밑 그늘 띠 + 벽 삼각형
      poly(g, [iso(U + o, v - o, z), iso(U + o, V + o, z), r1], shade(col, -0.45));
      poly(g, [iso(U, v, z), iso(U, V, z), iso(U, vm, z + rh - o * rh / (dv / 2))], endCol);
      const e0 = iso(u - o, V + o, z), e1 = iso(U + o, V + o, z);
      poly(g, [e0, e1, r1, r0], front);
      for (let i = 1; i < 4; i++) { const f = i / 4; line(g, lerp(e0, r0, f), lerp(e1, r1, f), shade(col, -0.2), 0.8); }
    } else {
      const um = u + du / 2, r0 = iso(um, v - o, z + rh), r1 = iso(um, V + o, z + rh);
      poly(g, [iso(u - o, v - o, z), iso(u - o, V + o, z), r1, r0], back);
      poly(g, [iso(u - o, V + o, z), iso(U + o, V + o, z), r1], shade(col, -0.45));
      poly(g, [iso(u, V, z), iso(U, V, z), iso(um, V, z + rh - o * rh / (du / 2))], endCol);
      const e0 = iso(U + o, v - o, z), e1 = iso(U + o, V + o, z);
      poly(g, [e0, e1, r1, r0], side);
      for (let i = 1; i < 4; i++) { const f = i / 4; line(g, lerp(e0, r0, f), lerp(e1, r1, f), shade(col, -0.3), 0.8); }
    }
  }
  const lerp = (a, b, f) => [a[0] + (b[0] - a[0]) * f, a[1] + (b[1] - a[1]) * f];

  /** 모임지붕(사각뿔) */
  function hip(g, u, v, z, du, dv, rh, col, o) {
    o = o == null ? 3 : o;
    const a = iso(u + du / 2, v + dv / 2, z + rh);
    const A = iso(u - o, v - o, z), B = iso(u + du + o, v - o, z), Cc = iso(u + du + o, v + dv + o, z), D = iso(u - o, v + dv + o, z);
    poly(g, [A, B, a], shade(col, -0.35));
    poly(g, [A, D, a], shade(col, -0.3));
    poly(g, [D, Cc, a], col);
    poly(g, [B, Cc, a], shade(col, -0.18));
    return a;
  }

  /** 외쪽지붕 (뒤가 높고 앞이 낮음) */
  function lean(g, u, v, du, dv, zBack, zFront, col) {
    poly(g, [iso(u, v, zBack), iso(u + du, v, zBack), iso(u + du, v + dv, zFront), iso(u, v + dv, zFront)], col);
    poly(g, [iso(u + du, v, zBack), iso(u + du, v + dv, zFront), iso(u + du, v + dv, zFront - 2), iso(u + du, v, zBack - 2)], shade(col, -0.3));
  }

  /** 원통 (탑·굴뚝·통). 반환: 윗면 정보 */
  function cyl(g, u, v, z, r, h, col, topCol) {
    const [cx, yb] = iso(u, v, z), yt = yb - h, rx = r * 1.414, ry = rx / 2;
    tk(yt - ry);
    path(g, `M${cx - rx},${yb} A${rx},${ry} 0 0 0 ${cx + rx},${yb} L${cx + rx},${yt} L${cx - rx},${yt} Z`, col);
    const sx = cx + rx * 0.3, sy = ry * Math.sqrt(1 - 0.09);
    el('path', { d: `M${sx},${yb + sy} A${rx},${ry} 0 0 0 ${cx + rx},${yb} L${cx + rx},${yt} A${rx},${ry} 0 0 1 ${sx},${yt + sy} Z`, fill: shade(col, -0.22) }, g);
    el('path', { d: `M${cx - rx},${yb} A${rx},${ry} 0 0 0 ${cx + rx},${yb} L${cx + rx},${yt} M${cx - rx},${yt} L${cx - rx},${yb}`, fill: 'none', stroke: OUT, 'stroke-width': 1 }, g);
    if (topCol !== null) ell(g, cx, yt, rx, ry, topCol || shade(col, 0.15));
    return { x: cx, y: yt, rx, ry };
  }
  /** 원뿔 지붕 (화면 좌표 밑면 중심) */
  function cone(g, x, y, rx, h, col) {
    const ry = rx / 2;
    tk(y - h);
    path(g, `M${x - rx},${y} A${rx},${ry} 0 0 0 ${x + rx},${y} L${x},${y - h} Z`, col);
    el('path', { d: `M${x + rx * 0.2},${y + ry * 0.98} A${rx},${ry} 0 0 0 ${x + rx},${y} L${x},${y - h} Z`, fill: shade(col, -0.25), stroke: 'none' }, g);
    path(g, `M${x - rx},${y} A${rx},${ry} 0 0 0 ${x + rx},${y} L${x},${y - h} Z`, 'none');
    return [x, y - h];
  }

  /** 깃발 (화면 좌표, 깃대 밑) */
  function flag(g, x, y, h, color) {
    line(g, [x, y], [x, y - h], C.woodDark, 2);
    circ(g, x, y - h - 1, 1.8, C.gold, { 'stroke-width': 0.6 });
    const f = el('g', { class: 'vg-flag' }, g);
    path(f, `M${x},${y - h + 1} C${x + 7},${y - h - 3} ${x + 12},${y - h + 3} ${x + 19},${y - h + 1} L${x + 19},${y - h + 11} C${x + 12},${y - h + 13} ${x + 7},${y - h + 7} ${x},${y - h + 10} Z`, color || C.red, y - h - 3);
  }
  /** 굴뚝 연기 (움직임은 CSS .vg-smoke) */
  function smoke(g, x, y) {
    const s = el('g', { class: 'vg-smoke-wrap' }, g);
    for (let i = 0; i < 3; i++) {
      tk(y - 22);
      el('circle', { cx: x, cy: y - 6, r: 4 + i, fill: '#e8e2d6', class: 'vg-smoke', style: `animation-delay:${-i}s` }, s);
    }
  }
  /** 불빛 (아궁이·용광로) */
  function glowL(g, v, u, w, h) {
    archL(g, v, u, w, h, '#5a1e08');
    const inner = archL(g, v, u, w * 0.7, h * 0.75, C.glow);
    inner.setAttribute('class', 'vg-glow');
    inner.setAttribute('stroke', 'none');
  }

  // ───────────── 소품 ─────────────
  function pine(g, u, v, s) {
    const [x, y] = iso(u, v, 0);
    s = s || 1;
    el('rect', { x: x - 2 * s, y: y - 8 * s, width: 4 * s, height: 8 * s, fill: C.woodDark, stroke: OUT, 'stroke-width': 0.8 }, g);
    for (let i = 0; i < 3; i++) {
      const by = y - 6 * s - i * 9 * s, w = (13 - i * 3) * s;
      poly(g, [[x - w, by], [x + w, by], [x, by - 14 * s]], i % 2 ? C.leaf : C.leafDark);
    }
  }
  function bush(g, u, v, s) {
    const [x, y] = iso(u, v, 0);
    s = s || 1;
    circ(g, x - 4 * s, y - 4 * s, 5 * s, C.leafDark);
    circ(g, x + 3 * s, y - 5 * s, 6 * s, C.leaf);
  }
  function crate(g, u, v, z, s) {
    s = s || 7;
    box(g, u, v, z || 0, s, s, s, C.plank);
    const V = v + s;
    line(g, iso(u, V, z || 0), iso(u + s, V, (z || 0) + s), C.woodDark, 0.8);
  }
  function barrel(g, u, v, z) {
    const t = cyl(g, u, v, z || 0, 3.4, 9, C.wood, shade(C.wood, 0.2));
    line(g, [t.x - t.rx, t.y + 3], [t.x + t.rx, t.y + 3], C.dark, 0.8);
    line(g, [t.x - t.rx, t.y + 7], [t.x + t.rx, t.y + 7], C.dark, 0.8);
  }
  function sack(g, u, v) {
    const [x, y] = iso(u, v, 0);
    path(g, `M${x - 4},${y} Q${x - 6},${y - 7} ${x - 2},${y - 9} L${x + 2},${y - 9} Q${x + 6},${y - 7} ${x + 4},${y} Z`, '#d9c48f', y - 10);
    line(g, [x - 2, y - 8], [x + 2, y - 8], C.rope, 1);
  }
  function hay(g, u, v, z) { box(g, u, v, z || 0, 8, 6, 5, '#dcc05a'); }
  /** 통나무 (u 방향으로 누움) */
  function logU(g, u0, u1, v, z, r) {
    const a = iso(u0, v, z + r), b = iso(u1, v, z + r);
    line(g, a, b, OUT, r * 2 + 1.5, { 'stroke-linecap': 'butt' });
    line(g, a, b, C.log, r * 2, { 'stroke-linecap': 'butt' });
    line(g, [a[0], a[1] - r * 0.5], [b[0], b[1] - r * 0.5], shade(C.log, 0.2), r * 0.5, { 'stroke-linecap': 'butt' });
    ell(g, b[0], b[1], r * 0.8, r, C.logEnd, { 'stroke-width': 0.8 });
    el('circle', { cx: b[0], cy: b[1], r: r * 0.35, fill: 'none', stroke: shade(C.logEnd, -0.3), 'stroke-width': 0.6 }, g);
  }
  function logPile(g, u0, u1, v, n) {
    const r = 3.2;
    const rows = n >= 6 ? [[0, 0], [2 * r, 0], [4 * r, 0], [r, 1.7 * r], [3 * r, 1.7 * r], [2 * r, 3.4 * r]] : [[0, 0], [2 * r, 0], [r, 1.7 * r]];
    rows.sort((a, b) => a[0] - b[0] || a[1] - b[1]);
    for (const [dv, z] of rows) logU(g, u0, u1, v + dv, z, r);
  }
  function fence(g, pts, h) {
    h = h || 7;
    for (let i = 0; i < pts.length - 1; i++) {
      const [u0, v0] = pts[i], [u1, v1] = pts[i + 1];
      line(g, iso(u0, v0, h * 0.8), iso(u1, v1, h * 0.8), C.woodDark, 1.5);
      line(g, iso(u0, v0, h * 0.4), iso(u1, v1, h * 0.4), C.woodDark, 1.5);
    }
    for (const [u, v] of pts) line(g, iso(u, v, 0), iso(u, v, h), C.woodDark, 2.2);
  }
  function banner(g, v, u, z, col) {
    poly(g, [iso(u - 3, v, z), iso(u + 3, v, z), iso(u + 3, v, z - 12), iso(u, v, z - 15), iso(u - 3, v, z - 12)], col || C.red);
    circ(g, ...iso(u, v, z - 6), 1.5, C.gold, { 'stroke-width': 0.5 });
  }
  function anvil(g, u, v) {
    const [x, y] = iso(u, v, 0);
    poly(g, [[x - 3, y], [x + 3, y], [x + 2, y - 4], [x - 2, y - 4]], '#4a4a4a');
    poly(g, [[x - 7, y - 4], [x + 6, y - 4], [x + 8, y - 7], [x - 5, y - 7], [x - 9, y - 6]], '#5d5d5d');
  }
  function ingots(g, u, v, n) {
    const spots = [[0, 0, 0], [4, 0, 0], [0, 5, 0], [4, 5, 0], [2, 2, 2.4], [2, 7, 2.4], [6, 2, 2.4], [3, 4, 4.8]].slice(0, n);
    spots.sort((a, b) => a[2] - b[2] || (a[0] + a[1]) - (b[0] + b[1]));
    for (const [du, dv, z] of spots) box(g, u + du, v + dv, z, 3.6, 4.6, 2.4, { l: C.gold, r: shade(C.gold, -0.2), t: '#ffe28a' });
  }
  function orePile(g, u, v, col) {
    const [x, y] = iso(u, v, 0);
    col = col || '#6c7480';
    poly(g, [[x - 10, y], [x - 7, y - 6], [x - 2, y - 9], [x + 4, y - 7], [x + 9, y - 2], [x + 10, y]], col);
    poly(g, [[x - 2, y - 9], [x + 4, y - 7], [x + 1, y - 3], [x - 4, y - 4]], shade(col, 0.2), 0.6);
    circ(g, x + 5, y - 3, 1.4, '#a7b7c9', { 'stroke-width': 0 });
  }
  function cart(g, x, y, load) {
    inGrp(g, x, y, 1, (q) => {
      poly(q, [[-8, -4], [8, -4], [10, -12], [-10, -12]], C.wood);
      if (load) path(q, 'M-9,-12 Q-4,-19 1,-15 Q5,-19 9,-12 Z', load, -19);
      circ(q, -5, -2, 3, C.dark);
      circ(q, 5, -2, 3, C.dark);
    });
  }
  function target(g, u, v) {
    const [x, y] = iso(u, v, 0);
    line(g, [x - 5, y], [x - 2, y - 14], C.woodDark, 1.5);
    line(g, [x + 5, y], [x + 2, y - 14], C.woodDark, 1.5);
    const rs = [8, 6.2, 4.4, 2.6, 1];
    const cols = ['#f4ecd8', C.red, '#f4ecd8', C.red, C.gold];
    rs.forEach((r, i) => circ(g, x, y - 16, r, cols[i], { 'stroke-width': i ? 0.5 : 1 }));
    line(g, [x - 3, y - 18], [x + 1, y - 16], C.woodDark, 1);
  }
  function dummy(g, u, v) {
    const [x, y] = iso(u, v, 0);
    line(g, [x, y], [x, y - 24], C.woodDark, 2.5);
    line(g, [x - 8, y - 17], [x + 8, y - 17], C.woodDark, 2);
    path(g, `M${x - 5},${y - 20} Q${x - 7},${y - 10} ${x - 3},${y - 6} L${x + 3},${y - 6} Q${x + 7},${y - 10} ${x + 5},${y - 20} Z`, '#cdb07a', y - 21);
    circ(g, x, y - 25, 4, '#cdb07a');
  }
  function rack(g, u, v) {
    const [x, y] = iso(u, v, 0);
    line(g, [x - 8, y], [x - 8, y - 14], C.woodDark, 2);
    line(g, [x + 8, y + 4], [x + 8, y - 10], C.woodDark, 2);
    line(g, [x - 8, y - 11], [x + 8, y - 7], C.woodDark, 1.5);
    for (let i = 0; i < 3; i++) {
      const sx = x - 5 + i * 5;
      line(g, [sx, y + 1 + i], [sx + 2, y - 26 + i], C.woodDark, 1.2);
      poly(g, [[sx + 2, y - 26 + i], [sx + 0.5, y - 22 + i], [sx + 3.5, y - 22 + i]], C.steel, 0.6);
    }
  }
  function horse(g, x, y, s, col) {
    s = s || 1;
    col = col || '#8a5a32';
    return inGrp(g, x, y, s, (q) => horseBody(q, col));
  }
  function horseBody(q, col) {
    const dk = shade(col, -0.3);
    line(q, [-8, -10], [-9, 0], dk, 2.4);
    line(q, [7, -10], [8, 0], dk, 2.4);
    path(q, 'M-13,-12 Q-16,-6 -14,-2', 'none', null, { stroke: '#3a2616', 'stroke-width': 2.5 });
    ell(q, 0, -13, 12, 6, col);
    line(q, [-5, -10], [-6, 0], col, 2.4);
    line(q, [10, -10], [11, 0], col, 2.4);
    poly(q, [[7, -17], [12, -27], [16, -25], [12, -12]], col);
    ell(q, 16, -25, 5, 3, col, { transform: 'rotate(28 16 -25)' });
    path(q, 'M8,-17 Q11,-26 14,-29 L12,-24 Z', '#3a2616');
    poly(q, [[13, -29], [14, -33], [15, -28]], dk, 0.6);
    circ(q, 16.5, -26.5, 0.8, '#111', { 'stroke-width': 0 });
  }

  // ───────────── 건물 ─────────────
  const B = {};

  B.hall = function (g, t) {
    if (t === 1) {
      box(g, -26, -16, 0, 52, 32, 20, C.wood);
      stripesL(g, 16, -26, 26, 0, 20, 4, shade(C.wood, -0.25));
      stripesR(g, 26, -16, 16, 0, 20, 4, shade(C.wood, -0.4));
      archL(g, 16, 0, 11, 15);
      winL(g, 16, -15, 8, 5, 5); winL(g, 16, 15, 8, 5, 5);
      winR(g, 26, 0, 8, 5, 5);
      gable(g, -26, -16, 20, 52, 32, 20, C.thatch, 'u', shade(C.wood, -0.2), 5);
      flag(g, ...iso(-31, 18, 0), 46, C.red);
    } else if (t === 2) {
      box(g, -32, -28, 0, 16, 16, 46, C.stone);
      stripesL(g, -12, -32, -16, 0, 46, 5, C.stoneDark);
      winL(g, -12, -24, 32, 3, 6);
      const tp = hip(g, -32, -28, 46, 16, 16, 16, C.roofSlate, 2);
      flag(g, tp[0], tp[1], 18, C.red);
      box(g, -24, -20, 0, 52, 40, 14, C.stone);
      stripesL(g, 20, -24, 28, 0, 14, 4.5, C.stoneDark);
      box(g, -24, -20, 14, 52, 40, 16, C.plaster, { noTop: true });
      timberL(g, 20, -24, 28, 14, 30, 6);
      timberR(g, 28, -20, 20, 14, 30, 4);
      archL(g, 20, 2, 12, 17);
      winL(g, 20, -15, 19, 5, 6); winL(g, 20, 19, 19, 5, 6);
      winR(g, 28, -8, 19, 5, 6); winR(g, 28, 8, 19, 5, 6);
      gable(g, -24, -20, 30, 52, 40, 24, C.roofRed, 'u', C.plaster, 4);
      banner(g, 20, -15, 12, C.red); banner(g, 20, 19, 12, C.red);
    } else {
      box(g, -32, -26, 0, 64, 50, 24, C.stone);
      stripesL(g, 24, -32, 32, 0, 24, 4, C.stoneDark);
      stripesR(g, 32, -26, 24, 0, 24, 4, shade(C.stoneDark, -0.2));
      // 뒤쪽 여장
      for (let u = -30; u < 30; u += 7) box(g, u, -26, 24, 3.5, 3.5, 4, C.stone);
      for (let v = -20; v < 22; v += 7) box(g, -32, v, 24, 3.5, 3.5, 4, C.stone);
      // 가운데 본성
      box(g, -14, -14, 24, 28, 28, 34, C.stoneLight);
      stripesL(g, 14, -14, 14, 24, 58, 5, C.stoneDark);
      winL(g, 14, -6, 40, 4, 8); winL(g, 14, 6, 40, 4, 8);
      winR(g, 14, 0, 40, 4, 8);
      const tp = hip(g, -14, -14, 58, 28, 28, 22, C.roofBlue, 3);
      flag(g, tp[0], tp[1], 22, C.red);
      // 앞쪽 여장
      for (let u = -30; u < 32; u += 7) box(g, u, 21.5, 24, 3.5, 3.5, 4, C.stone);
      for (let v = -24; v < 24; v += 7) box(g, 28.5, v, 24, 3.5, 3.5, 4, C.stone);
      archL(g, 24, 0, 14, 18);
      banner(g, 24, -20, 20, C.red); banner(g, 24, 20, 20, C.red);
      // 앞 모서리 원탑
      for (const [u, v] of [[-32, 24], [32, -26]]) {
        const top = cyl(g, u, v, 0, 7, 36, C.stone);
        cone(g, top.x, top.y, top.rx + 2, 18, C.roofRed);
      }
    }
  };

  B.farm = function (g, t) {
    const plot = t === 1 ? [-34, -30, 62, 60] : [-6, -22, 40, 56];
    box(g, plot[0], plot[1], 0, plot[2], plot[3], 2.5, { l: shade(C.soil, -0.1), r: shade(C.soil, -0.3), t: C.soil });
    const rows = Math.floor(plot[3] / 8);
    for (let i = 0; i < rows; i++) {
      const v = plot[1] + 4 + i * 8;
      line(g, iso(plot[0] + 3, v, 2.5), iso(plot[0] + plot[2] - 3, v, 2.5), C.crop, 5, { 'stroke-dasharray': '3 1.6' });
      line(g, iso(plot[0] + 3, v, 5), iso(plot[0] + plot[2] - 3, v, 5), C.cropLight, 1.6, { 'stroke-dasharray': '2 2.6' });
    }
    if (t === 1) {
      const [x, y] = iso(4, 0, 2.5);
      line(g, [x, y], [x, y - 26], C.woodDark, 1.8);
      line(g, [x - 9, y - 18], [x + 9, y - 18], C.woodDark, 1.6);
      path(g, `M${x - 5},${y - 21} L${x + 5},${y - 21} L${x + 4},${y - 10} L${x - 4},${y - 10} Z`, '#6f8fb0', y - 21);
      circ(g, x, y - 25, 3.5, '#e8d49a');
      path(g, `M${x - 7},${y - 27} L${x + 7},${y - 27} L${x + 2},${y - 32} L${x - 2},${y - 32} Z`, C.thatch, y - 32);
      hay(g, 30, 22);
      return;
    }
    // 헛간 (뒤)
    box(g, -36, -36, 0, 24, 28, 17, '#a84632');
    const V = -8;
    poly(g, qL(V, -30, -18, 0, 12), '#7e2e20');
    line(g, iso(-30, V, 0), iso(-18, V, 12), C.white, 1.3);
    line(g, iso(-18, V, 0), iso(-30, V, 12), C.white, 1.3);
    gable(g, -36, -36, 17, 24, 28, 13, '#6e3a26', 'v', '#a84632');
    if (t === 3) {
      const top = cyl(g, 20, -30, 0, 7, 38, '#cfc6b2');
      path(g, `M${top.x - top.rx},${top.y} A${top.rx},${top.rx * 0.9} 0 0 1 ${top.x + top.rx},${top.y} Z`, C.roofBlue, top.y - top.rx);
      fence(g, [[-8, 36], [36, 36], [36, -24]], 7);
      pine(g, -34, 24, 0.9);
    }
    hay(g, -14, 20); hay(g, -10, 28);
    if (t === 3) sack(g, -24, 30);
  };

  B.lumber = function (g, t) {
    if (t === 1) {
      const posts = [[-22, -28], [12, -28]];
      for (const [u, v] of posts) line(g, iso(u, v, 0), iso(u, v, 20), C.woodDark, 2.6);
      box(g, -18, -24, 0, 26, 14, 6, C.plank);
      box(g, -16, -22, 6, 22, 10, 3, shade(C.plank, 0.1));
      for (const [u, v] of [[-22, -4], [12, -4]]) line(g, iso(u, v, 0), iso(u, v, 14), C.woodDark, 2.6);
      lean(g, -24, -30, 38, 28, 21, 14, C.roofBrown);
      logPile(g, -18, 10, 8, 3);
      const [x, y] = iso(24, 14, 0);
      cyl(g, 24, 14, 0, 5, 6, C.log, C.logEnd);
      line(g, [x - 1, y - 9], [x + 7, y - 20], C.woodDark, 1.6);
      poly(g, [[x - 3, y - 12], [x + 2, y - 9], [x + 1, y - 5], [x - 3, y - 7]], C.steel, 0.8);
      return;
    }
    const big = t === 3;
    if (big) { pine(g, -36, -36, 1.1); pine(g, 26, -38, 1); }
    else pine(g, 20, -36, 0.9);
    const [u, v, du, dv, h] = big ? [-32, -30, 44, 32, 26] : [-30, -28, 38, 28, 20];
    box(g, u, v, 0, du, dv, h, C.wood);
    stripesL(g, v + dv, u, u + du, 0, h, 4, shade(C.wood, -0.25));
    stripesR(g, u + du, v, v + dv, 0, h, 4, shade(C.wood, -0.4));
    poly(g, qL(v + dv, u + 6, u + 18, 0, 14), C.dark);
    // 둥근 톱
    const [sx, sy] = iso(u + du, v + dv / 2, h * 0.45);
    circ(g, sx, sy, 7, C.steel, { 'stroke-dasharray': '2 1', 'stroke-width': 1.6 });
    circ(g, sx, sy, 1.5, '#555');
    gable(g, u, v, h, du, dv, 16, C.roofBrown, 'u', shade(C.wood, -0.2));
    if (big) {
      // 기중기
      const [bx, by] = iso(22, -10, 0);
      line(g, [bx, by], [bx, by - 56], C.woodDark, 3);
      line(g, [bx, by - 54], [bx - 26, by - 44], C.woodDark, 2.5);
      line(g, [bx, by - 36], [bx - 14, by - 49], C.woodDark, 1.5);
      line(g, [bx - 24, by - 45], [bx - 24, by - 22], C.rope, 1);
      logU(g, -2, 16, 6, 22 - 2, 2.6);
    }
    logPile(g, -16, 14, 10, big ? 6 : 3);
    box(g, 20, 14, 0, 14, 8, 2, C.plank); box(g, 20, 14, 2, 14, 8, 2, shade(C.plank, 0.08)); box(g, 20, 14, 4, 14, 8, 2, C.plank);
  };

  B.mine = function (g, t) {
    const k = [0, 1, 1.12, 1.25][t];
    const S = (pts) => pts.map(([x, y]) => [x * k, y * k]);
    const rock = '#958c7e';
    poly(g, S([[-58, 8], [-54, -8], [-40, -26], [-18, -42], [4, -46], [24, -34], [44, -20], [58, 2], [36, 20], [0, 26], [-34, 22]]), rock);
    poly(g, S([[-40, -26], [-18, -42], [4, -46], [-6, -24], [-26, -12]]), shade(rock, 0.18), 0.8);
    poly(g, S([[4, -46], [24, -34], [44, -20], [22, -8], [-6, -24]]), shade(rock, -0.12), 0.8);
    poly(g, S([[44, -20], [58, 2], [36, 20], [22, -8]]), shade(rock, -0.28), 0.8);
    poly(g, S([[-58, 8], [-54, -8], [-40, -26], [-26, -12], [-30, 10]]), shade(rock, 0.08), 0.8);
    // 갱도 입구 (앞왼쪽)
    const ex = -18 * k, ey = 14 * k;
    if (t === 3) {
      path(g, `M${ex - 14},${ey} L${ex - 14},${ey - 16} Q${ex},${ey - 32} ${ex + 14},${ey - 16} L${ex + 14},${ey} Z`, C.stone, ey - 26);
      path(g, `M${ex - 9},${ey} L${ex - 9},${ey - 14} Q${ex},${ey - 25} ${ex + 9},${ey - 14} L${ex + 9},${ey} Z`, '#1c140e');
      circ(g, ex + 17, ey - 16, 2.5, C.glowHot, { class: 'vg-glow' });
    } else {
      poly(g, [[ex - 10, ey], [ex - 10, ey - 18], [ex + 10, ey - 18], [ex + 10, ey]], '#1c140e');
      line(g, [ex - 11, ey], [ex - 11, ey - 20], C.woodDark, 3);
      line(g, [ex + 11, ey], [ex + 11, ey - 20], C.woodDark, 3);
      line(g, [ex - 14, ey - 20], [ex + 14, ey - 20], C.woodDark, 3.2);
    }
    // 레일과 수레
    line(g, [ex - 6, ey], [ex + 10, ey + 14], '#555', 1.2);
    line(g, [ex + 4, ey], [ex + 20, ey + 12], '#555', 1.2);
    cart(g, ex + 12, ey + 13, '#6c7480');
    orePile(g, 30, 14, '#6c7480');
    if (t >= 2) {
      // 권양탑
      const hx = 10 * k, hy = -38 * k, H = t === 3 ? 40 : 32;
      line(g, [hx - 10, hy + 6], [hx, hy - H], C.woodDark, 2.5);
      line(g, [hx + 10, hy + 8], [hx, hy - H], C.woodDark, 2.5);
      line(g, [hx - 6, hy - 10], [hx + 6, hy - 9], C.woodDark, 1.5);
      circ(g, hx, hy - H + 4, 5, 'none', { 'stroke-width': 1.6, stroke: C.woodDark });
      line(g, [hx, hy - H + 4], [hx + 3, hy + 2], C.rope, 1);
      if (t === 3) { poly(g, [[hx - 8, hy - H + 2], [hx + 8, hy - H + 2], [hx, hy - H - 8]], C.roofBrown); cart(g, 42 * k, 8 * k, C.gold); }
    }
  };

  function stall(g, u, v, w, d, stripe, goods) {
    for (const [pu, pv] of [[u, v], [u + w, v]]) line(g, iso(pu, pv, 0), iso(pu, pv, 22), C.woodDark, 1.8);
    box(g, u, v + d - 7, 0, w, 7, 8, C.plank);
    goods.forEach((col, i) => {
      const [x, y] = iso(u + 3 + (i * (w - 6)) / Math.max(1, goods.length - 1), v + d - 3.5, 8);
      circ(g, x, y - 1.8, 2.3, col, { 'stroke-width': 0.6 }); circ(g, x + 2.5, y - 1, 2.1, col, { 'stroke-width': 0.6 });
    });
    for (const [pu, pv] of [[u, v + d], [u + w, v + d]]) line(g, iso(pu, pv, 0), iso(pu, pv, 16), C.woodDark, 1.8);
    const n = 6;
    for (let i = 0; i < n; i++) {
      const a = u + (w * i) / n, b = u + (w * (i + 1)) / n;
      poly(g, [iso(a, v - 1, 23), iso(b, v - 1, 23), iso(b, v + d + 4, 15), iso(a, v + d + 4, 15)], i % 2 ? C.white : stripe, 0.6);
    }
    poly(g, [iso(u + w, v - 1, 23), iso(u + w, v + d + 4, 15), iso(u + w, v + d + 4, 13), iso(u + w, v - 1, 21)], shade(stripe, -0.3), 0.6);
  }
  B.market = function (g, t) {
    if (t === 3) {
      box(g, -32, -34, 0, 56, 24, 24, C.stone);
      stripesR(g, 24, -34, -10, 0, 24, 5, shade(C.stoneDark, -0.2));
      for (const uc of [-22, -8, 6, 18]) archL(g, -10, uc, 9, 16);
      gable(g, -32, -34, 24, 56, 24, 16, C.roofRed, 'u', C.stone);
      banner(g, -10, -15, 22, C.gold); banner(g, -10, 12, 22, C.gold);
    }
    const sv = t === 3 ? -2 : -26;
    stall(g, -30, sv, 22, 16, C.red, ['#c0392b', '#e67e22', '#c0392b']);
    stall(g, 2, sv, 22, 16, '#2f6db0', ['#6aa84f', '#f1c232', '#6aa84f']);
    if (t === 2) stall(g, -14, 8, 22, 14, '#7a3fa0', ['#e69138', '#cc0000', '#e69138']);
    barrel(g, 28, 18); barrel(g, 30, 25);
    crate(g, -32, 22); if (t >= 2) { crate(g, -32, 29); sack(g, -20, 32); }
  };

  B.smelter = function (g, t) {
    if (t === 1) {
      orePile(g, -28, 20);
      box(g, -20, -22, 0, 30, 30, 18, C.stone);
      stripesL(g, 8, -20, 10, 0, 18, 4.5, C.stoneDark);
      glowL(g, 8, -6, 11, 11);
      box(g, -15, -17, 18, 20, 20, 7, C.stoneDark);
      const top = cyl(g, -5, -7, 25, 4.5, 20, '#7a6e62', '#2b2520');
      smoke(g, top.x, top.y);
      ingots(g, 16, 14, 5);
      return;
    }
    const big = t === 3;
    if (big) {
      box(g, -34, -30, 0, 44, 34, 24, C.stone);
      stripesL(g, 4, -34, 10, 0, 24, 4.5, C.stoneDark);
      glowL(g, 4, -12, 16, 16);
      winR(g, 10, -13, 10, 5, 6, C.glow);
      gable(g, -34, -30, 24, 44, 34, 14, C.roofSlate, 'u', C.stone);
      for (const [u, v] of [[-24, -34], [4, -34]]) { const tp = cyl(g, u, v, 0, 5, 58, '#7a6e62', '#2b2520'); smoke(g, tp.x, tp.y); }
      cart(g, ...iso(26, -14, 0), '#6c7480');
      orePile(g, -30, 22);
      ingots(g, 18, 14, 8);
    } else {
      box(g, 12, -32, 0, 20, 16, 12, C.wood);
      gable(g, 12, -32, 12, 20, 16, 9, C.roofBrown, 'u', C.wood);
      box(g, -24, -24, 0, 32, 32, 22, C.stone);
      stripesL(g, 8, -24, 8, 0, 22, 4.5, C.stoneDark);
      glowL(g, 8, -8, 13, 13);
      box(g, -19, -19, 22, 22, 22, 8, C.stoneDark);
      for (const [u, v] of [[-13, -13], [-2, -2]]) { const tp = cyl(g, u, v, 30, 4, 18, '#7a6e62', '#2b2520'); smoke(g, tp.x, tp.y); }
      orePile(g, -30, 22);
      ingots(g, 16, 14, 6);
    }
  };

  B.warehouse = function (g, t) {
    if (t === 3) {
      box(g, -32, -26, 0, 62, 40, 28, C.stone);
      stripesL(g, 14, -32, 30, 0, 28, 5, C.stoneDark);
      stripesR(g, 30, -26, 14, 0, 28, 5, shade(C.stoneDark, -0.2));
      for (const uc of [-16, 12]) { poly(g, qL(14, uc - 7, uc + 7, 0, 16), C.woodDark); line(g, iso(uc, 14, 0), iso(uc, 14, 16), C.dark, 1); }
      winL(g, 14, -2, 20, 5, 5);
      gable(g, -32, -26, 28, 62, 40, 18, C.roofSlate, 'u', C.stone);
      crate(g, 30, 18); crate(g, 30, 25); crate(g, 30, 18, 7);
      barrel(g, -30, 22); barrel(g, -24, 26);
      cart(g, ...iso(8, 30, 0), '#d9c48f');
      return;
    }
    const big = t === 2;
    const [u, v, du, dv] = big ? [-30, -24, 54, 38] : [-28, -20, 48, 34];
    const base = big ? 6 : 0, h = big ? 22 : 18;
    if (big) box(g, u, v, 0, du, dv, base, C.stone);
    box(g, u, v, base, du, dv, h, C.wood);
    stripesL(g, v + dv, u, u + du, base, base + h, 4, shade(C.wood, -0.25));
    stripesR(g, u + du, v, v + dv, base, base + h, 4, shade(C.wood, -0.4));
    const uc = u + du / 2;
    poly(g, qL(v + dv, uc - 8, uc + 8, base, base + 14), C.woodDark);
    line(g, iso(uc - 8, v + dv, base), iso(uc + 8, v + dv, base + 14), C.plank, 1.2);
    line(g, iso(uc + 8, v + dv, base), iso(uc - 8, v + dv, base + 14), C.plank, 1.2);
    gable(g, u, v, base + h, du, dv, big ? 20 : 18, C.roofBrown, 'u', C.wood);
    if (big) { winR(g, u + du, v + dv / 2, base + 12, 6, 7, C.woodDark); line(g, iso(u + du + 6, v + dv / 2, base + 30), iso(u + du + 6, v + dv / 2, base + 14), C.rope, 1); }
    crate(g, u + du + 2, v + dv - 8); crate(g, u + du + 2, v + dv - 1);
    barrel(g, u - 2, v + dv + 4);
    if (big) { sack(g, u + 8, v + dv + 6); crate(g, u + du + 2, v + dv - 8, 7); }
  };

  B.barracks = function (g, t) {
    if (t === 3) {
      box(g, 14, -34, 0, 18, 18, 50, C.stone);
      stripesL(g, -16, 14, 32, 0, 50, 5, C.stoneDark);
      for (const [a, b] of [[14, -34], [28, -34], [14, -20], [28, -20]]) box(g, a, b, 50, 4, 4, 5, C.stone);
      flag(g, ...iso(23, -25, 50), 20, C.red);
      box(g, -32, -28, 0, 48, 34, 26, C.stone);
      stripesL(g, 6, -32, 16, 0, 26, 4.5, C.stoneDark);
      for (let u = -32; u < 14; u += 7) box(g, u, 2.5, 26, 3.5, 3.5, 4, C.stone);
      for (let v = -28; v < 6; v += 7) box(g, 12.5, v, 26, 3.5, 3.5, 4, C.stone);
      archL(g, 6, -8, 12, 16);
      for (const uc of [-24, 8]) { const [x, y] = iso(uc, 6, 16); circ(g, x, y, 4.2, C.red); circ(g, x, y, 1.4, C.gold, { 'stroke-width': 0.5 }); }
      banner(g, 6, -16, 24, C.red); banner(g, 6, 0, 24, C.red);
      dummy(g, 18, 18); dummy(g, 28, 8); rack(g, -24, 22);
      return;
    }
    const big = t === 2;
    const [u, v, du, dv] = big ? [-32, -28, 52, 32] : [-28, -26, 44, 28];
    if (big) {
      box(g, u, v, 0, du, dv, 10, C.stone);
      box(g, u, v, 10, du, dv, 14, C.plaster, { noTop: true });
      timberL(g, v + dv, u, u + du, 10, 24, 6);
      timberR(g, u + du, v, v + dv, 10, 24, 3);
    } else {
      box(g, u, v, 0, du, dv, 18, C.wood);
      stripesL(g, v + dv, u, u + du, 0, 18, 4, shade(C.wood, -0.25));
    }
    const h = big ? 24 : 18;
    archL(g, v + dv, u + du / 2, 10, 14);
    winL(g, v + dv, u + 8, 7, 5, 5); winL(g, v + dv, u + du - 8, 7, 5, 5);
    gable(g, u, v, h, du, dv, 18, '#8a3a2a', 'u', big ? C.plaster : C.wood);
    flag(g, ...iso(u + du + 3, v - 2, 0), big ? 58 : 46, C.red);
    dummy(g, 20, 16);
    if (big) dummy(g, 30, 4);
    rack(g, -22, 20);
  };

  B.archery = function (g, t) {
    line(g, iso(-6, 30, 0), iso(36, -6, 0), '#b89a68', 5, { opacity: 0.6 });
    if (t === 3) {
      for (const [u, v] of [[-32, -34], [-32, -14], [6, -34], [6, -14]]) line(g, iso(u, v, 0), iso(u, v, 22), C.woodDark, 2.2);
      gable(g, -32, -34, 22, 38, 20, 12, C.roofGreen, 'u', C.woodDark);
      box(g, 10, -36, 0, 20, 16, 16, C.wood);
      gable(g, 10, -36, 16, 20, 16, 10, C.roofGreen, 'u', C.wood);
      flag(g, ...iso(32, -38, 0), 40, C.roofGreen);
      target(g, -10, 24); target(g, 6, 20); target(g, 22, 12);
      hay(g, -30, 8); hay(g, -30, 16);
      return;
    }
    const [u, v, du, dv, h] = t === 2 ? [-34, -34, 28, 22, 18] : [-30, -30, 22, 20, 14];
    box(g, u, v, 0, du, dv, h, C.wood);
    stripesL(g, v + dv, u, u + du, 0, h, 4, shade(C.wood, -0.25));
    poly(g, qL(v + dv, u + du / 2 - 4, u + du / 2 + 4, 0, 11), C.dark);
    gable(g, u, v, h, du, dv, 12, C.roofGreen, 'u', C.wood);
    target(g, 10, -16); target(g, 22, 0);
    if (t === 2) { target(g, 24, 16); hay(g, -30, 4); hay(g, -24, 12); rack(g, -12, 22); }
  };

  B.stable = function (g, t) {
    const [u, v, du, dv, h] = t === 1 ? [-32, -30, 46, 22, 16] : [-34, -34, 56, 24, 20];
    if (t === 3) box(g, u, v, 0, du, dv, 8, C.stone);
    const base = t === 3 ? 8 : 0;
    box(g, u, v, base, du, dv, h - base, C.wood);
    stripesR(g, u + du, v, v + dv, base, h, 4, shade(C.wood, -0.4));
    const n = t === 1 ? 3 : 4;
    for (let i = 0; i < n; i++) {
      const a = u + 4 + (i * (du - 8)) / n, b = a + (du - 8) / n - 3;
      poly(g, qL(v + dv, a, b, 0, 12), C.dark);
      poly(g, qL(v + dv, a, b, 0, 5), C.plank);
    }
    gable(g, u, v, h, du, dv, 15, C.roofBrown, 'u', C.wood);
    if (t === 3) { const tp = hip(g, u + du / 2 - 5, v + dv / 2 - 5, h + 12, 10, 10, 10, C.roofRed, 1); line(g, tp, [tp[0], tp[1] - 8], C.dark, 1); poly(g, [[tp[0], tp[1] - 8], [tp[0] + 6, tp[1] - 7], [tp[0], tp[1] - 6]], C.dark, 0); }
    hay(g, -34, 0); if (t >= 2) hay(g, -34, 8);
    fence(g, [[-18, 4], [34, 4], [34, 34], [-18, 34]], 7);
    horse(g, ...iso(10, 20, 0), 0.9, '#8a5a32');
    if (t >= 2) horse(g, ...iso(24, 10, 0), 0.85, '#4a3525');
    if (t === 3) { box(g, -14, 14, 0, 4, 14, 4, C.wood); }
    fence(g, [[-18, 34], [34, 34]], 7);
  };

  B.workshop = function (g, t) {
    if (t === 1) {
      for (const [u, v] of [[-30, -32], [4, -32]]) line(g, iso(u, v, 0), iso(u, v, 20), C.woodDark, 2.6);
      box(g, -26, -28, 0, 12, 8, 8, C.plank);
      for (const [u, v] of [[-30, -10], [4, -10]]) line(g, iso(u, v, 0), iso(u, v, 14), C.woodDark, 2.6);
      lean(g, -32, -34, 38, 26, 21, 14, C.roofBrown);
      logPile(g, -26, -4, 6, 3);
      drawUnit(g, 'catapult', ...iso(20, 16, 0), 0.9, { unfinished: true });
      return;
    }
    const big = t === 3;
    const [u, v, du, dv, h] = big ? [-34, -34, 46, 34, 26] : [-32, -32, 40, 28, 20];
    box(g, u, v, 0, du, dv, h, big ? C.stone : C.wood);
    stripesL(g, v + dv, u, u + du, 0, h, big ? 5 : 4, big ? C.stoneDark : shade(C.wood, -0.25));
    poly(g, qL(v + dv, u + 8, u + 24, 0, 16), C.woodDark);
    line(g, iso(u + 16, v + dv, 0), iso(u + 16, v + dv, 16), C.dark, 1);
    gable(g, u, v, h, du, dv, 16, big ? C.roofSlate : C.roofBrown, 'u', big ? C.stone : C.wood);
    const ch = cyl(g, u + 6, v + 4, h, 3.5, 14, '#7a6e62', '#2b2520'); smoke(g, ch.x, ch.y);
    if (big) {
      const [bx, by] = iso(22, -24, 0);
      line(g, [bx, by], [bx, by - 60], C.woodDark, 3);
      line(g, [bx, by - 58], [bx - 22, by - 44], C.woodDark, 2.5);
      line(g, [bx - 20, by - 45], [bx - 20, by - 28], C.rope, 1);
      drawUnit(g, 'ram', ...iso(-12, 26, 0), 0.8);
    }
    drawUnit(g, 'catapult', ...iso(22, 14, 0), 0.9);
    box(g, 20, -16, 0, 12, 6, 2, C.plank); box(g, 20, -16, 2, 12, 6, 2, shade(C.plank, 0.1));
  };

  B.smithy = function (g, t) {
    const big = t >= 2;
    if (t === 3) {
      box(g, -30, -28, 0, 40, 32, 14, C.stone);
      stripesL(g, 4, -30, 10, 0, 14, 4.5, C.stoneDark);
      box(g, -30, -28, 14, 40, 32, 14, C.plaster, { noTop: true });
      timberL(g, 4, -30, 10, 14, 28, 5);
      timberR(g, 10, -28, 4, 14, 28, 3);
      glowL(g, 4, -12, 12, 11);
      gable(g, -30, -28, 28, 40, 32, 16, C.roofSlate, 'u', C.plaster);
      for (const [u, v] of [[-26, -30], [2, -30]]) { const tp = cyl(g, u, v, 0, 4.5, 50, '#7a6e62', '#2b2520'); smoke(g, tp.x, tp.y); }
      // 간판
      const [sx, sy] = iso(12, 8, 20);
      line(g, [sx - 2, sy], [sx + 12, sy + 6], C.woodDark, 1.6);
      poly(g, [[sx + 4, sy + 4], [sx + 13, sy + 8], [sx + 13, sy + 17], [sx + 4, sy + 13]], C.plank);
      line(g, [sx + 6, sy + 13], [sx + 11, sy + 8], C.steel, 1.4);
    } else {
      const [u, v, du, dv, h] = big ? [-30, -28, 36, 30, 20] : [-26, -24, 30, 26, 16];
      box(g, u, v, 0, du, dv, h, C.stone);
      stripesL(g, v + dv, u, u + du, 0, h, 4.5, C.stoneDark);
      glowL(g, v + dv, u + du / 2, 11, 10);
      gable(g, u, v, h, du, dv, 13, C.roofSlate, 'v', C.stone);
      const tp = cyl(g, u + 6, v + 6, h, 4, 16, '#7a6e62', '#2b2520');
      smoke(g, tp.x, tp.y);
      if (big) {
        for (const [pu, pv] of [[u + du, v + 4], [u + du + 12, v + 4], [u + du, v + dv - 2], [u + du + 12, v + dv - 2]]) line(g, iso(pu, pv, 0), iso(pu, pv, 14), C.woodDark, 2);
        box(g, u + du + 2, v + 8, 0, 8, 10, 6, C.stoneDark);
        glowL(g, v + 18, u + du + 6, 5, 5);
        lean(g, u + du, v + 2, 14, dv - 2, h - 2, 14, C.roofBrown);
      }
    }
    anvil(g, 18, 16);
    barrel(g, 28, 6);
    rack(g, -24, 22);
    if (big) {
      const [x, y] = iso(4, 26, 0);
      line(g, [x - 5, y], [x - 5, y - 8], C.woodDark, 1.5); line(g, [x + 5, y], [x + 5, y - 8], C.woodDark, 1.5);
      circ(g, x, y - 9, 5, '#9a9488'); circ(g, x, y - 9, 1.2, C.woodDark, { 'stroke-width': 0 });
    }
  };

  B.watchtower = function (g, t) {
    if (t === 3) {
      const top = cyl(g, 0, 0, 0, 14, 80, C.stone);
      for (let z = 8; z < 78; z += 8) {
        const [cx, yb] = iso(0, 0, z);
        el('path', { d: `M${top.x - top.rx},${yb} A${top.rx},${top.ry} 0 0 0 ${top.x + top.rx},${yb}`, fill: 'none', stroke: C.stoneDark, 'stroke-width': 0.7 }, g);
        void cx;
      }
      for (const z of [30, 55]) { const [x, y] = iso(4, 8, z); el('rect', { x: x - 1.5, y: y - 8, width: 3, height: 8, fill: C.dark }, g); }
      const ring = cyl(g, 0, 0, 80, 17, 7, C.stoneLight);
      for (let i = 0; i <= 6; i++) {
        const a = Math.PI * (i / 6);
        const x = ring.x - Math.cos(a) * ring.rx * 0.95, y = ring.y + Math.sin(a) * ring.ry * 0.95;
        poly(g, [[x - 3, y], [x + 3, y], [x + 3, y - 6], [x - 3, y - 6]], C.stoneLight, 0.8);
      }
      const ap = cone(g, ring.x, ring.y - 4, ring.rx - 3, 30, C.roofRed);
      flag(g, ap[0], ap[1], 18, C.red);
      return;
    }
    const H = t === 1 ? 56 : 68, w0 = 12, w1 = 8;
    const legs = [[-w0, -w0, -w1, -w1], [w0, -w0, w1, -w1], [-w0, w0, -w1, w1], [w0, w0, w1, w1]];
    const leg = (i) => line(g, iso(legs[i][0], legs[i][1], 0), iso(legs[i][2], legs[i][3], H), C.woodDark, 3);
    leg(0); leg(1); leg(2);
    for (let z = 10; z < H - 6; z += 16) {
      line(g, iso(-w0 + 1, w0 - 1, z), iso(w0 - 1, w0 - 1, z + 14), C.wood, 1.6);
      line(g, iso(w0 - 1, -w0 + 1, z), iso(w0 - 1, w0 - 1, z + 14), shade(C.wood, -0.2), 1.6);
    }
    leg(3);
    // 사다리
    for (const du of [-4, 2]) line(g, iso(du, w0 + 3, 0), iso(du, w1 + 2, H), C.wood, 1.2);
    for (let z = 4; z < H; z += 5) line(g, iso(-4, w0 + 3 - (z / H) * (w0 - w1 + 1), z), iso(2, w0 + 3 - (z / H) * (w0 - w1 + 1), z), C.wood, 0.9);
    box(g, -12, -12, H, 24, 24, 3, C.plank);
    if (t === 1) {
      for (const [u, v] of [[-11, -11], [11, -11], [-11, 11], [11, 11]]) line(g, iso(u, v, H + 3), iso(u, v, H + 14), C.woodDark, 1.8);
      line(g, iso(-11, 11, H + 9), iso(11, 11, H + 9), C.woodDark, 1.4);
      line(g, iso(11, -11, H + 9), iso(11, 11, H + 9), C.woodDark, 1.4);
      hip(g, -12, -12, H + 14, 24, 24, 14, C.thatch, 2);
    } else {
      box(g, -10, -10, H + 3, 20, 20, 14, C.wood);
      winL(g, 10, 0, H + 7, 8, 5); winR(g, 10, 0, H + 7, 8, 5);
      const ap = hip(g, -10, -10, H + 17, 20, 20, 16, C.roofRed, 3);
      flag(g, ap[0], ap[1], 16, C.red);
    }
  };

  /** 관리자가 추가한 건물 등 전용 그림이 없는 건물: 분류별 기본 집 */
  function generic(g, t, category) {
    const roof = { production: C.roofBrown, storage: C.roofBrown, military: '#8a3a2a', research: C.roofSlate, defense: C.roofRed }[category] || C.roofBrown;
    const [u, v, du, dv, h] = [[0, 0, 0, 0, 0], [-22, -18, 40, 30, 16], [-26, -22, 48, 36, 20], [-30, -26, 56, 42, 24]][t];
    box(g, u, v, 0, du, dv, h, t === 3 ? C.stone : C.wood);
    archL(g, v + dv, u + du / 2, 9, 13);
    winL(g, v + dv, u + 8, 6, 5, 5); winR(g, u + du, v + dv / 2, 6, 5, 5);
    gable(g, u, v, h, du, dv, 16, roof, 'u', t === 3 ? C.stone : C.wood);
    if (t >= 2) crate(g, u + du + 2, v + dv - 6);
    if (t === 3) flag(g, ...iso(u + du + 4, v - 2, 0), 50, C.red);
  }

  function scaffold(g) {
    minY = 0;
    off = { y: 0, s: 1 };
    box(g, -30, -30, 0, 60, 60, 1.5, { l: '#9c7a4c', r: '#86653c', t: '#b8966a' });
    const H = 40;
    const posts = [[-22, -22], [22, -22], [-22, 22], [22, 22]];
    const post = (p) => line(g, iso(p[0], p[1], 0), iso(p[0], p[1], H), C.woodDark, 2.6);
    post(posts[0]); post(posts[1]); post(posts[2]);
    box(g, -16, -16, 0, 32, 32, 14, { l: shade(C.stone, -0.05), r: C.stoneDark, t: C.stoneLight });
    for (const z of [16, 30]) {
      line(g, iso(-22, -22, z), iso(22, -22, z), C.plank, 2.4);
      line(g, iso(-22, -22, z), iso(-22, 22, z), C.plank, 2.4);
    }
    post(posts[3]);
    for (const z of [16, 30]) {
      line(g, iso(-22, 22, z), iso(22, 22, z), C.plank, 2.4);
      line(g, iso(22, -22, z), iso(22, 22, z), C.plank, 2.4);
    }
    line(g, iso(-22, 22, 0), iso(22, 22, 30), C.woodDark, 1.4);
    line(g, iso(22, -22, 0), iso(22, 22, 30), C.woodDark, 1.4);
    for (let i = 0; i < 4; i++) box(g, 26, -10 + i * 5, 0, 8, 4, 3, i % 2 ? C.stone : C.stoneLight);
    logU(g, -30, -8, 28, 0, 2.6);
    return -minY;
  }

  /** 건물 한 채. 반환: 그림 높이(타일 중심에서 위로 px) */
  function building(g, code, level, category) {
    minY = 0;
    off = { y: 0, s: 1 };
    const t = level >= 8 ? 3 : level >= 4 ? 2 : 1;
    if (B[code]) B[code](g, t);
    else generic(g, t, category);
    return -minY;
  }

  // ───────────── 성벽 ─────────────
  /**
   * corners: 화면 좌표 {top, right, bottom, left}. which: 'back' (왼→위→오른쪽) / 'front' (왼→아래→오른쪽)
   * 등급: Lv1~3 목책, Lv4~7 석벽+사각탑, Lv8+ 높은 석벽+원탑+성문
   */
  function wall(g, which, level, corners) {
    const t = level >= 8 ? 3 : level >= 4 ? 2 : 1;
    const Cn = corners;
    const segs = which === 'back' ? [[Cn.left, Cn.top], [Cn.top, Cn.right]] : [[Cn.left, Cn.bottom], [Cn.bottom, Cn.right]];
    const towers = which === 'back' ? [Cn.top] : [Cn.left, Cn.right, Cn.bottom];
    const H = [0, 26, 32, 42][t];
    for (const [p1, p2] of segs) {
      if (t === 1) palisade(g, p1, p2, H);
      else stoneWall(g, p1, p2, H, t, which === 'front' && p1 === Cn.bottom);
    }
    towers.sort((a, b) => a[1] - b[1]);
    for (const p of towers) tower(g, p, t, H);
  }
  function palisade(g, p1, p2, H) {
    const len = Math.hypot(p2[0] - p1[0], p2[1] - p1[1]), n = Math.floor(len / 5.5);
    const dx = (p2[0] - p1[0]) / n, dy = (p2[1] - p1[1]) / n;
    line(g, [p1[0], p1[1] - H * 0.35], [p2[0], p2[1] - H * 0.35], C.woodDark, 2.5);
    for (let i = 0; i <= n; i++) {
      const x = p1[0] + dx * i, y = p1[1] + dy * i, h = H + (i % 3) * 1.5;
      poly(g, [[x - 2.6, y], [x + 2.6, y], [x + 2.6, y - h], [x, y - h - 4], [x - 2.6, y - h]], i % 2 ? C.log : shade(C.log, -0.12), 0.8);
    }
    line(g, [p1[0], p1[1] - H * 0.75], [p2[0], p2[1] - H * 0.75], C.woodDark, 2);
  }
  function stoneWall(g, p1, p2, H, t, gate) {
    const face = p1[1] < p2[1] ? C.stone : shade(C.stone, -0.12);
    poly(g, [p1, p2, [p2[0], p2[1] - H], [p1[0], p1[1] - H]], face);
    const len = Math.hypot(p2[0] - p1[0], p2[1] - p1[1]);
    for (let z = 6; z < H - 2; z += 6) line(g, [p1[0], p1[1] - z], [p2[0], p2[1] - z], C.stoneDark, 0.7);
    // 벽돌 세로 이음
    const nb = Math.floor(len / 12);
    for (let i = 1; i < nb; i++) for (let z = 0, r = 0; z < H - 6; z += 6, r++) {
      const f = (i + (r % 2) * 0.5) / nb;
      const x = p1[0] + (p2[0] - p1[0]) * f, y = p1[1] + (p2[1] - p1[1]) * f - z;
      line(g, [x, y], [x, y - 6], C.stoneDark, 0.5);
    }
    // 여장
    const n = Math.floor(len / 10);
    for (let i = 0; i < n; i += 2) {
      const a = lerp(p1, p2, i / n), b = lerp(p1, p2, (i + 1) / n);
      poly(g, [[a[0], a[1] - H], [b[0], b[1] - H], [b[0], b[1] - H - 7], [a[0], a[1] - H - 7]], C.stoneLight, 0.8);
    }
    line(g, [p1[0], p1[1] - H], [p2[0], p2[1] - H], OUT, 1);
    if (gate) {
      const m = lerp(p1, p2, 0.5), w = 26, gh = H * 0.72;
      const dir = [(p2[0] - p1[0]) / len, (p2[1] - p1[1]) / len];
      const at = (s, z) => [m[0] + dir[0] * s, m[1] + dir[1] * s - z];
      const pts = [at(-w / 2, 0)];
      for (let i = 0; i <= 10; i++) { const a = Math.PI - (Math.PI * i) / 10; pts.push(at((w / 2) * Math.cos(a), gh - w / 2 + (w / 2) * Math.sin(a))); }
      pts.push(at(w / 2, 0));
      poly(g, pts, C.stoneDark);
      const inner = [at(-w / 2 + 3, 0)];
      for (let i = 0; i <= 10; i++) { const a = Math.PI - (Math.PI * i) / 10; inner.push(at((w / 2 - 3) * Math.cos(a), gh - w / 2 + (w / 2 - 3) * Math.sin(a) - 1)); }
      inner.push(at(w / 2 - 3, 0));
      poly(g, inner, C.woodDark);
      for (let s = -w / 2 + 6; s < w / 2 - 4; s += 5) line(g, at(s, 0), at(s, gh - 4), C.dark, 0.8);
      const bp = at(0, gh + 8);
      poly(g, [[bp[0] - 4, bp[1]], [bp[0] + 4, bp[1]], [bp[0] + 4, bp[1] + 12], [bp[0], bp[1] + 15], [bp[0] - 4, bp[1] + 12]], C.red);
      circ(g, bp[0], bp[1] + 6, 1.6, C.gold, { 'stroke-width': 0.5 });
    }
  }
  function tower(g, p, t, H) {
    inGrp(g, p[0], p[1], 1, (q) => towerBody(q, t, H));
  }
  function towerBody(q, t, H) {    if (t === 1) {
      for (const [u, v] of [[-6, -6], [6, -6], [-6, 6], [6, 6]]) line(q, iso(u, v, 0), iso(u, v, H + 10), C.woodDark, 2.4);
      box(q, -8, -8, H + 10, 16, 16, 3, C.plank);
      hip(q, -8, -8, H + 13, 16, 16, 12, C.thatch, 2);
    } else if (t === 2) {
      box(q, -9, -9, 0, 18, 18, H + 12, C.stone);
      stripesL(q, 9, -9, 9, 0, H + 12, 6, C.stoneDark);
      for (const [u, v] of [[-9, -9], [5, -9], [-9, 5], [5, 5]]) box(q, u, v, H + 12, 4, 4, 5, C.stoneLight);
      winL(q, 9, 0, H - 4, 2.5, 6, C.dark);
    } else {
      const top = cyl(q, 0, 0, 0, 10, H + 14, C.stone);
      const [x, y] = iso(3, 6, H - 6);
      el('rect', { x: x - 1.5, y: y - 8, width: 3, height: 8, fill: C.dark }, q);
      const ap = cone(q, top.x, top.y, top.rx + 3, 26, C.roofRed);
      flag(q, ap[0], ap[1], 14, C.red);
    }
  }

  // ───────────── 병종 ─────────────
  /** 사람 몸 (발끝 0,0, 오른쪽을 봄). 반환: 손 위치 등 */
  function body(g, o) {
    const legs = o.legs || '#5a4630', tunic = o.tunic;
    line(g, [-3, -13], [-4, 0], legs, 3.6);
    line(g, [2, -13], [3, 0], shade(legs, -0.15), 3.6);
    ell(g, -4.5, -0.5, 2.8, 1.4, '#3a2616', { 'stroke-width': 0 });
    ell(g, 3.5, -0.5, 2.8, 1.4, '#3a2616', { 'stroke-width': 0 });
    path(g, 'M-6,-27 L6,-27 L7.5,-12 L-7.5,-12 Z', tunic, -27);
    if (o.belt !== false) line(g, [-7, -15], [7, -15], o.belt || '#4a3322', 1.6);
    circ(g, 0.5, -32, 5, C.skin);
    circ(g, 3.2, -32.5, 0.7, '#222', { 'stroke-width': 0 });
    return { hand: [8, -18], shoulder: [4, -25], head: [0.5, -32] };
  }
  function arm(g, from, to, col) { line(g, from, to, OUT, 4.2); line(g, from, to, col, 3); circ(g, to[0], to[1], 1.8, C.skin, { 'stroke-width': 0.6 }); }

  const U = {};
  // 일꾼: 천 모자, 앞치마, 어깨에 멘 망치, 등에 진 목재
  U.worker = function (g) {
    poly(g, [[-13, -30], [-5, -34], [-3, -12], [-11, -9]], C.plank);
    line(g, [-12, -26], [-4, -30], C.woodDark, 0.8);
    line(g, [-11, -18], [-3, -21], C.woodDark, 0.8);
    const b = body(g, { tunic: '#7d8a5a', legs: '#5a4630' });
    path(g, 'M-4,-24 L5,-24 L6.5,-11 L-5.5,-11 Z', '#b89a68', -24, { 'stroke-width': 0.6 });
    path(g, 'M-4.5,-34 Q0.5,-40 6,-34 L8,-33 L-4.5,-32.5 Z', '#9c5a2e', -40);
    arm(g, b.shoulder, [9, -27], '#7d8a5a');
    line(g, [9, -27], [2, -44], C.woodDark, 2);
    poly(g, [[-1, -47], [6, -44], [5, -41], [-2, -44]], '#6f7a86', 0.8);
    line(g, [-5, -15], [-5, -10], '#555', 1.2);
  };
  // 주민: 밀짚모자, 바구니
  U.villager = function (g) {
    const b = body(g, { tunic: '#b07a4a', legs: '#6b5337' });
    ell(g, 0.5, -36, 8.5, 2.2, C.thatch);
    path(g, 'M-4,-36 Q0.5,-43 5,-36 Z', shade(C.thatch, -0.1), -41);
    arm(g, b.shoulder, [8, -17], '#b07a4a');
    path(g, 'M4,-18 L14,-18 L12.5,-10 L5.5,-10 Z', '#c89b52', -18);
    path(g, 'M5,-18 Q9,-25 13,-18', 'none', null, { stroke: C.woodDark, 'stroke-width': 1 });
    circ(g, 7.5, -19, 1.8, '#c0392b', { 'stroke-width': 0.4 });
    circ(g, 10.5, -19.5, 1.8, '#6aa84f', { 'stroke-width': 0.4 });
  };
  U.spearman = function (g) {
    line(g, [-6, 4], [16, -54], C.woodDark, 1.8);
    poly(g, [[16, -54], [14.5, -60], [18.5, -58.5]], C.steel, 0.7);
    const b = body(g, { tunic: '#5f7392', legs: '#4a4a52' });
    path(g, 'M-5,-34 Q0.5,-42 6,-34 Z', '#8a929c', -40);
    ell(g, 0.5, -34, 8, 1.8, '#8a929c');
    arm(g, b.shoulder, [7, -22], '#5f7392');
    path(g, 'M-12,-28 L-2,-28 L-2,-14 Q-7,-4 -12,-14 Z', C.red, -28);
    line(g, [-7, -27], [-7, -9], C.white, 1.6);
    line(g, [-11, -21], [-3, -21], C.white, 1.6);
  };
  U.swordsman = function (g) {
    const b = body(g, { tunic: '#9aa3ad', legs: '#4a4a52' });
    path(g, 'M-5,-26 L5,-26 L6.5,-16 L-6.5,-16 Z', C.red, -26, { 'stroke-width': 0.6 });
    path(g, 'M-4.5,-34 Q0.5,-42 5.5,-34 L5.5,-31 L-4.5,-31 Z', '#8a929c', -40);
    line(g, [3, -34], [3, -28], '#8a929c', 1.6);
    arm(g, b.shoulder, [10, -27], '#9aa3ad');
    line(g, [10, -27], [18, -47], C.steel, 2.4);
    line(g, [7.5, -29], [12.5, -25], C.gold, 1.6);
    circ(g, -6, -20, 8.5, C.wood);
    circ(g, -6, -20, 6, C.red, { 'stroke-width': 0 });
    circ(g, -6, -20, 2, C.steel);
  };
  U.guard = function (g) {
    const b = body(g, { tunic: '#c8cdd4', legs: '#9aa1aa', belt: C.gold });
    path(g, 'M-4.5,-27 L4.5,-27 L5.5,-10 L-5.5,-10 Z', C.red, -27, { 'stroke-width': 0.6 });
    line(g, [0, -26], [0, -11], C.gold, 1);
    path(g, 'M-5,-38 L6,-38 L6,-28 L-5,-28 Z', '#c8cdd4', -38);
    line(g, [0, -33], [6, -33], '#222', 1.2);
    path(g, 'M-2,-38 Q-6,-48 4,-47 Q-1,-44 1,-38 Z', C.red, -48);
    arm(g, b.shoulder, [9, -17], '#c8cdd4');
    line(g, [9, -17], [11, 2], C.steel, 2.2);
    line(g, [6, -16], [12, -18], C.gold, 1.6);
    path(g, 'M-15,-30 L-1,-30 L-1,-18 Q-1,-7 -8,-3 Q-15,-7 -15,-18 Z', '#2f4f8f', -30);
    path(g, 'M-8,-27 L-8,-8 M-13,-20 L-3,-20', 'none', null, { stroke: C.gold, 'stroke-width': 1.8 });
  };
  U.archer = function (g) {
    poly(g, [[-8, -30], [-4, -32], [-1, -16], [-5, -14]], C.woodDark);
    for (const dx of [-7, -5.5, -4]) line(g, [dx, -30], [dx - 1, -36], '#e8e2d6', 1.2);
    const b = body(g, { tunic: '#4d6b35', legs: '#5a4630' });
    path(g, 'M-5,-31 Q-5,-39 1,-39 Q7,-39 6,-31 L4,-29 L-5,-27 Z', '#3f5a2c', -39);
    arm(g, b.shoulder, [12, -24], '#4d6b35');
    path(g, 'M12,-42 Q22,-24 12,-6', 'none', -42, { stroke: C.woodDark, 'stroke-width': 2 });
    line(g, [12, -42], [5, -24], '#e8e2d6', 0.7);
    line(g, [5, -24], [12, -6], '#e8e2d6', 0.7);
    line(g, [5, -24], [22, -24], C.woodDark, 1);
    poly(g, [[22, -24], [19, -22.5], [19, -25.5]], C.steel, 0.5);
  };
  U.cavalry = function (g) {
    horse(g, 0, 0, 1.15, '#7b4a28');
    path(g, 'M-8,-19 L6,-19 L4,-11 L-6,-11 Z', C.red, -19, { 'stroke-width': 0.7 });
    inGrp(g, -1, -17, 1, (q) => {
    line(q, [-1, -2], [5, 6], '#4a4a52', 3.4);
    path(q, 'M-5,-17 L5,-17 L5.5,-3 L-5.5,-3 Z', '#8a929c', -34);
    circ(q, 0.5, -21, 4.5, C.skin);
    path(q, 'M-4,-22 Q0.5,-30 5,-22 L5,-20 L-4,-20 Z', '#8a929c', -40);
    line(q, [-10, -2], [30, -16], C.woodDark, 1.8);
    poly(q, [[30, -16], [34, -18], [31, -14]], C.steel, 0.6);
    poly(q, [[18, -12], [26, -16], [24, -10]], C.red, 0.6);
    arm(q, [3, -14], [7, -8], '#8a929c');
    path(q, 'M-12,-14 L-4,-14 L-4,-6 Q-8,-1 -12,-6 Z', C.red, -14);
    });
  };
  U.elephant = function (g) {
    const gr = '#8d8a86', dk = shade(gr, -0.25);
    line(g, [-12, -14], [-13, 0], dk, 6.5);
    line(g, [10, -14], [10, 0], dk, 6.5);
    path(g, 'M-22,-26 Q-26,-18 -24,-10', 'none', null, { stroke: OUT, 'stroke-width': 1.6 });
    ell(g, -2, -24, 21, 14, gr);
    line(g, [-6, -14], [-7, 0], gr, 7);
    line(g, [14, -14], [15, 0], gr, 7);
    for (const x of [-7, 15]) ell(g, x, -0.5, 4, 1.5, dk, { 'stroke-width': 0 });
    circ(g, 20, -30, 10, gr);
    path(g, 'M26,-26 Q31,-14 27,-4 Q25,0 22,-2', 'none', null, { stroke: OUT, 'stroke-width': 5.4, 'stroke-linecap': 'round' });
    path(g, 'M26,-26 Q31,-14 27,-4 Q25,0 22,-2', 'none', null, { stroke: gr, 'stroke-width': 4, 'stroke-linecap': 'round' });
    path(g, 'M22,-20 Q30,-16 31,-22', 'none', null, { stroke: '#f6f0e0', 'stroke-width': 2.2, 'stroke-linecap': 'round' });
    path(g, 'M12,-36 Q4,-30 8,-18 Q14,-20 16,-28 Z', shade(gr, -0.12), -36);
    circ(g, 23, -33, 1, '#111', { 'stroke-width': 0 });
    // 가마
    path(g, 'M-14,-36 L8,-36 L6,-44 L-12,-44 Z', C.gold, -44);
    inGrp(g, -3, -44, 1, (q) => box(q, -8, -8, 0, 16, 16, 8, C.red, { noTop: true }));
    line(g, [-13, -44], [-13, -58], C.woodDark, 1.4);
    line(g, [7, -44], [7, -58], C.woodDark, 1.4);
    path(g, 'M-16,-57 Q-3,-66 10,-57 Z', C.red, -64);
    circ(g, -3, -52, 3.5, C.skin);
    line(g, [2, -46], [12, -66], C.woodDark, 1.4);
    poly(g, [[12, -66], [11, -70], [14, -68]], C.steel, 0.5);
  };
  U.ram = function (g) {
    for (const x of [-14, 0, 14]) { circ(g, x, -5, 5, C.woodDark); circ(g, x, -5, 1.4, '#555'); }
    line(g, [-20, -12], [26, -12], OUT, 7.5, { 'stroke-linecap': 'butt' });
    line(g, [-20, -12], [26, -12], C.log, 6, { 'stroke-linecap': 'butt' });
    path(g, 'M26,-17 L34,-15 Q37,-12 34,-9 L26,-7 Z', '#5f6670', -17);
    poly(g, [[-20, -10], [18, -10], [18, -30], [-1, -40], [-20, -30]], '#8a6b4a');
    for (let x = -16; x < 18; x += 6) line(g, [x, -10], [x, x < -1 ? -30 - ((x + 20) / 19) * 10 : -30 - ((18 - x) / 19) * 10], shade('#8a6b4a', -0.25), 0.8);
    poly(g, [[-22, -30], [-1, -42], [20, -30], [18, -28], [-1, -39], [-20, -28]], '#6b5238');
  };
  U.catapult = function (g, o) {
    for (const x of [-12, 12]) { circ(g, x, -5, 5, C.woodDark); circ(g, x, -5, 1.4, '#555'); }
    line(g, [-18, -9], [18, -9], C.wood, 4.5, { 'stroke-linecap': 'butt' });
    line(g, [-6, -9], [2, -26], C.wood, 3);
    line(g, [10, -9], [2, -26], C.wood, 3);
    line(g, [-2, -18], [7, -18], C.woodDark, 2);
    if (o && o.unfinished) { line(g, [2, -26], [-2, -32], C.wood, 2.6); tk(-32); return; }
    line(g, [2, -24], [-18, -40], C.woodDark, 3);
    path(g, 'M-24,-42 Q-21,-36 -15,-38 L-17,-43 Z', C.rope, -44);
    circ(g, -20, -43, 3, '#8a8478');
    line(g, [2, -24], [14, -14], C.rope, 1.2);
    circ(g, 2, -25, 2.2, '#555');
  };

  /** 병종 한 명(대). 발끝 (0,0), 반환: 높이 */
  // 전용 그림이 없는 병종(관리자 추가)은 분류별 대표 그림
  const UNIT_FALLBACK = { worker: 'worker', infantry: 'swordsman', ranged: 'archer', cavalry: 'cavalry', siege: 'catapult' };

  function unit(g, code, opts) {
    minY = 0;
    off = { y: 0, s: 1 };
    const f = U[code] || U[UNIT_FALLBACK[(opts && opts.category) || ''] || 'swordsman'];
    f(g, opts || {});
    return -minY;
  }
  /** 건물 그림 안에 병종 그림을 소품으로 넣을 때 (높이 추적 유지) */
  function drawUnit(g, code, x, y, s, opts) {
    inGrp(g, x, y, s, (q) => U[code](q, opts || {}));
  }

  const UNIT_NAMES = {
    worker: '일꾼', spearman: '창병', swordsman: '검사', guard: '근위기사', archer: '궁수',
    cavalry: '기병', elephant: '코끼리병', ram: '공성추', catapult: '투석기',
  };

  const VgArt = { building, scaffold, wall, unit, UNIT_NAMES, iso, shade, villager: (g) => unit(g, 'villager') };
  global.VgArt = VgArt;
})(window);
