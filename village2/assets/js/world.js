// 세계 맵 탭: 부대 편성·출진, 이동(경로 미리보기·도착 예정), 회군, 채집, 다리 건설, 타일 정보.
(function () {
  'use strict';
  const C = () => window.VgCore;
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => [...(r || document).querySelectorAll(s)];

  const W = {
    map: null, mapData: null, armies: [], armiesAt: 0, visible: false, loadingMap: false,
    mode: null,          // null | 'create' | 'move' | 'bridge'
    selTile: null,       // {q, r}
    selArmy: null,       // army id
    dest: null,          // {q, r}
    form: {},            // 편성 수량 {code: n}
    lastPoll: 0,
  };

  function st() { return C().G.st; }
  const myVid = () => st() && st().village.id;
  const myArmy = (id) => W.armies.find((a) => a.id === id && a.mine);

  // ───────────── 데이터 ─────────────
  async function loadMap() {
    if (W.loadingMap) return;
    W.loadingMap = true;
    try {
      const r = await fetch('api.php?a=map', { credentials: 'same-origin', cache: 'no-store' });
      const j = await r.json();
      if (j.ok) {
        W.mapData = j.map;
        W.map.opts.myVid = myVid();
        W.map.setMap(j.map);
        if (st().home && !W.centered) { W.map.centerOn(st().home.q, st().home.r, 0.9); W.centered = true; }
      }
    } finally { W.loadingMap = false; }
  }

  async function loadArmies() {
    W.lastPoll = Date.now();
    try {
      const r = await fetch('api.php?a=armies', { credentials: 'same-origin', cache: 'no-store' });
      const j = await r.json();
      if (j.ok) setArmies(j.armies);
    } catch (e) { /* 다음 주기에 다시 */ }
  }

  function setArmies(list) {
    W.armies = list;
    if (W.selArmy && !myArmy(W.selArmy)) { W.selArmy = null; if (W.mode === 'move' || W.mode === 'bridge') W.mode = null; }
    if (W.map) { W.map.selArmy = W.selArmy; W.map.setArmies(list); }
    renderPanel();
  }

  async function act(name, payload, okMsg) {
    const j = await C().api(name, payload);
    if (!j) return null;
    if (j.armies) setArmies(j.armies);
    if (okMsg) C().toast(okMsg);
    return j;
  }

  // ───────────── 계산 ─────────────
  function secPerTileFor(counts) {
    const m = st().march;
    let slow = 0;
    for (const c in counts) if (counts[c] > 0) slow = Math.max(slow, m.unit_speed[c] || 60);
    if (!slow) slow = 60;
    return slow / (1 + m.bonus_pct / 100) / Math.max(0.01, m.speed_mult);
  }
  const isHome = (q, r) => st().home && st().home.q === q && st().home.r === r;
  const returnFactor = () => 100 / Math.max(1, st().march.return_pct);

  /** 부대가 새 길을 시작하는 타일과 그때까지 남은 초 */
  function armyStart(a) {
    if (a.state !== 'moving' || !a.path) return { q: a.q, r: a.r, wait: 0 };
    const el = C().serverNow() - a.depart_at, p = a.path;
    let i = 0;
    while (i + 1 < p.length && p[i + 1][2] <= el) i++;
    if (i + 1 >= p.length) return { q: p[i][0], r: p[i][1], wait: 0 };
    return { q: p[i + 1][0], r: p[i + 1][1], wait: Math.max(0, p[i + 1][2] - el) };
  }

  function updatePreview() {
    W.map.preview = null;
    if (!W.dest) { W.map.draw(); return; }
    let from, spt, wait = 0;
    if (W.mode === 'create') {
      if (!st().home) return;
      from = st().home; spt = secPerTileFor(W.form);
    } else if (W.mode === 'move') {
      const a = myArmy(W.selArmy);
      if (!a) return;
      const s0 = armyStart(a);
      from = s0; wait = s0.wait; spt = a.sec_per_tile;
    } else return;
    const tiles = W.map.findPath(from.q, from.r, W.dest.q, W.dest.r, myVid());
    if (!tiles) {
      W.map.preview = { tiles: [[from.q, from.r], [W.dest.q, W.dest.r]], ok: false, label: '갈 수 없음' };
      W.eta = null;
    } else {
      const f = isHome(W.dest.q, W.dest.r) ? returnFactor() : 1;
      W.eta = wait + W.map.pathTime(tiles, spt, f);
      W.map.preview = { tiles, ok: true, label: C().fmtDur(W.eta) };
    }
    W.map._animate();
    W.map.draw();
  }

  // ───────────── 지도 클릭 ─────────────
  function onClick(t, army) {
    if (W.mode === 'bridge') {
      if (!t) return;
      const k = window.VgMap.key(t.q, t.r);
      if (!W.map.targetTiles || !W.map.targetTiles.includes(k)) { C().toast('부대 바로 옆 강 타일을 고르세요.', 'err'); return; }
      const m = st().march;
      if (!confirm(`(${t.q}, ${t.r}) 에 다리를 놓을까요?\n나무 ${C().fmt(m.bridge_wood)} 이 듭니다.`)) return;
      act('army_bridge', { id: W.selArmy, q: t.q, r: t.r }, '다리 건설을 시작했습니다.').then(() => setMode(null));
      return;
    }
    if (W.mode === 'create' || W.mode === 'move') {
      if (!t) return;
      W.dest = { q: t.q, r: t.r };
      W.selTile = W.dest;
      W.map.sel = window.VgMap.key(t.q, t.r);
      updatePreview();
      renderPanel();
      return;
    }
    if (army && army.mine) { selectArmy(army.id); return; }
    W.selTile = t ? { q: t.q, r: t.r } : null;
    W.clickedArmy = army || null;
    W.map.sel = t ? window.VgMap.key(t.q, t.r) : null;
    W.map.draw();
    renderPanel();
  }

  function selectArmy(id) {
    W.selArmy = id;
    W.map.selArmy = id;
    const a = myArmy(id);
    if (a) {
      const p = W.map.armyPos(a);
      W.selTile = { q: p.q, r: p.r };
      W.map.sel = window.VgMap.key(p.q, p.r);
    }
    W.map.draw();
    renderPanel();
  }

  function setMode(mode) {
    W.mode = mode;
    W.dest = null;
    W.eta = null;
    W.map.preview = null;
    W.map.targetTiles = null;
    if (mode === 'bridge') {
      const a = myArmy(W.selArmy);
      const t = [];
      for (const [dq, dr] of window.VgMap.DIRS) {
        const n = W.map.tile(a.q + dq, a.r + dr);
        if (n && n.t === 'river' && !n.f) t.push(window.VgMap.key(n.q, n.r));
      }
      if (!t.length) { C().toast('부대 바로 옆에 다리를 놓을 강이 없습니다.', 'err'); W.mode = null; }
      W.map.targetTiles = t;
    }
    W.map.draw();
    renderPanel();
  }

  // ───────────── 패널 ─────────────
  function stateText(a) {
    const { fmtDur, serverNow } = C();
    if (a.state === 'moving') return `${a.returning ? '회군 중' : '이동 중'} · <b data-until="${a.arrive_at}">${fmtDur(a.arrive_at - serverNow())}</b>`;
    if (a.task === 'gather') return `주둔 (${a.q}, ${a.r}) · 채집 중`;
    if (a.task === 'bridge') return `주둔 (${a.q}, ${a.r}) · 다리 건설 <b data-until="${a.task_end}">${fmtDur(a.task_end - serverNow())}</b>`;
    return `주둔 (${a.q}, ${a.r})`;
  }

  function tileInfo(t) {
    const { esc } = C();
    const md = W.mapData, tile = W.map.tile(t.q, t.r);
    if (!tile) return '';
    const d = md.terrains[tile.t] || {};
    const f = tile.f ? md.features[tile.f] : null;
    const resName = (r) => st().res_names[r];
    let gather = f && f.res ? resName(f.res) : (tile.t === 'forest' && !tile.f ? resName('wood') : null);
    let h = `<div class="tinfo"><h3>(${t.q}, ${t.r}) ${esc(d.name || tile.t)}${f && tile.f !== 'village' ? ' · ' + esc(f.name) : ''}</h3>`;
    if (tile.v) h += `<div><b>${esc(tile.v.name)}</b> <span class="muted">${esc(tile.v.owner)}</span>${tile.v.id === myVid() ? ' <span class="tag">내 마을</span>' : ''}</div>`;
    h += `<div class="small">이동 비용 ×${tile.f === 'bridge' ? md.bridge_move_cost : d.move_cost}${d.passable || tile.f === 'bridge' ? '' : ' · <span class="warn">통행 불가</span>'}
      · 방어 ${d.def >= 0 ? '+' : ''}${d.def}% · 기병 ${d.cav >= 0 ? '+' : ''}${d.cav}%</div>`;
    if (gather) h += `<div class="small ok">일꾼이 ${esc(gather)}을(를) 모을 수 있음</div>`;
    if (d.descr) h += `<div class="small muted">${esc(d.descr)}</div>`;
    const here = W.armies.filter((a) => a.state !== 'moving' && a.q === t.q && a.r === t.r);
    for (const a of here) h += `<div class="small">${a.mine ? '내 ' : ''}부대: ${esc(a.village)} ${esc(a.name)} (${a.total}명)</div>`;
    if (W.clickedArmy && !W.clickedArmy.mine) h += `<div class="small">부대: ${esc(W.clickedArmy.village)} ${esc(W.clickedArmy.name)} · ${esc(W.clickedArmy.owner)} (${W.clickedArmy.total}명)</div>`;
    return h + '</div>';
  }

  function createForm() {
    const { esc, fmtDur } = C();
    const s = st();
    const home = Object.entries(s.home_units ? Object.fromEntries(s.home_units.map((u) => [u.code, u])) : {});
    let h = `<div class="mform"><h3>새 부대 편성</h3>`;
    if (!home.length) return h + '<p class="muted">마을에 병력이 없습니다. 병력 탭에서 먼저 훈련하세요.</p></div>';
    for (const [code, u] of home) {
      const v = W.form[code] || 0;
      h += `<label class="frow"><span>${esc(u.name)} <small class="muted">/${u.count}</small></span>
        <input type="number" min="0" max="${u.count}" value="${v}" data-form="${esc(code)}">
        <button class="btn small" data-all="${esc(code)}" data-n="${u.count}">전부</button></label>`;
    }
    const total = Object.values(W.form).reduce((a, b) => a + (+b || 0), 0);
    const spt = secPerTileFor(W.form);
    h += `<div class="small">부대 ${total}명 · 평원 1칸 ${fmtDur(spt)} (가장 느린 병종 기준)</div>`;
    h += W.dest ? `<div>목적지 (${W.dest.q}, ${W.dest.r}) · ${W.eta != null ? '도착까지 <b>' + fmtDur(W.eta) + '</b>' : '<span class="warn">갈 수 없음</span>'}</div>`
      : '<div class="hint">지도에서 목적지를 누르세요</div>';
    h += `<div class="row"><button class="btn primary" data-do="create" ${!total || !W.dest || W.eta == null ? 'disabled' : ''}>출진</button>
      <button class="btn" data-do="cancel-mode">취소</button></div></div>`;
    return h;
  }

  function armyDetail(a) {
    const { esc, fmt, fmtDur, serverNow } = C();
    const m = st().march;
    let h = `<div class="adetail"><h3>${esc(a.name)} <small>${a.total}명</small></h3>
      <div class="small">${stateText(a)}</div>
      <div class="small">${a.units.map((u) => `${esc(u.name)} ${u.count}`).join(' · ')}</div>
      <div class="small muted">평원 1칸 ${fmtDur(a.sec_per_tile)} · 회군은 ${m.return_pct}% 속도</div>`;
    const cargo = Object.entries(a.cargo || {}).filter(([, v]) => v >= 1);
    if (a.cargo_cap > 0) {
      h += `<div class="small">짐 ${cargo.length ? cargo.map(([r, v]) => `${esc(st().res_names[r])} ${fmt(v)}`).join(', ') : '없음'} / ${fmt(a.cargo_cap)}
        ${a.task === 'gather' ? ` <span class="ok">(시간당 +${fmt(a.gather_rate)})</span>` : ''}</div>`;
    }
    if (a.task === 'bridge') {
      h += `<div class="pbar"><i data-from="${a.task_start}" data-to="${a.task_end}"></i></div>`;
    }
    if (W.mode === 'move') {
      h += W.dest ? `<div>새 목적지 (${W.dest.q}, ${W.dest.r}) · ${W.eta != null ? '도착까지 <b>' + fmtDur(W.eta) + '</b>' : '<span class="warn">갈 수 없음</span>'}</div>`
        : '<div class="hint">지도에서 목적지를 누르세요 (내 마을을 고르면 회군)</div>';
      h += `<div class="row"><button class="btn primary" data-do="move" ${!W.dest || W.eta == null ? 'disabled' : ''}>이동</button>
        <button class="btn" data-do="cancel-mode">취소</button></div>`;
    } else if (W.mode === 'bridge') {
      h += `<div class="hint">파란 점선으로 표시된 강 타일을 누르세요 (나무 ${fmt(m.bridge_wood)})</div><div class="row"><button class="btn" data-do="cancel-mode">취소</button></div>`;
    } else {
      const tile = W.map.tile(a.q, a.r);
      const canGather = a.state === 'stationed' && !a.task && a.workers > 0 && tile &&
        ((tile.f && W.mapData.features[tile.f] && W.mapData.features[tile.f].res) || (tile.t === 'forest' && !tile.f));
      const canBridge = a.state === 'stationed' && !a.task && a.workers >= m.bridge_workers_min;
      h += `<div class="row">
        <button class="btn primary small" data-do="mode-move">이동</button>
        <button class="btn small" data-do="recall" ${a.returning ? 'disabled' : ''}>회군</button>
        ${a.task === 'gather' ? '<button class="btn small" data-do="gather-off">채집 멈춤</button>' : `<button class="btn small" data-do="gather-on" ${canGather ? '' : 'disabled title="주둔 중 + 일꾼 + 거점·숲에서만"'}>채집</button>`}
        <button class="btn small" data-do="mode-bridge" ${canBridge ? '' : `disabled title="주둔 중 + 일꾼 ${m.bridge_workers_min}명 이상"`}>다리 놓기</button>
        <button class="btn small" data-do="center">지도에서 보기</button></div>`;
    }
    return h + '</div>';
  }

  function renderPanel() {
    const P = $('#mappanel');
    if (!P || !st()) return;
    const { esc } = C();
    const s = st();
    const mine = W.armies.filter((a) => a.mine);
    let h = `<div class="mhead"><h2>내 부대 <small>${mine.length}/${s.march.army_max}</small></h2>
      <button class="btn small primary" data-do="mode-create" ${mine.length >= s.march.army_max || W.mode === 'create' ? 'disabled' : ''}>새 부대 편성</button></div>`;
    if (W.mode === 'create') h += createForm();
    if (!mine.length && W.mode !== 'create') h += '<p class="muted small">나가 있는 부대가 없습니다. 마을 병력으로 부대를 편성해 출진하세요. 부대에 있는 병력도 마을 식량을 먹습니다.</p>';
    h += '<div class="alist">';
    for (const a of mine) h += `<div class="arow${W.selArmy === a.id ? ' on' : ''}" data-army="${a.id}"><b>${esc(a.name)}</b> <small>${a.total}명</small><div class="small">${stateText(a)}</div></div>`;
    h += '</div>';
    const a = myArmy(W.selArmy);
    if (a) h += armyDetail(a);
    if (W.selTile) h += tileInfo(W.selTile);
    const keep = {};
    $$('input[data-form]', P).forEach((n) => { keep[n.dataset.form] = n === document.activeElement; });
    P.innerHTML = h;
    for (const code in keep) if (keep[code]) { const n = $(`input[data-form="${code}"]`, P); if (n) { n.focus(); n.setSelectionRange(99, 99); } }
  }

  async function onPanel(e) {
    const t = e.target.closest('[data-do], [data-army], [data-all]');
    if (!t) return;
    const s = st();
    if (t.dataset.army) { selectArmy(+t.dataset.army); return; }
    if (t.dataset.all) { W.form[t.dataset.all] = +t.dataset.n; updatePreview(); renderPanel(); return; }
    switch (t.dataset.do) {
      case 'mode-create': W.selArmy = null; W.map.selArmy = null; W.form = {}; setMode('create'); break;
      case 'mode-move': setMode('move'); break;
      case 'mode-bridge': setMode('bridge'); break;
      case 'cancel-mode': setMode(null); break;
      case 'center': { const a = myArmy(W.selArmy); if (a) { const p = W.map.armyPos(a); W.map.centerOn(p.q, p.r); } break; }
      case 'create': {
        const units = {};
        for (const c in W.form) if (+W.form[c] > 0) units[c] = +W.form[c];
        t.disabled = true;
        const j = await act('army_create', { units, q: W.dest.q, r: W.dest.r }, '출진했습니다.');
        if (j) {
          W.form = {};
          setMode(null);
          const ids = W.armies.filter((a) => a.mine).map((a) => a.id);
          if (ids.length) selectArmy(Math.max(...ids));
        } else t.disabled = false;
        break;
      }
      case 'move':
        t.disabled = true;
        if (await act('army_move', { id: W.selArmy, q: W.dest.q, r: W.dest.r }, isHome(W.dest.q, W.dest.r) ? '회군합니다.' : '이동합니다.')) setMode(null);
        else t.disabled = false;
        break;
      case 'recall': await act('army_recall', { id: W.selArmy }, '회군합니다.'); break;
      case 'gather-on': await act('army_gather', { id: W.selArmy, on: 1 }, '채집을 시작했습니다.'); break;
      case 'gather-off': await act('army_gather', { id: W.selArmy, on: 0 }, '채집을 멈췄습니다. 짐은 마을로 돌아가면 창고에 들어갑니다.'); break;
    }
    void s;
  }

  // ───────────── 주기 ─────────────
  function tick() {
    const core = C();
    if (!core || !st() || !W.visible) return;
    const now = core.serverNow();
    $$('#mappanel [data-until]').forEach((n) => { n.textContent = core.fmtDur(+n.dataset.until - now); });
    $$('#mappanel [data-from]').forEach((n) => {
      const a = +n.dataset.from, b = +n.dataset.to;
      n.style.width = Math.min(100, Math.max(0, (now - a) / Math.max(0.001, b - a) * 100)) + '%';
    });
    const due = W.armies.some((a) => (a.state === 'moving' && a.arrive_at <= now) || (a.task === 'bridge' && a.task_end <= now));
    if ((due && Date.now() - W.lastPoll > 1500) || Date.now() - W.lastPoll > st().march.map_poll_sec * 1000) loadArmies();
  }

  function onState(s) {
    if (!W.map) return;
    if (W.mapData && s.map_version !== W.mapData.version) loadMap();
    if (W.visible) renderPanel();
  }

  function show() {
    W.visible = true;
    if (!W.map) init();
    W.map.resize();
    if (!W.mapData) loadMap();
    loadArmies();
  }
  function hide() { W.visible = false; }

  function init() {
    W.map = new window.VgMap($('#mapcv'), { onClick, myVid: myVid() });
    W.map.now = () => C().serverNow();
    $('#mzin').onclick = () => W.map.cam.zoomCenter(1.25);
    $('#mzout').onclick = () => W.map.cam.zoomCenter(0.8);
    $('#mzfit').onclick = () => W.map.fit();
    $('#mzhome').onclick = () => { const h = st().home; if (h) W.map.centerOn(h.q, h.r, Math.max(0.8, W.map.cam.s)); };
    $('#mappanel').addEventListener('click', onPanel);
    $('#mappanel').addEventListener('input', (e) => {
      if (!e.target.dataset.form) return;
      const max = +e.target.max;
      W.form[e.target.dataset.form] = Math.max(0, Math.min(max, Math.floor(+e.target.value || 0)));
      updatePreview();
      renderPanel();
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && W.visible && W.mode) setMode(null); });
  }

  window.VgWorld = { show, hide, tick, onState };
})();
