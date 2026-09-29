// 마을 화면: 상단 자원, 아이소메트릭 마을(SVG), 건물/건설 패널, 기록.
// 서버 상태를 기준으로 하고, 사이에는 서버 시계(offset 보정)로 자원·공사 시간을 계산해 보여준다.
(function () {
  'use strict';

  const BOOT = window.VG_BOOT;
  const NS = 'http://www.w3.org/2000/svg';
  const TW = 160, TH = 80, SP = 1.5;          // 타일 폭/높이, 타일 간격 배수
  const LABEL_MAXW = TW * SP * 0.5 * 1.7;     // 이름표 최대 폭 (옆 타일에 걸치지 않게)
  const LABEL_MAX_RISE = TH * SP * 0.6;       // 이름표가 타일 중심에서 올라갈 수 있는 최대 높이 (뒤 타일 이름표와 겹치지 않게)
  const RES = ['money', 'food', 'wood', 'iron', 'gold'];
  const RES_COLOR = { money: '#b8860b', food: '#6d9a2e', wood: '#8b5a2b', iron: '#5f6f80', gold: '#d4a017' };

  const $ = (s) => document.querySelector(s);
  const el = (tag, attrs, parent) => {
    const n = document.createElementNS(NS, tag);
    for (const k in attrs || {}) n.setAttribute(k, attrs[k]);
    if (parent) parent.appendChild(n);
    return n;
  };
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = (n) => Math.floor(n + 1e-9).toLocaleString('ko-KR');
  const fmtRate = (r) => (Math.abs(r) >= 10 ? r.toFixed(1) : r.toFixed(2));
  const fmtDur = (s) => {
    s = Math.max(0, Math.ceil(s));
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
    const p2 = (v) => String(v).padStart(2, '0');
    return h ? `${h}:${p2(m)}:${p2(x)}` : `${m}:${p2(x)}`;
  };
  const fmtTime = (t) => {
    const d = new Date(t * 1000);
    return `${d.getMonth() + 1}/${d.getDate()} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
  };

  const G = {
    st: null, offset: 0, sel: null, moving: null, fitted: false,
    bubbles: [], refreshing: false, lastPoll: 0, pendingRefresh: false,
  };

  const serverNow = () => Date.now() / 1000 + G.offset;

  // ───────────── API ─────────────
  async function api(a, data) {
    const t0 = Date.now() / 1000;
    let j;
    try {
      const r = await fetch('api.php?a=' + encodeURIComponent(a), data ? {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': BOOT.csrf },
        body: JSON.stringify(Object.assign({ csrf: BOOT.csrf }, data)),
      } : { credentials: 'same-origin', cache: 'no-store' });
      j = await r.json();
    } catch (e) {
      toast('서버와 통신하지 못했습니다.', 'err');
      return null;
    }
    const t1 = Date.now() / 1000;
    if (!j.ok) {
      toast(j.error || '오류', 'err');
      if (j.auth) setTimeout(() => location.reload(), 1500);
      return null;
    }
    if (j.state) {
      G.offset = j.state.now - (t0 + t1) / 2;
      setState(j.state);
    }
    return j;
  }

  async function refresh() {
    if (G.refreshing) { G.pendingRefresh = true; return; }
    G.refreshing = true;
    G.lastPoll = Date.now();
    await api('state');
    G.refreshing = false;
    if (G.pendingRefresh) { G.pendingRefresh = false; refresh(); }
  }

  function toast(msg, kind) {
    const d = document.createElement('div');
    d.className = 'toast ' + (kind || '');
    d.textContent = msg;
    $('#toasts').appendChild(d);
    setTimeout(() => d.classList.add('out'), 3200);
    setTimeout(() => d.remove(), 3800);
  }

  // ───────────── 상태 ─────────────
  function setState(st) {
    const first = !G.st;
    G.st = st;
    if (G.sel && G.sel.type === 'bld' && !bld(G.sel.id)) G.sel = null;
    if (G.moving && !bld(G.moving)) G.moving = null;
    renderTop(true);
    renderVillage();
    renderPanel();
    renderLogs();
    if (!first && st.crit) {
      for (const code in st.crit) {
        const c = st.crit[code];
        const nm = st.defs[code] ? st.defs[code].name : code;
        toast(`크리티컬! ${nm} ×${c.n} · ${st.res_names[c.res]} +${fmt(c.amount)}`, 'crit');
      }
    }
  }

  const bld = (id) => G.st.buildings.find((b) => b.id === id);
  const bldAtSlot = (slot) => G.st.buildings.find((b) => b.slot === slot);
  const hall = () => G.st.buildings.find((b) => b.code === 'hall');
  const busyCount = () => G.st.buildings.filter((b) => b.build_finish).length;

  /** 지금 시점 자원 (마지막 정산 + 순생산 × 경과) */
  function curRes(r) {
    const st = G.st;
    const dt = Math.max(0, serverNow() - st.now);
    const base = st.res[r], net = st.net[r], cap = st.caps[r];
    if (net >= 0) {
      if (cap != null && base >= cap) return base;
      return cap != null ? Math.min(cap, base + net * dt) : base + net * dt;
    }
    return Math.max(0, base + net * dt);
  }

  const canAfford = (cost) => Object.keys(cost).every((r) => curRes(r) + 1e-9 >= cost[r]);

  // ───────────── 상단 자원 ─────────────
  function renderTop(full) {
    const st = G.st;
    const bar = $('#resbar');
    if (full || !bar.children.length) {
      bar.innerHTML = RES.map((r) => `
        <div class="res" data-r="${r}" title="${esc(st.res_names[r])}">
          <span class="ico" style="background:${RES_COLOR[r]}">${esc(st.res_names[r].charAt(0))}</span>
          <span class="nm">${esc(st.res_names[r])}</span>
          <span class="amt"></span>
          <span class="rate"></span>
        </div>`).join('');
      $('#vname').textContent = st.village.name;
      $('#hallinfo').textContent = `회관 Lv${st.hall_level} · 칸 ${st.cells}/16`;
    }
    for (const r of RES) {
      const n = bar.querySelector(`[data-r="${r}"]`);
      const v = curRes(r), cap = st.caps[r];
      n.querySelector('.amt').innerHTML = fmt(v) + (cap != null ? `<small>/${fmt(cap)}</small>` : '');
      const net = st.net[r];
      const rt = n.querySelector('.rate');
      rt.textContent = (net >= 0 ? '+' : '') + fmtRate(net) + '/s';
      rt.className = 'rate' + (net < 0 ? ' neg' : net === 0 ? ' zero' : '');
      n.classList.toggle('full', cap != null && v >= cap - 0.5);
    }
    $('#buildinfo').textContent = `공사 ${busyCount()}/${st.max_builds}`;
  }

  // ───────────── 마을 그리기 ─────────────
  const pos = (gx, gy) => ({ x: (gx - gy) * TW / 2 * SP, y: (gx + gy - 4) * TH / 2 * SP });
  const tierOf = (lv) => (lv >= 8 ? 3 : lv >= 4 ? 2 : 1);

  function shade(hex, f) {
    const n = parseInt(hex.slice(1), 16);
    let r = n >> 16, g = (n >> 8) & 255, b = n & 255;
    const k = (c) => Math.max(0, Math.min(255, Math.round(f < 0 ? c * (1 + f) : c + (255 - c) * f)));
    r = k(r); g = k(g); b = k(b);
    return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
  }
  const pts = (arr) => arr.map((p) => p[0].toFixed(1) + ',' + p[1].toFixed(1)).join(' ');

  const STYLE = {
    hall: { a: 54, h: 44, wall: '#d9c9a6', roof: '#a8412e', rh: 36, flag: true },
    lumber: { a: 44, h: 26, wall: '#9a6b3c', roof: '#5d3d1e', rh: 18, logs: true },
    market: { a: 44, h: 22, wall: '#eadcb8', roof: '#c0392b', rh: 22 },
    smelter: { a: 42, h: 32, wall: '#6d5d52', roof: '#3b2f2a', rh: 12, chimney: true, glow: true },
    warehouse: { a: 52, h: 30, wall: '#b98a52', roof: '#6b4a2a', rh: 20 },
    barracks: { a: 48, h: 30, wall: '#b8a58a', roof: '#7a2a2a', rh: 22, flag: true },
    archery: { a: 44, h: 28, wall: '#a3b37e', roof: '#3f6b2f', rh: 22, target: true },
    stable: { a: 52, h: 24, wall: '#a8773f', roof: '#5b3a1a', rh: 18 },
    workshop: { a: 48, h: 30, wall: '#8d8a80', roof: '#4a4a4a', rh: 16, chimney: true },
    smithy: { a: 42, h: 28, wall: '#7d6f63', roof: '#2f2f2f', rh: 16, chimney: true, glow: true },
    watchtower: { a: 22, h: 92, wall: '#bda57e', roof: '#6b3a1a', rh: 28 },
    _: { a: 44, h: 28, wall: '#c8b48c', roof: '#7a5a3a', rh: 18 },
  };

  /** 아이소메트릭 상자 + 지붕. 반환: 그림 높이 */
  function isoHouse(g, s, k) {
    const a = s.a * k, h = s.h * k, rh = s.rh * k;
    const L = [-a, 0], R = [a, 0], B = [0, a / 2], T = [0, -a / 2];
    const up = (p, d) => [p[0], p[1] - d];
    el('polygon', { points: pts([L, B, up(B, h), up(L, h)]), fill: shade(s.wall, -0.18), stroke: '#3b2a18', 'stroke-width': 1 }, g);
    el('polygon', { points: pts([B, R, up(R, h), up(B, h)]), fill: s.wall, stroke: '#3b2a18', 'stroke-width': 1 }, g);
    // 문
    const dw = a * 0.22, dh = Math.min(h * 0.6, 22 * k);
    el('polygon', { points: pts([[a * 0.35, a * 0.33], [a * 0.35 + dw, a * 0.33 - dw / 2], [a * 0.35 + dw, a * 0.33 - dw / 2 - dh], [a * 0.35, a * 0.33 - dh]]), fill: '#4a3220' }, g);
    const apex = [0, -h - rh];
    el('polygon', { points: pts([up(L, h), up(T, h), apex]), fill: shade(s.roof, -0.35) }, g);
    el('polygon', { points: pts([up(T, h), up(R, h), apex]), fill: shade(s.roof, -0.25) }, g);
    el('polygon', { points: pts([up(L, h), up(B, h), apex]), fill: shade(s.roof, -0.1), stroke: '#2a1a10', 'stroke-width': 1 }, g);
    el('polygon', { points: pts([up(B, h), up(R, h), apex]), fill: s.roof, stroke: '#2a1a10', 'stroke-width': 1 }, g);
    let top = h + rh;
    if (s.chimney) {
      const cx = a * 0.45, cy = -h - rh * 0.2;
      el('rect', { x: cx - 5 * k, y: cy - 26 * k, width: 10 * k, height: 26 * k, fill: '#5a4a40', stroke: '#2a1a10' }, g);
      el('ellipse', { cx: cx + 4 * k, cy: cy - 34 * k, rx: 9 * k, ry: 6 * k, fill: '#ddd', opacity: 0.6 }, g);
      top = Math.max(top, -cy + 40 * k);
    }
    if (s.glow) el('polygon', { points: pts([[-a * 0.6, a * 0.2 - h * 0.35], [-a * 0.3, a * 0.35 - h * 0.35], [-a * 0.3, a * 0.35 - h * 0.6], [-a * 0.6, a * 0.2 - h * 0.6]]), fill: '#f39c12', opacity: 0.85 }, g);
    if (s.flag) {
      el('line', { x1: 0, y1: apex[1], x2: 0, y2: apex[1] - 26 * k, stroke: '#3b2a18', 'stroke-width': 2 }, g);
      el('polygon', { points: pts([[0, apex[1] - 26 * k], [16 * k, apex[1] - 21 * k], [0, apex[1] - 16 * k]]), fill: '#c0392b' }, g);
      top += 26 * k;
    }
    if (s.logs) {
      for (let i = 0; i < 3; i++) el('ellipse', { cx: -a * 0.75 + i * 9 * k, cy: a * 0.05 - i * 3 * k, rx: 5 * k, ry: 5 * k, fill: '#c89b62', stroke: '#6b4a2a' }, g);
    }
    if (s.target) {
      el('circle', { cx: -a * 0.85, cy: -6 * k, r: 9 * k, fill: '#fff', stroke: '#c0392b', 'stroke-width': 3 * k }, g);
      el('circle', { cx: -a * 0.85, cy: -6 * k, r: 3 * k, fill: '#c0392b' }, g);
    }
    return top;
  }

  function drawFarm(g, k) {
    const a = 60 * k;
    const L = [-a, 0], R = [a, 0], B = [0, a / 2], T = [0, -a / 2];
    el('polygon', { points: pts([L, B, [0, a / 2 + 6], [-a, 6]]), fill: '#7a5a30' }, g);
    el('polygon', { points: pts([B, R, [a, 6], [0, a / 2 + 6]]), fill: '#8f6a3a' }, g);
    el('polygon', { points: pts([L, T, R, B]), fill: '#d8c25a', stroke: '#8a7428' }, g);
    for (let i = 1; i < 6; i++) {
      const f = i / 6;
      // 좌상변(L→T)의 점과 우하변(B→R)의 점을 이어 밭고랑
      el('line', { x1: -a + a * f, y1: -a / 2 * f, x2: a * f, y2: a / 2 - a / 2 * f, stroke: '#a88f30', 'stroke-width': 2 }, g);
    }
    const bg = el('g', { transform: `translate(${-a * 0.35},${-a * 0.12})` }, g);
    return Math.max(a / 2, isoHouse(bg, { a: 20, h: 16, wall: '#a8412e', roof: '#6b2a1e', rh: 12 }, k) + a * 0.12);
  }

  function drawMine(g, k) {
    const w = 58 * k, h = 52 * k;
    el('path', { d: `M${-w},${8 * k} Q${-w * 0.55},${-h} 0,${-h} Q${w * 0.6},${-h} ${w},${8 * k} Z`, fill: '#8a8278', stroke: '#4a4238' }, g);
    el('path', { d: `M${-w * 0.2},${-h * 0.95} Q0,${-h * 1.05} ${w * 0.3},${-h * 0.9} L${w * 0.1},${-h * 0.6} Z`, fill: '#a9a197' }, g);
    el('path', { d: `M${-14 * k},${10 * k} L${-14 * k},${-8 * k} Q0,${-24 * k} ${14 * k},${-8 * k} L${14 * k},${10 * k} Z`, fill: '#2a2018', stroke: '#6b4a2a', 'stroke-width': 3 }, g);
    el('rect', { x: w * 0.45, y: -2 * k, width: 16 * k, height: 10 * k, fill: '#6b4a2a' }, g);
    el('circle', { cx: w * 0.52, cy: -5 * k, r: 5 * k, fill: '#5f6f80' }, g);
    return h + 4;
  }

  function drawScaffold(g, k) {
    const a = 46 * k, h = 50 * k;
    el('polygon', { points: pts([[-a, 0], [0, -a / 2], [a, 0], [0, a / 2]]), fill: '#a07a50', stroke: '#6b4a2a' }, g);
    const posts = [[-a * 0.7, 0], [0, a * 0.35], [a * 0.7, 0], [0, -a * 0.35]];
    for (const p of posts) el('line', { x1: p[0], y1: p[1], x2: p[0], y2: p[1] - h, stroke: '#6b4a2a', 'stroke-width': 3 }, g);
    for (const y of [h * 0.45, h]) {
      el('polyline', { points: pts(posts.concat([posts[0]]).map((p) => [p[0], p[1] - y])), fill: 'none', stroke: '#8b5a2b', 'stroke-width': 2.5 }, g);
    }
    el('line', { x1: posts[0][0], y1: posts[0][1], x2: posts[1][0], y2: posts[1][1] - h, stroke: '#8b5a2b', 'stroke-width': 2 }, g);
    el('line', { x1: posts[1][0], y1: posts[1][1], x2: posts[2][0], y2: posts[2][1] - h, stroke: '#8b5a2b', 'stroke-width': 2 }, g);
    return h + a * 0.35;
  }

  /** 건물 한 채. 업로드 이미지가 있으면 이미지, 없으면 기본 도형. 반환: 그림 높이 */
  function drawBuilding(g, b) {
    const st = G.st;
    if (b.level === 0) return drawScaffold(g, 1);
    const tier = tierOf(b.level);
    const img = st.images[`bld_${b.code}_${tier}`] || st.images[`bld_${b.code}_1`];
    if (img) {
      const w = TW * 1.0, hgt = TW * 1.0;
      el('image', { href: img, x: -w / 2, y: TH / 4 - hgt, width: w, height: hgt, preserveAspectRatio: 'xMidYMax meet' }, g);
      return hgt - TH / 4;
    }
    const k = [0, 0.82, 0.95, 1.1][tier];
    if (b.code === 'farm') return drawFarm(g, k);
    if (b.code === 'mine') return drawMine(g, k);
    return isoHouse(g, Object.assign({}, STYLE[b.code] || STYLE._, tier === 3 && !STYLE[b.code]?.flag ? { flag: true } : {}), k);
  }

  function wallCorners() {
    const c = (gx, gy) => { const p = pos(gx, gy); return [p.x, p.y]; };
    const e = 0.62;
    return { top: c(-e, -e), right: c(4 + e, -e), bottom: c(4 + e, 4 + e), left: c(-e, 4 + e) };
  }

  function drawWallSegs(g, segs, WH, alpha) {
    const grp = el('g', { opacity: alpha }, g);
    for (const [p1, p2] of segs) {
      el('polygon', { points: pts([p1, p2, [p2[0], p2[1] - WH], [p1[0], p1[1] - WH]]), fill: p1[1] < p2[1] ? '#a39580' : '#b8aa92', stroke: '#5a4e3e' }, grp);
      const len = Math.hypot(p2[0] - p1[0], p2[1] - p1[1]), n = Math.floor(len / 22);
      for (let i = 0; i < n; i += 2) {
        const f1 = i / n, f2 = (i + 1) / n;
        const q1 = [p1[0] + (p2[0] - p1[0]) * f1, p1[1] + (p2[1] - p1[1]) * f1 - WH];
        const q2 = [p1[0] + (p2[0] - p1[0]) * f2, p1[1] + (p2[1] - p1[1]) * f2 - WH];
        el('polygon', { points: pts([q1, q2, [q2[0], q2[1] - 9], [q1[0], q1[1] - 9]]), fill: '#9a8c76', stroke: '#5a4e3e' }, grp);
      }
    }
    for (const [p1] of segs) {
      el('rect', { x: p1[0] - 12, y: p1[1] - WH - 22, width: 24, height: WH + 22, fill: '#a89a84', stroke: '#5a4e3e' }, grp);
      el('ellipse', { cx: p1[0], cy: p1[1] - WH - 22, rx: 12, ry: 5, fill: '#8a7c66', stroke: '#5a4e3e' }, grp);
    }
    const last = segs[segs.length - 1][1];
    el('rect', { x: last[0] - 12, y: last[1] - WH - 22, width: 24, height: WH + 22, fill: '#a89a84', stroke: '#5a4e3e' }, grp);
  }

  /** 성벽: 뒤/앞 이미지를 각각 통째로. 한쪽만 있으면 나머지는 도형 */
  function drawWall(layer, which, wallB) {
    if (!wallB) return;
    const st = G.st, ui = st.ui;
    const C = wallCorners(), WH = 34;
    const alpha = wallB.level === 0 ? 0.45 : 1;
    const img = st.images['wall_' + which];
    const width = (C.right[0] - C.left[0]) * ui.wall_img_scale;
    const g = el('g', { class: 'wall', 'data-wall': which }, layer);
    if (img) {
      const hgt = which === 'back' ? (C.left[1] - C.top[1]) + WH * 2.2 : (C.bottom[1] - C.left[1]) + WH * 2.2;
      const yBottom = (which === 'back' ? C.left[1] : C.bottom[1] + WH * 0.4) + (which === 'back' ? ui.wall_back_offset : ui.wall_front_offset);
      el('image', { href: img, x: -width / 2, y: yBottom - hgt, width, height: hgt, preserveAspectRatio: 'xMidYMax meet', opacity: alpha }, g);
    } else {
      drawWallSegs(g, which === 'back' ? [[C.left, C.top], [C.top, C.right]] : [[C.left, C.bottom], [C.bottom, C.right]], WH, alpha);
    }
    g.addEventListener('click', () => select({ type: 'wall' }));
  }

  function renderVillage() {
    const st = G.st;
    const world = $('#world');
    world.innerHTML = '';
    G.bubbles = [];
    const ground = el('g', {}, world);
    const wallBack = el('g', {}, world);
    const tiles = el('g', {}, world);
    const wallFront = el('g', {}, world);
    const labels = el('g', { class: 'labels' }, world);
    const bubbles = el('g', { class: 'bubbles' }, world);

    // 바닥(마을 터)
    const C = wallCorners();
    el('polygon', { points: pts([C.top, C.right, C.bottom, C.left]), fill: '#cdb98a', stroke: '#a88f5c', 'stroke-width': 2, opacity: 0.55 }, ground);

    const wallB = st.buildings.find((b) => b.slot === 90);
    drawWall(wallBack, 'back', wallB);
    drawWall(wallFront, 'front', wallB);

    const slots = Object.keys(st.layout).map(Number).sort((a, b) => {
      const A = st.layout[a], B = st.layout[b];
      return (A[0] + A[1]) - (B[0] + B[1]) || A[0] - B[0];
    });
    for (const slot of slots) {
      const [gx, gy] = st.layout[slot];
      const p = pos(gx, gy);
      const b = bldAtSlot(slot);
      const open = slot === 0 || slot <= st.cells;
      const nextOpen = !open && slot <= st.cells_next;
      const g = el('g', { class: 'tile', transform: `translate(${p.x},${p.y})`, 'data-slot': slot }, tiles);
      const selected = (G.sel && ((G.sel.type === 'slot' && G.sel.slot === slot) || (G.sel.type === 'bld' && b && G.sel.id === b.id)));
      const moveTarget = G.moving && slot !== 0 && (open || b) && !(b && b.id === G.moving);
      el('polygon', {
        points: pts([[-TW / 2, 0], [0, -TH / 2], [TW / 2, 0], [0, TH / 2]]),
        class: 'ground' + (open || b ? '' : ' locked') + (selected ? ' sel' : '') + (moveTarget ? ' target' : ''),
      }, g);
      if (!b) {
        if (open) {
          el('text', { class: 'plus', y: 8, 'text-anchor': 'middle' }, g).textContent = '+';
        } else {
          el('text', { class: 'lock', y: 5, 'text-anchor': 'middle' }, g).textContent = nextOpen ? `회관 Lv${st.hall_level + 1}` : '잠김';
        }
        g.addEventListener('click', () => onSlotClick(slot, open));
        continue;
      }
      const bg = el('g', { class: 'bld' + (G.moving === b.id ? ' moving' : '') }, g);
      const height = drawBuilding(bg, b);
      g.addEventListener('click', () => onSlotClick(slot, true));
      // 키 큰 건물은 이름표가 건물 몸통에 걸리더라도 뒤 타일 이름표와 겹치지 않는 높이까지만 올린다
      const top = p.y - Math.min(height + 10, LABEL_MAX_RISE);
      const lb = drawLabel(labels, p.x, top, b);
      if (b.build_finish) drawBubble(bubbles, p.x, lb.top - 8, b);
    }

    if (!G.fitted) { G.fitted = true; fitCamera(); }
  }

  function drawLabel(layer, x, y, b) {
    const st = G.st, d = st.defs[b.code] || { name: b.code };
    const g = el('g', { transform: `translate(${x},${y})` }, layer);
    const bgRect = el('rect', { class: 'lbl-bg', rx: 7, ry: 7 }, g);
    const t1 = el('text', { class: 'lbl-name', 'text-anchor': 'middle', y: 0 }, g);
    t1.textContent = b.level === 0 ? `${d.name} (건설 중)` : `${d.name} Lv${b.level}`;
    let line2 = null;
    if (b.level > 0 && b.produces && b.rate > 0) {
      line2 = `${st.res_names[b.produces]} +${fmtRate(b.rate)}/s`;
    } else if (b.level > 0 && b.produces && b.code === 'smelter') {
      line2 = '철광석 부족';
    } else if (b.value) {
      line2 = `보관 +${fmt(b.value)}`;
    }
    let lines = 1;
    if (line2) {
      const t2 = el('text', { class: 'lbl-prod', 'text-anchor': 'middle', y: 16, fill: RES_COLOR[b.produces] || '#5a4020' }, g);
      t2.textContent = line2;
      lines = 2;
    }
    const bb = g.getBBox();
    let scale = 1;
    if (bb.width > LABEL_MAXW) scale = LABEL_MAXW / bb.width;
    const w = bb.width + 14, hgt = lines * 16 + 6;
    bgRect.setAttribute('x', -w / 2); bgRect.setAttribute('y', -14);
    bgRect.setAttribute('width', w); bgRect.setAttribute('height', hgt);
    // 이름표 아래끝이 건물 머리 위에 오도록 위로 올림
    const shift = -(hgt - 14);
    g.setAttribute('transform', `translate(${x},${y + shift * scale}) scale(${scale})`);
    return { top: y + (shift - 14) * scale };
  }

  function drawBubble(layer, x, y, b) {
    const W = 132, H = 36;
    const g = el('g', { class: 'bubble', transform: `translate(${x},${y - H - 8})` }, layer);
    el('path', { d: `M${-W / 2 + 8},0 H${W / 2 - 8} Q${W / 2},0 ${W / 2},8 V${H - 8} Q${W / 2},${H} ${W / 2 - 8},${H} H8 L0,${H + 8} L-8,${H} H${-W / 2 + 8} Q${-W / 2},${H} ${-W / 2},${H - 8} V8 Q${-W / 2},0 ${-W / 2 + 8},0 Z`, class: 'bub-bg' }, g);
    const txt = el('text', { class: 'bub-txt', x: 0, y: 15, 'text-anchor': 'middle' }, g);
    el('rect', { x: -W / 2 + 10, y: 22, width: W - 20, height: 7, rx: 3, class: 'bub-track' }, g);
    const bar = el('rect', { x: -W / 2 + 10, y: 22, width: 0, height: 7, rx: 3, class: 'bub-bar' }, g);
    G.bubbles.push({ b, txt, bar, w: W - 20 });
  }

  function fitCamera() {
    const world = $('#world');
    const saved = world.getAttribute('transform');
    world.removeAttribute('transform');
    const bb = world.getBBox();
    if (saved) world.setAttribute('transform', saved);
    G.cam.fit({ x: bb.x, y: bb.y, w: bb.width, h: bb.height }, 20);
  }

  // ───────────── 클릭·선택 ─────────────
  function onSlotClick(slot, open) {
    const b = bldAtSlot(slot);
    if (G.moving) {
      if (slot === 0 || (!open && !b)) { toast('옮길 수 없는 칸입니다.', 'err'); return; }
      const bid = G.moving;
      endMove();
      if (b && b.id === bid) return;
      api('move', { bid, slot }).then((j) => { if (j) toast(b ? '건물 위치를 맞바꿨습니다.' : '건물을 옮겼습니다.'); });
      return;
    }
    if (b) select({ type: 'bld', id: b.id });
    else if (open) select({ type: 'slot', slot });
    else toast(`회관 레벨을 올리면 칸이 열립니다.`);
  }

  function select(sel) {
    G.sel = sel;
    renderVillage();
    renderPanel();
  }

  function startMove(bid) {
    G.moving = bid;
    $('#movehint').hidden = false;
    closePanel();
  }
  function endMove() {
    G.moving = null;
    $('#movehint').hidden = true;
    renderVillage();
  }

  function closePanel() {
    G.sel = null;
    $('#panel').hidden = true;
    renderVillage();
  }

  // ───────────── 패널 ─────────────
  function costHtml(cost) {
    const st = G.st;
    const keys = Object.keys(cost);
    if (!keys.length) return '<span class="muted">비용 없음</span>';
    return keys.map((r) => `<span class="cost" data-r="${r}" data-amt="${cost[r]}"><i style="background:${RES_COLOR[r]}"></i>${esc(st.res_names[r])} ${fmt(cost[r])}</span>`).join(' ');
  }

  function valueText(code, v) {
    const st = G.st, d = st.defs[code];
    if (!d || !v) return '';
    if (d.category === 'storage') return `보관 +${fmt(v)}`;
    if (d.produces) return `${st.res_names[d.produces]} +${fmtRate(v)}/s`;
    return '';
  }

  function renderPanel() {
    const st = G.st, panel = $('#panel'), body = $('#panelbody');
    if (!G.sel) { panel.hidden = true; return; }
    panel.hidden = false;
    let html = '';
    if (G.sel.type === 'slot') html = buildMenuHtml(G.sel.slot);
    else if (G.sel.type === 'wall') {
      const w = st.buildings.find((b) => b.slot === 90);
      html = w ? bldHtml(w) : buildMenuHtml(90);
    } else {
      const b = bld(G.sel.id);
      if (!b) { panel.hidden = true; return; }
      html = bldHtml(b);
    }
    const scroll = panel.scrollTop; // 주기 갱신 때 목록 스크롤 유지
    body.innerHTML = html;
    panel.scrollTop = scroll;
    body.querySelectorAll('[data-act]').forEach((n) => n.addEventListener('click', onPanelAction));
    tickPanel();
  }

  function bldHtml(b) {
    const st = G.st, d = st.defs[b.code] || { name: b.code, descr: '', category: '' };
    const isHall = b.code === 'hall', isWall = b.slot === 90;
    let h = `<h2>${esc(d.name)} <small>Lv${b.level}</small></h2><p class="descr">${esc(d.descr)}</p>`;
    if (b.level > 0) {
      if (b.produces && b.code === 'smelter') {
        h += `<p class="now">현재: 금괴 +${fmtRate(b.rate)}/s` + (st.smelt_util < 1 ? ` <span class="warn">(철광석 부족, 가동률 ${Math.round(st.smelt_util * 100)}%)</span>` : '') + '</p>';
      } else if (b.produces) h += `<p class="now">현재: ${esc(valueText(b.code, b.rate))}</p>`;
      else if (b.value) h += `<p class="now">현재: 보관 +${fmt(b.value)}</p>`;
      if (isHall) h += `<p class="now">다른 건물 최대 레벨: Lv${b.level + st.hall_headroom} · 마을 칸 ${st.cells}칸 (다음 레벨 ${st.cells_next}칸)</p>`;
    }
    if (b.build_finish) {
      h += `<div class="progress"><div>${b.level === 0 ? '건설' : 'Lv' + b.target_level + ' 업그레이드'} 중 · 남은 시간 <b data-until="${b.build_finish}"></b></div>
        <div class="pbar"><i data-from="${b.build_start}" data-to="${b.build_finish}"></i></div>
        <button class="btn" data-act="cancel" data-bid="${b.id}">공사 취소 (환급 ${st.ui.cancel_refund_pct}%)</button></div>`;
    }
    const n = b.next;
    if (n && !b.build_finish) {
      h += `<div class="next"><h3>Lv${n.level} 로 업그레이드</h3>
        <div class="costs">${costHtml(n.cost)}</div>
        <div class="meta">시간 ${fmtDur(n.time)}${valueText(b.code, n.value) ? ' · ' + esc(valueText(b.code, n.value)) : ''}</div>
        ${n.blocked ? `<div class="warn">${esc(n.blocked)}</div>` : ''}
        <button class="btn primary" data-act="upgrade" data-bid="${b.id}" data-cost='${JSON.stringify(n.cost)}' ${n.blocked ? 'disabled' : ''}>업그레이드</button></div>`;
    } else if (!n) {
      h += '<p class="muted">최대 레벨입니다.</p>';
    }
    if (!isHall) {
      h += '<div class="row">';
      if (!isWall) h += `<button class="btn" data-act="move" data-bid="${b.id}">이동·맞바꾸기</button>`;
      if (!b.build_finish) h += `<button class="btn danger" data-act="demolish" data-bid="${b.id}">철거 (환급 ${st.ui.demolish_refund_pct}%)</button>`;
      h += '</div>';
    }
    return h;
  }

  function buildMenuHtml(slot) {
    const st = G.st;
    const isWall = slot === 90;
    let h = `<h2>${isWall ? '성벽 짓기' : '건물 짓기'}</h2>`;
    if (!isWall) h += `<p class="muted">빈 칸 #${slot}</p>`;
    const groups = {};
    for (const code in st.defs) {
      const d = st.defs[code];
      if (d.category === 'hall') continue;
      if (isWall !== (d.category === 'wall')) continue;
      (groups[d.category] = groups[d.category] || []).push(code);
    }
    for (const cat in st.categories) {
      if (!groups[cat]) continue;
      h += `<h3>${esc(st.categories[cat])}</h3>`;
      for (const code of groups[cat]) {
        const d = st.defs[code];
        let why = '';
        if (st.hall_level < d.req_hall) why = `회관 Lv${d.req_hall} 필요`;
        else if (!d.multi && d.count > 0) why = '이미 있음 (1채만)';
        const val = valueText(code, d.value);
        h += `<div class="opt${why ? ' off' : ''}">
          <div class="opt-h"><b>${esc(d.name)}</b>${d.multi ? '<span class="tag">여러 채</span>' : ''}${val ? `<span class="val">${esc(val)}</span>` : ''}</div>
          <div class="descr">${esc(d.descr)}</div>
          <div class="costs">${costHtml(d.cost)} <span class="meta">· ${fmtDur(d.time)}</span></div>
          ${why ? `<div class="warn">${esc(why)}</div>` : `<button class="btn primary" data-act="build" data-code="${esc(code)}" data-slot="${slot}" data-cost='${JSON.stringify(d.cost)}'>짓기</button>`}
        </div>`;
      }
    }
    return h;
  }

  async function onPanelAction(e) {
    const n = e.currentTarget, act = n.dataset.act, bid = Number(n.dataset.bid);
    if (act === 'move') { startMove(bid); return; }
    if (act === 'demolish') {
      const b = bld(bid), d = G.st.defs[b.code];
      if (!confirm(`${d.name} Lv${b.level} 을(를) 철거할까요?\n들어간 비용의 ${G.st.ui.demolish_refund_pct}% 만 돌려받습니다.`)) return;
    }
    if (act === 'cancel' && !confirm('공사를 취소할까요?')) return;
    n.disabled = true;
    let j;
    if (act === 'build') j = await api('build', { code: n.dataset.code, slot: Number(n.dataset.slot) });
    else j = await api(act, { bid });
    if (!j) { n.disabled = false; return; }
    if (act === 'build') {
      const nb = G.st.buildings.find((b) => b.slot === Number(n.dataset.slot) && b.code === n.dataset.code);
      if (nb) select({ type: 'bld', id: nb.id });
      toast('공사를 시작했습니다.');
    } else if (act === 'upgrade') toast('업그레이드를 시작했습니다.');
    else if (act === 'demolish') { closePanel(); toast('철거했습니다.'); }
    else if (act === 'cancel') toast('공사를 취소했습니다.');
  }

  function tickPanel() {
    const body = $('#panelbody');
    if ($('#panel').hidden) return;
    const now = serverNow();
    body.querySelectorAll('[data-until]').forEach((n) => { n.textContent = fmtDur(Number(n.dataset.until) - now); });
    body.querySelectorAll('[data-from]').forEach((n) => {
      const a = Number(n.dataset.from), b = Number(n.dataset.to);
      n.style.width = Math.min(100, Math.max(0, (now - a) / Math.max(0.001, b - a) * 100)) + '%';
    });
    body.querySelectorAll('.cost[data-r]').forEach((n) => n.classList.toggle('short', curRes(n.dataset.r) + 1e-9 < Number(n.dataset.amt)));
    const full = busyCount() >= G.st.max_builds;
    body.querySelectorAll('button[data-cost]').forEach((n) => {
      const ok = canAfford(JSON.parse(n.dataset.cost));
      const blocked = n.dataset.act === 'upgrade' && n.closest('.next').querySelector('.warn');
      n.disabled = !ok || full || !!blocked;
      n.title = full ? `동시 공사는 ${G.st.max_builds}곳까지` : ok ? '' : '자원 부족';
    });
  }

  // ───────────── 기록 ─────────────
  function renderLogs() {
    const ul = $('#logs');
    ul.innerHTML = G.st.logs.map((l) => `<li class="k-${esc(l.kind)}"><time>${fmtTime(l.t)}</time> ${esc(l.msg)}</li>`).join('') || '<li class="muted">기록이 없습니다.</li>';
  }

  // ───────────── 주기 갱신 ─────────────
  function tick() {
    if (!G.st) return;
    renderTop(false);
    const now = serverNow();
    for (const u of G.bubbles) {
      const b = u.b, left = b.build_finish - now;
      u.txt.textContent = `${b.level === 0 ? '건설' : 'Lv' + b.target_level} ${fmtDur(left)}`;
      u.bar.setAttribute('width', Math.min(1, Math.max(0, (now - b.build_start) / Math.max(0.001, b.build_finish - b.build_start))) * u.w);
    }
    tickPanel();
    // 완공 시각이 지나면 서버에서 다시 읽는다 (서버가 같은 시각에 완공 처리)
    const due = G.st.buildings.some((b) => b.build_finish && b.build_finish <= now);
    const pollDue = Date.now() - G.lastPoll > G.st.ui.poll_sec * 1000;
    if ((due || pollDue) && !G.refreshing && Date.now() - G.lastPoll > 1000) refresh();
  }

  // ───────────── 시작 ─────────────
  function init() {
    const svg = $('#vsvg');
    G.cam = new window.VgCamera(svg, { target: $('#world'), min: 0.2, max: 3 });
    $('#zin').onclick = () => G.cam.zoomCenter(1.25);
    $('#zout').onclick = () => G.cam.zoomCenter(0.8);
    $('#zfit').onclick = fitCamera;
    $('#panelclose').onclick = closePanel;
    $('#movecancel').onclick = endMove;
    $('#wallbtn').onclick = () => select({ type: 'wall' });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') { if (G.moving) endMove(); else closePanel(); }
    });
    $('#vname').onclick = async () => {
      const nm = prompt('새 마을 이름 (1~30자)', G.st.village.name);
      if (nm && nm.trim() && nm !== G.st.village.name) { if (await api('rename', { name: nm })) toast('마을 이름을 바꿨습니다.'); }
    };
    document.querySelectorAll('.tabs [data-tab]').forEach((b) => b.addEventListener('click', () => {
      document.querySelectorAll('.tabs [data-tab]').forEach((x) => x.classList.toggle('on', x === b));
      document.querySelectorAll('main .tab').forEach((t) => t.classList.toggle('on', t.id === 'tab-' + b.dataset.tab));
    }));
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    refresh();
    setInterval(tick, 250);
  }

  init();
})();
