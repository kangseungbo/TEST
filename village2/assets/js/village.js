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
  const pts = (arr) => arr.map((p) => p[0].toFixed(1) + ',' + p[1].toFixed(1)).join(' ');
  const Art = window.VgArt;

  /** 건물 한 채 (그림은 art.js). 반환: 그림 높이 */
  function drawBuilding(g, b) {
    if (b.level === 0) return Art.scaffold(g);
    const d = G.st.defs[b.code];
    return Art.building(g, b.code, b.level, d ? d.category : '');
  }

  function wallCorners() {
    const c = (gx, gy) => { const p = pos(gx, gy); return [p.x, p.y]; };
    const e = 0.62;
    return { top: c(-e, -e), right: c(4 + e, -e), bottom: c(4 + e, 4 + e), left: c(-e, 4 + e) };
  }

  /** 성벽 뒤/앞. 공사 중(Lv0)이면 목책을 흐리게 */
  function drawWall(layer, which, wallB) {
    if (!wallB) return;
    const g = el('g', { class: 'wall', 'data-wall': which, opacity: wallB.level === 0 ? 0.45 : 1 }, layer);
    Art.wall(g, which, Math.max(1, wallB.level), wallCorners());
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
      // 숨겨진 동안 그린 이름표는 크기 측정이 0 이라 마을 탭으로 돌아오면 다시 그린다
      if (b.dataset.tab === 'village' && G.st) renderVillage();
    }));
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    refresh();
    setInterval(tick, 250);
  }

  init();
})();
