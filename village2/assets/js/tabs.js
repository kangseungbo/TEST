// 주민·병력·연구 탭. 마을 화면(village.js)의 상태·API 를 VgCore 로 받아 쓴다.
(function () {
  'use strict';
  const NS = 'http://www.w3.org/2000/svg';
  const C = () => window.VgCore;
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => [...(r || document).querySelectorAll(s)];

  /** 병종·주민 그림 (작은 SVG) */
  function unitSvg(code, category, size) {
    size = size || 56;
    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '-34 -66 68 72');
    svg.setAttribute('width', size);
    svg.setAttribute('height', size);
    svg.setAttribute('class', 'uart');
    const g = document.createElementNS(NS, 'g');
    svg.appendChild(g);
    const sh = document.createElementNS(NS, 'ellipse');
    sh.setAttribute('rx', 20); sh.setAttribute('ry', 4); sh.setAttribute('fill', 'rgba(60,40,20,.18)');
    g.appendChild(sh);
    const q = document.createElementNS(NS, 'g');
    g.appendChild(q);
    if (code === 'villager') window.VgArt.villager(q);
    else window.VgArt.unit(q, code, { category });
    return svg.outerHTML;
  }

  const hours = (h) => {
    const m = Math.max(0, Math.round(h * 60)), hh = Math.floor(m / 60), mm = m % 60;
    return hh ? `${hh}시간${mm ? ' ' + mm + '분' : ''}` : `${mm}분`;
  };

  // ───────────── 주민 ─────────────
  function renderPeople(st) {
    const { esc, fmt } = C();
    const ui = st.villager_ui, pop = st.pop;
    const places = st.buildings.filter((b) => b.level > 0 && st.defs[b.code] && st.defs[b.code].category === 'production');
    const taken = {};
    for (const v of st.villagers) if (v.building_id) taken[v.building_id] = (taken[v.building_id] || 0) + 1;
    let h = `<div class="parch card">
      <div class="head-row">
        <h2>주민 <small>인구 ${pop.count}/${pop.cap} · 일하는 주민 ${pop.working}명</small></h2>
        <button class="btn primary" data-act="hire" ${pop.count >= pop.cap ? 'disabled title="인구가 가득 찼습니다"' : ''}>주민 받기 (무료)</button>
      </div>
      <p class="muted">주민은 고용비 없이 식량만 먹습니다 (1명당 시간당 ${fmt(ui.food_each)}). 인구 상한은 회관 레벨에 따라 늘어납니다.
        생산 건물에 배치하면 그 건물 생산량 +${ui.base_pct}% (레벨당 +${ui.per_level_pct}%),
        같은 종류 건물에서 ${ui.spec_hours}시간 일하면 특화되어 +${ui.spec_pct}% 더.
        건물 한 채에 ${ui.max_per_building}명까지.</p>
    </div>`;
    if (!st.villagers.length) h += `<div class="parch card muted">아직 주민이 없습니다. '주민 받기' 를 누르세요.</div>`;
    h += '<div class="vgrid">';
    for (const v of st.villagers) {
      const xpPct = v.xp_next == null ? 100 : Math.min(100, ((v.xp - v.xp_prev) / Math.max(0.001, v.xp_next - v.xp_prev)) * 100);
      let opts = '<option value="0">쉬게 하기</option>';
      for (const b of places) {
        const full = (taken[b.id] || 0) >= ui.max_per_building && v.building_id !== b.id;
        opts += `<option value="${b.id}" ${v.building_id === b.id ? 'selected' : ''} ${full ? 'disabled' : ''}>${esc(st.defs[b.code].name)} Lv${b.level} (#${b.slot})${full ? ' - 자리 없음' : ''}${v.spec_code === b.code ? ' ★특화' : ''}</option>`;
      }
      const specLeft = v.job_since && v.spec_code !== v.job_code ? Math.max(0, ui.spec_hours - (C().serverNow() - v.job_since) / 3600) : null;
      h += `<div class="parch vcard">
        <div class="vtop">${unitSvg('villager', '', 52)}<div>
          <b>${esc(v.name)}</b> <small>Lv${v.level}</small>
          ${v.spec_name ? `<span class="tag">${esc(v.spec_name)} 특화</span>` : ''}
          <div class="xpbar" title="근무 ${hours(v.xp)}${v.xp_next != null ? ' / 다음 레벨 ' + hours(v.xp_next) : ''}"><i style="width:${xpPct}%"></i></div>
          <div class="small">${v.building_id ? `${esc(v.job_name)}에서 일하는 중 <span class="ok">+${Math.round(v.bonus_pct)}%</span>` : '<span class="muted">쉬는 중</span>'}</div>
          ${specLeft != null ? `<div class="small muted">${esc(v.job_name)} 특화까지 ${hours(specLeft)}</div>` : ''}
        </div></div>
        <div class="vact">
          <select data-vil="${v.id}">${opts}</select>
          <button class="btn small" data-act="assign" data-vil="${v.id}">배치</button>
          <button class="btn small danger" data-act="fire" data-vil="${v.id}" data-name="${esc(v.name)}">내보내기</button>
        </div>
      </div>`;
    }
    h += '</div>';
    if (!places.length && st.villagers.length) h += `<div class="parch card muted">배치할 수 있는 생산 건물(완공된 농장·벌목장·광산 등)이 없습니다.</div>`;
    return h;
  }

  // ───────────── 병력 ─────────────
  function renderArmy(st) {
    const { esc, fmt, fmtDur, costHtml } = C();
    const up = st.upkeep, tu = st.train_ui;
    const total = st.home_units.reduce((a, u) => a + u.count, 0);
    let h = `<div class="parch card">
      <h2>병력 <small>마을 병력 ${fmt(total)}명</small></h2>
      <p>식량 유지비 <b>${fmt(up.total)}</b>/시간 <span class="muted">(주민 ${fmt(up.villagers)} + 병력 ${fmt(up.units)})</span>
        ${st.worker_build_pct > 0 ? ` · 일꾼 덕분에 건설 시간 <span class="ok">−${Math.round(st.worker_build_pct)}%</span>` : ''}</p>
      <p class="muted small">훈련 건물 종류마다 대기열이 따로 돌아가고(동시 진행), 같은 종류 건물의 레벨 합이 클수록 빨라집니다.
        훈련 취소 시 남은 수량 비용의 ${tu.cancel_refund_pct}% 를 돌려받고, 해산하면 돌려받지 못합니다. 식량이 바닥나면 병사가 떠납니다.</p>
    </div>`;

    for (const g of st.train) {
      h += `<div class="parch card" id="train-${esc(g.bld)}">
        <h2>${esc(g.name)} <small>${g.levels > 0 ? `레벨 합 ${g.levels} · 속도 ×${g.mult.toFixed(2)}` : '없음 — 먼저 지어야 훈련할 수 있습니다'}</small></h2>`;
      if (g.queue.length) {
        h += '<div class="queue">';
        for (const q of g.queue) {
          h += `<div class="qrow" data-q="${q.id}" data-head="${q.head ? 1 : 0}" data-pat="${q.progress_at}" data-up="${q.unit_progress}" data-ut="${q.unit_time || 0}" data-done="${q.done}" data-total="${q.total}" data-finish="${q.finish || 0}">
            <b>${esc(q.name)}</b> <span class="qn">${q.done}/${q.total}</span>
            <div class="pbar"><i style="width:0"></i></div>
            <span class="qleft">${q.head ? '' : '대기'}</span>
            <button class="btn small" data-act="train_cancel" data-qid="${q.id}">취소</button>
          </div>`;
        }
        h += '</div>';
      }
      h += '<div class="ugrid">';
      for (const u of g.units) {
        const locked = g.max_level < u.req_level;
        h += `<div class="ucard${locked ? ' off' : ''}">
          <div class="utop">${unitSvg(u.code, u.category, 64)}<div>
            <b>${esc(u.name)}</b> <span class="tag">${esc(u.category_name)}${u.ranged && u.category !== 'ranged' ? '·원거리' : ''}</span>
            <div class="stats">공격 ${u.attack.toFixed(1).replace(/\.0$/, '')} · 방어 ${u.defense.toFixed(1).replace(/\.0$/, '')} · 식량 ${u.upkeep}/시간</div>
            ${u.strong.length ? `<div class="small">강함: <span class="ok">${u.strong.map(esc).join(', ')}</span></div>` : ''}
            ${u.weak.length ? `<div class="small">약함: <span class="warn">${u.weak.map(esc).join(', ')}</span></div>` : ''}
          </div></div>
          <div class="descr small">${esc(u.descr)}</div>
          <div class="costs">${costHtml(u.cost)} <span class="meta">· 1명 ${u.time ? fmtDur(u.time) : '-'}</span></div>
          ${locked ? `<div class="warn">${esc(g.name)} Lv${u.req_level} 필요</div>` : `
          <div class="trow">
            <input type="number" min="1" max="${tu.max_batch}" value="1" data-count="${u.code}">
            <button class="btn small" data-add="10" data-for="${u.code}">+10</button>
            <button class="btn small" data-max="${u.code}" data-cost='${JSON.stringify(u.cost)}'>최대</button>
            <button class="btn primary small" data-act="train" data-unit="${u.code}" data-cost='${JSON.stringify(u.cost)}'>훈련</button>
            <span class="small muted" data-total-for="${u.code}"></span>
          </div>`}
        </div>`;
      }
      h += '</div></div>';
    }

    if (st.my_armies.length) {
      h += `<div class="parch card"><h2>출진한 부대 <small>${st.my_armies.length}/${st.march.army_max}</small></h2><div class="hgrid">`;
      for (const a of st.my_armies) {
        const names = Object.entries(a.units).map(([c, n]) => `${esc(st.unit_names[c] || c)} ${n}`).join(', ');
        const where = a.state === 'moving' ? (a.returning ? '회군 중' : '이동 중') : `주둔 (${a.q}, ${a.r})`;
        h += `<div class="hcard"><div><b>${esc(a.name)}</b> ${fmt(a.total)}명 <span class="small muted">${where}${a.task === 'gather' ? ' · 채집' : a.task === 'bridge' ? ' · 다리 건설' : ''}</span>
          <div class="small">${names}</div></div></div>`;
      }
      h += `</div><p><button class="btn small" data-go-map="1">세계 맵에서 보기</button></p></div>`;
    }
    h += `<div class="parch card"><h2>마을 병력</h2>`;
    if (!st.home_units.length) h += '<p class="muted">아직 병력이 없습니다.</p>';
    else {
      h += '<div class="hgrid">';
      for (const u of st.home_units) {
        h += `<div class="hcard">${unitSvg(u.code, u.category, 44)}<div><b>${esc(u.name)}</b> ${fmt(u.count)}명
          <div class="small muted">식량 ${fmt(u.count * u.upkeep)}/시간</div>
          <div class="trow"><input type="number" min="1" max="${u.count}" value="1" data-disband="${u.code}">
          <button class="btn small danger" data-act="disband" data-unit="${u.code}" data-name="${esc(u.name)}">해산</button></div></div></div>`;
      }
      h += '</div>';
    }
    h += '</div>';
    return h;
  }

  // ───────────── 연구 ─────────────
  function renderResearch(st) {
    const { esc, fmtDur, costHtml } = C();
    const busy = st.research.filter((r) => r.finish).length;
    let h = `<div class="parch card"><h2>대장간 연구 <small>${st.smithy_level > 0 ? `대장간 Lv${st.smithy_level}` : '대장간이 없습니다'} · 동시 연구 ${busy}/${st.research_max}</small></h2>
      <p class="muted small">연구 레벨마다 효과가 쌓입니다. 공격·방어·운반은 전투·약탈이 열리는 5단계, 행군 속도는 4단계부터 적용되고 훈련 속도는 지금 바로 적용됩니다.</p></div>
      <div class="rgrid">`;
    for (const r of st.research) {
      const n = r.next;
      h += `<div class="parch rcard">
        <div class="rh"><b>${esc(r.name)}</b> <small>Lv${r.level}/${r.max_level}</small></div>
        <div class="small">${esc(r.effect)} +${r.value}%/Lv · 대상 ${esc(r.target)} <span class="muted">(${esc(r.effect_when)})</span></div>
        <div class="small ok">현재 +${(r.level * r.value).toFixed(0)}%</div>
        <div class="descr small">${esc(r.descr)}</div>`;
      if (r.finish) {
        h += `<div class="progress">Lv${r.target_level} 연구 중 · <b data-until="${r.finish}"></b>
          <div class="pbar"><i data-from="${r.start}" data-to="${r.finish}"></i></div>
          <button class="btn small" data-act="research_cancel" data-code="${esc(r.code)}">취소</button></div>`;
      } else if (n) {
        const need = st.smithy_level < n.req;
        h += `<div class="costs">${costHtml(n.cost)} <span class="meta">· ${fmtDur(n.time)}</span></div>
          ${need ? `<div class="warn">대장간 Lv${n.req} 필요</div>` : ''}
          <button class="btn primary small" data-act="research" data-code="${esc(r.code)}" data-cost='${JSON.stringify(n.cost)}' ${need || busy >= st.research_max ? 'disabled' : ''}>Lv${n.level} 연구</button>`;
      } else {
        h += '<div class="muted small">최대 레벨</div>';
      }
      h += '</div>';
    }
    h += '</div>';
    return h;
  }

  // ───────────── 공통 ─────────────
  function keepInputs(root, fn) {
    // 다시 그려도 입력 중인 수량·선택은 유지
    const saved = {};
    $$('input[data-count], input[data-disband], select[data-vil]', root).forEach((n) => { saved[n.outerHTML.match(/data-\w+="[^"]*"/)[0]] = n.value; });
    const scroll = root.scrollTop;
    fn();
    $$('input[data-count], input[data-disband], select[data-vil]', root).forEach((n) => {
      const k = n.outerHTML.match(/data-\w+="[^"]*"/)[0];
      if (k in saved) n.value = saved[k];
    });
    root.scrollTop = scroll;
  }

  // 입력 중인 탭은 다시 그리지 않고, 포커스가 빠질 때 그린다 (입력 도중 커서가 사라지지 않게)
  const pending = {};
  function renderTab(id, fn, st) {
    const root = $('#' + id);
    if (!root) return;
    if (root.contains(document.activeElement) && document.activeElement.matches('input, select')) { pending[id] = fn; return; }
    delete pending[id];
    keepInputs(root, () => { root.innerHTML = fn(st); });
  }
  function render(st) {
    renderTab('tab-people', renderPeople, st);
    renderTab('tab-army', renderArmy, st);
    renderTab('tab-research', renderResearch, st);
    tick();
  }
  document.addEventListener('focusout', () => setTimeout(() => {
    const st = C() && C().G.st;
    if (!st) return;
    for (const id in pending) renderTab(id, pending[id], st);
  }, 0));

  function countOf(code) {
    const n = $(`input[data-count="${code}"]`);
    return Math.max(1, Math.floor(Number(n && n.value) || 1));
  }

  function tick() {
    const core = C();
    if (!core || !core.G.st) return;
    const now = core.serverNow();
    // 훈련 대기열: 맨 앞 주문은 진행률·완성 수를 실시간 계산 (서버와 같은 식)
    $$('.qrow').forEach((n) => {
      const d = n.dataset, total = +d.total;
      let done = +d.done, frac = 0;
      if (d.head === '1' && +d.ut > 0) {
        const prog = +d.up + (now - +d.pat) / +d.ut;
        done = Math.min(total, +d.done + Math.floor(prog));
        frac = done >= total ? 1 : prog - Math.floor(prog);
      }
      $('.qn', n).textContent = `${done}/${total}`;
      $('.pbar i', n).style.width = (d.head === '1' ? frac * 100 : 0) + '%';
      if (+d.finish > 0) $('.qleft', n).textContent = (d.head === '1' ? '' : '대기 · ') + '완료까지 ' + core.fmtDur(+d.finish - now);
      else if (d.head === '1') $('.qleft', n).textContent = '멈춤 (훈련 건물 없음)';
    });
    $$('#tab-research [data-until]').forEach((n) => { n.textContent = core.fmtDur(Number(n.dataset.until) - now); });
    $$('#tab-research [data-from]').forEach((n) => {
      const a = +n.dataset.from, b = +n.dataset.to;
      n.style.width = Math.min(100, Math.max(0, ((now - a) / Math.max(0.001, b - a)) * 100)) + '%';
    });
    // 수량 × 비용 표시와 자원 부족 표시
    $$('#tab-army .ucard').forEach((card) => {
      const inp = $('input[data-count]', card);
      const mul = inp ? countOf(inp.dataset.count) : 1;
      $$('.cost[data-r]', card).forEach((c) => {
        const amt = +c.dataset.each * mul;
        c.dataset.amt = amt;
        c.querySelector('b').textContent = core.fmt(amt);
      });
    });
    $$('#tab-army .cost[data-r], #tab-research .cost[data-r]').forEach((c) => c.classList.toggle('short', core.curRes(c.dataset.r) + 1e-9 < +c.dataset.amt));
    $$('#tab-army button[data-act="train"]').forEach((b) => {
      const cost = JSON.parse(b.dataset.cost), mul = countOf(b.dataset.unit), total = {};
      for (const r in cost) total[r] = cost[r] * mul;
      b.disabled = !core.canAfford(total);
    });
    $$('#tab-research button[data-act="research"]').forEach((b) => {
      if (b.hasAttribute('data-locked')) return;
      const busy = core.G.st.research.filter((r) => r.finish).length >= core.G.st.research_max;
      const locked = !!b.closest('.rcard').querySelector('.warn');
      b.disabled = locked || busy || !core.canAfford(JSON.parse(b.dataset.cost));
    });
  }

  async function onClick(e) {
    const core = C();
    const t = e.target.closest('button');
    if (!t || !core) return;
    const d = t.dataset;
    if (d.goMap) { core.showTab('map'); return; }
    if (d.add) {
      const n = $(`input[data-count="${d.for}"]`);
      n.value = countOf(d.for) === 1 && n.value === '1' ? +d.add : countOf(d.for) + +d.add;
      tick();
      return;
    }
    if (d.max) {
      const cost = JSON.parse(d.cost);
      let m = core.G.st.train_ui.max_batch;
      for (const r in cost) if (cost[r] > 0) m = Math.min(m, Math.floor(core.curRes(r) / cost[r]));
      $(`input[data-count="${d.max}"]`).value = Math.max(1, m);
      tick();
      return;
    }
    if (!d.act) return;
    let payload = null, msg = '';
    switch (d.act) {
      case 'hire': payload = {}; msg = '새 주민이 들어왔습니다.'; break;
      case 'fire':
        if (!confirm(`${d.name} 을(를) 내보낼까요?`)) return;
        payload = { vil: +d.vil }; msg = '주민을 내보냈습니다.'; break;
      case 'assign': payload = { vil: +d.vil, bid: +$(`select[data-vil="${d.vil}"]`).value }; msg = '배치했습니다.'; break;
      case 'train': payload = { unit: d.unit, count: countOf(d.unit) }; msg = '훈련을 시작했습니다.'; break;
      case 'train_cancel':
        if (!confirm(`훈련을 취소할까요? 남은 수량 비용의 ${core.G.st.train_ui.cancel_refund_pct}% 만 돌려받습니다.`)) return;
        payload = { qid: +d.qid }; msg = '훈련을 취소했습니다.'; break;
      case 'disband': {
        const n = Math.floor(+$(`input[data-disband="${d.unit}"]`).value || 0);
        if (!confirm(`${d.name} ${n}명을 해산할까요? 비용은 돌려받지 못합니다.`)) return;
        payload = { unit: d.unit, count: n }; msg = '해산했습니다.'; break;
      }
      case 'research': payload = { code: d.code }; msg = '연구를 시작했습니다.'; break;
      case 'research_cancel':
        if (!confirm('연구를 취소할까요?')) return;
        payload = { code: d.code }; msg = '연구를 취소했습니다.'; break;
      default: return;
    }
    t.disabled = true;
    const j = await core.api(d.act, payload);
    if (j) core.toast(msg); else t.disabled = false;
  }

  document.addEventListener('click', (e) => {
    if (e.target.closest('#tab-people, #tab-army, #tab-research')) onClick(e);
  });
  document.addEventListener('input', (e) => { if (e.target.matches('input[data-count]')) tick(); });

  window.VgTabs = { render, tick };
})();
