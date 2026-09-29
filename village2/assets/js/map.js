// 육각 세계 맵 캔버스 렌더러 (게임·관리자 공용).
// - 뾰족한 위쪽 육각, 축좌표 (q, r). 보이는 영역의 타일만 그린다.
// - 카메라는 VgCamera (휠 커서 기준 줌, 드래그, 핀치, 드래그 후 클릭 무시)
// - 부대 위치는 path(출발 기준 초)와 depart_at 으로 매 프레임 계산
(function (global) {
  'use strict';

  const S = 26;                       // 육각 크기(중심→꼭짓점)
  const W = Math.sqrt(3) * S;
  const DIRS = [[1, 0], [1, -1], [0, -1], [-1, 0], [-1, 1], [0, 1]];
  const key = (q, r) => q + ',' + r;
  const hexDist = (q1, r1, q2, r2) => (Math.abs(q1 - q2) + Math.abs(q1 + r1 - q2 - r2) + Math.abs(r1 - r2)) / 2;
  const toPx = (q, r) => ({ x: W * (q + r / 2), y: 1.5 * S * r });

  function fromPx(x, y) {
    const qf = (Math.sqrt(3) / 3 * x - y / 3) / S, rf = (2 / 3 * y) / S, sf = -qf - rf;
    let q = Math.round(qf), r = Math.round(rf); const s = Math.round(sf);
    const dq = Math.abs(q - qf), dr = Math.abs(r - rf), ds = Math.abs(s - sf);
    if (dq > dr && dq > ds) q = -r - s; else if (dr > ds) r = -q - s;
    return { q, r };
  }

  /** 병종 그림 → 이미지 (art.js SVG 를 데이터 URL 로) */
  const imgCache = {};
  function unitImage(code, category, onload) {
    const k = code + '|' + category;
    if (imgCache[k]) return imgCache[k].complete ? imgCache[k] : null;
    const NS = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('xmlns', NS);
    svg.setAttribute('viewBox', '-36 -68 72 72');
    svg.setAttribute('width', 72); svg.setAttribute('height', 72);
    const g = document.createElementNS(NS, 'g');
    svg.appendChild(g);
    if (global.VgArt) global.VgArt.unit(g, code, { category });
    const img = new Image();
    img.onload = onload;
    img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(new XMLSerializer().serializeToString(svg));
    imgCache[k] = img;
    return null;
  }

  function shade(hex, f) { return global.VgArt ? global.VgArt.shade(hex, f) : hex; }

  class VgMap {
    constructor(canvas, opts) {
      this.cv = canvas;
      this.ctx = canvas.getContext('2d');
      this.opts = opts || {};
      this.map = null; this.tiles = new Map(); this.armies = []; this.now = () => Date.now() / 1000;
      this.preview = null; this.sel = null; this.hover = null; this.selArmy = null; this.targetTiles = null;
      this.cam = new global.VgCamera(canvas, { min: 0.15, max: 3, apply: () => this.draw() });
      this._raf = null;
      canvas.addEventListener('click', (e) => this._click(e));
      canvas.addEventListener('pointermove', (e) => {
        if (e.pointerType !== 'mouse') return;
        const t = this.tileAt(e);
        const k = t ? key(t.q, t.r) : null;
        if (k !== this.hover) { this.hover = k; this.draw(); if (this.opts.onHover) this.opts.onHover(t); }
      });
      canvas.addEventListener('pointerleave', () => { this.hover = null; this.draw(); });
      new ResizeObserver(() => this.resize()).observe(canvas);
    }

    setMap(map) {
      const first = !this.map;
      this.map = map;
      this.tiles.clear();
      for (const [q, r, t, f] of map.tiles) this.tiles.set(key(q, r), { q, r, t, f, v: null, ...toPx(q, r) });
      for (const v of map.villages) { const t = this.tiles.get(key(v.q, v.r)); if (t) t.v = v; }
      if (first) this.fit();
      this.draw();
    }
    setArmies(list) { this.armies = list || []; this.draw(); this._animate(); }
    tile(q, r) { return this.tiles.get(key(q, r)); }

    resize() {
      const r = this.cv.getBoundingClientRect(), dpr = global.devicePixelRatio || 1;
      if (!r.width) return;
      this.cv.width = Math.round(r.width * dpr); this.cv.height = Math.round(r.height * dpr);
      this.dpr = dpr;
      if (this.map && !this._fitted) this.fit();
      this.draw();
    }
    fit() {
      if (!this.map || !this.cv.getBoundingClientRect().width) return;
      const R = this.map.radius + 1;
      this.cam.fit({ x: -W * R, y: -1.5 * S * R, w: 2 * W * R, h: 3 * S * R }, 10);
      this._fitted = true;
    }
    centerOn(q, r, zoom) {
      const p = toPx(q, r), rc = this.cv.getBoundingClientRect();
      if (zoom) this.cam.s = zoom;
      this.cam.tx = rc.width / 2 - p.x * this.cam.s; this.cam.ty = rc.height / 2 - p.y * this.cam.s;
      this.cam.apply();
    }
    tileAt(e) {
      const rc = this.cv.getBoundingClientRect();
      const w = this.cam.toWorld(e.clientX - rc.left, e.clientY - rc.top);
      const h = fromPx(w.x, w.y);
      return this.tiles.get(key(h.q, h.r)) || null;
    }

    /** 부대 현재 화면 위치 (이동 중이면 두 타일 사이 보간) */
    armyPos(a) {
      if (a.state !== 'moving' || !a.path) return { ...toPx(a.q, a.r), q: a.q, r: a.r, idx: -1 };
      const el = this.now() - a.depart_at, p = a.path;
      let i = 0;
      while (i + 1 < p.length && p[i + 1][2] <= el) i++;
      if (i + 1 >= p.length) return { ...toPx(p[i][0], p[i][1]), q: p[i][0], r: p[i][1], idx: i };
      const A = toPx(p[i][0], p[i][1]), B = toPx(p[i + 1][0], p[i + 1][1]);
      const f = Math.max(0, Math.min(1, (el - p[i][2]) / Math.max(0.001, p[i + 1][2] - p[i][2])));
      return { x: A.x + (B.x - A.x) * f, y: A.y + (B.y - A.y) * f, q: p[i][0], r: p[i][1], idx: i };
    }

    _click(e) {
      const rc = this.cv.getBoundingClientRect();
      const w = this.cam.toWorld(e.clientX - rc.left, e.clientY - rc.top);
      // 부대 먼저 (표식 반지름 안)
      let best = null, bd = 16 / Math.max(0.4, this.cam.s) + 6;
      for (const a of this.armies) {
        const p = this.armyPos(a), d = Math.hypot(p.x - w.x, p.y - w.y - 4);
        if (d < bd) { bd = d; best = a; }
      }
      const t = this.tileAt(e);
      if (this.opts.onClick) this.opts.onClick(t, best, e);
    }

    _animate() {
      if (this._raf) return;
      const loop = () => {
        this._raf = null;
        if (!this.cv.offsetParent) return;
        if (!this.armies.some((a) => a.state === 'moving') && !this.preview) return;
        this.draw();
        this._raf = setTimeout(() => requestAnimationFrame(loop), 80);
      };
      this._raf = setTimeout(() => requestAnimationFrame(loop), 80);
    }

    // ───────────── 그리기 ─────────────
    draw() {
      const ctx = this.ctx, cv = this.cv, dpr = this.dpr || 1;
      if (!cv.width) return;
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      ctx.fillStyle = '#e8dcbc';
      ctx.fillRect(0, 0, cv.width, cv.height);
      if (!this.map) return;
      const s = this.cam.s;
      ctx.setTransform(dpr * s, 0, 0, dpr * s, dpr * this.cam.tx, dpr * this.cam.ty);
      const x0 = -this.cam.tx / s - W, y0 = -this.cam.ty / s - S * 2;
      const x1 = x0 + cv.width / dpr / s + 2 * W, y1 = y0 + cv.height / dpr / s + S * 4;
      const vis = [];
      for (const t of this.tiles.values()) if (t.x >= x0 && t.x <= x1 && t.y >= y0 && t.y <= y1) vis.push(t);
      vis.sort((a, b) => a.y - b.y || a.x - b.x);
      const detail = s >= 0.45;
      for (const t of vis) this._hex(t, detail);
      // 지형 무늬는 가까이서만, 거점·다리는 항상
      for (const t of vis) if (detail || (t.f && t.f !== 'village')) this._glyph(t, detail);
      if (this.targetTiles) for (const k of this.targetTiles) { const t = this.tiles.get(k); if (t) this._outline(t, '#2f6db0', 3, [5, 3]); }
      if (this.hover) { const t = this.tiles.get(this.hover); if (t) this._outline(t, 'rgba(255,255,255,.9)', 2.5); }
      if (this.sel) { const t = this.tiles.get(this.sel); if (t) this._outline(t, '#8b3a1e', 3.5); }
      for (const t of vis) if (t.v) this._village(t, s);
      this._paths();
      if (this.preview) this._previewPath(this.preview);
      this._armies(s);
    }

    _hexPath(t, inset) {
      const ctx = this.ctx, r = S - (inset || 0);
      ctx.beginPath();
      for (let i = 0; i < 6; i++) {
        const a = Math.PI / 180 * (60 * i - 30);
        const x = t.x + r * Math.cos(a), y = t.y + r * Math.sin(a);
        i ? ctx.lineTo(x, y) : ctx.moveTo(x, y);
      }
      ctx.closePath();
    }
    _hex(t, detail) {
      const ctx = this.ctx, def = this.map.terrains[t.t] || { color: '#ccc' };
      // 같은 지형도 타일마다 살짝 다른 색
      const jitter = (((t.q * 73856093) ^ (t.r * 19349663)) & 7) / 7 - 0.5;
      this._hexPath(t, -0.6);
      ctx.fillStyle = shade(def.color, jitter * 0.08);
      ctx.fill();
      if (detail) { ctx.strokeStyle = 'rgba(60,40,20,.18)'; ctx.lineWidth = 0.8; ctx.stroke(); }
    }
    _outline(t, color, w, dash) {
      const ctx = this.ctx;
      this._hexPath(t, w / 2);
      ctx.setLineDash(dash || []);
      ctx.strokeStyle = color; ctx.lineWidth = w; ctx.stroke();
      ctx.setLineDash([]);
    }
    _glyph(t, detail) {
      const ctx = this.ctx, x = t.x, y = t.y;
      const h = ((t.q * 928371 + t.r * 12345) >>> 0) % 5;
      if (!detail) { /* 멀리서는 지형 무늬 생략 */ } else if (t.t === 'forest') {
        for (const [dx, dy, sc] of [[-8, 2, 1], [6, 4, 0.9], [-1, -7, 0.85]]) {
          const px = x + dx + (h - 2), py = y + dy;
          ctx.fillStyle = '#6b4a2a'; ctx.fillRect(px - 1, py, 2, 4 * sc);
          ctx.fillStyle = '#3b6429';
          ctx.beginPath(); ctx.moveTo(px - 6 * sc, py + 1); ctx.lineTo(px + 6 * sc, py + 1); ctx.lineTo(px, py - 11 * sc); ctx.closePath(); ctx.fill();
          ctx.fillStyle = '#4f7d38';
          ctx.beginPath(); ctx.moveTo(px - 4.5 * sc, py - 4); ctx.lineTo(px + 4.5 * sc, py - 4); ctx.lineTo(px, py - 13 * sc); ctx.closePath(); ctx.fill();
        }
      } else if (t.t === 'mountain') {
        ctx.fillStyle = '#7d7366'; ctx.strokeStyle = '#4a4238'; ctx.lineWidth = 1;
        ctx.beginPath(); ctx.moveTo(x - 16, y + 9); ctx.lineTo(x - 3, y - 13); ctx.lineTo(x + 10, y + 9); ctx.closePath(); ctx.fill(); ctx.stroke();
        ctx.fillStyle = '#8d8375';
        ctx.beginPath(); ctx.moveTo(x - 2, y + 9); ctx.lineTo(x + 8, y - 6); ctx.lineTo(x + 17, y + 9); ctx.closePath(); ctx.fill(); ctx.stroke();
        ctx.fillStyle = '#f2efe8';
        ctx.beginPath(); ctx.moveTo(x - 7, y - 6); ctx.lineTo(x - 3, y - 13); ctx.lineTo(x + 1, y - 6); ctx.closePath(); ctx.fill();
      } else if (t.t === 'river' || t.t === 'lake') {
        ctx.strokeStyle = 'rgba(255,255,255,.55)'; ctx.lineWidth = 1.4;
        for (const dy of t.t === 'river' ? [-4, 4] : [-6, 2, 9]) {
          ctx.beginPath(); ctx.moveTo(x - 10, y + dy);
          ctx.quadraticCurveTo(x - 5, y + dy - 3, x, y + dy); ctx.quadraticCurveTo(x + 5, y + dy + 3, x + 10, y + dy);
          ctx.stroke();
        }
      } else if (t.t === 'plain' && !t.f && h < 2) {
        ctx.strokeStyle = 'rgba(80,110,40,.5)'; ctx.lineWidth = 1;
        for (const dx of [-6, 5]) { ctx.beginPath(); ctx.moveTo(x + dx, y + 5); ctx.lineTo(x + dx - 2, y); ctx.moveTo(x + dx, y + 5); ctx.lineTo(x + dx + 2, y); ctx.stroke(); }
      }
      if (t.f === 'bridge') {
        ctx.fillStyle = '#a8743f'; ctx.strokeStyle = '#5a3a1a'; ctx.lineWidth = 1;
        ctx.save(); ctx.translate(x, y); ctx.rotate(-0.5);
        ctx.fillRect(-15, -5, 30, 10); ctx.strokeRect(-15, -5, 30, 10);
        for (let i = -12; i < 15; i += 5) { ctx.beginPath(); ctx.moveTo(i, -5); ctx.lineTo(i, 5); ctx.stroke(); }
        ctx.restore();
      } else if (t.f === 'mine') {
        ctx.fillStyle = '#5d5448'; ctx.beginPath(); ctx.moveTo(x - 12, y + 8); ctx.lineTo(x, y - 10); ctx.lineTo(x + 12, y + 8); ctx.closePath(); ctx.fill();
        ctx.fillStyle = '#1c140e'; ctx.beginPath(); ctx.arc(x, y + 8, 5, Math.PI, 0); ctx.fill();
        ctx.fillStyle = '#a7b7c9'; ctx.beginPath(); ctx.arc(x + 8, y + 6, 2.2, 0, 7); ctx.fill();
      } else if (t.f === 'farm') {
        ctx.fillStyle = '#d8b845'; ctx.strokeStyle = '#8a7428'; ctx.lineWidth = 1;
        for (const [dx, dy] of [[-9, -6], [1, -6], [-9, 3], [1, 3]]) { ctx.fillRect(x + dx, y + dy, 8, 7); ctx.strokeRect(x + dx, y + dy, 8, 7); }
      } else if (t.f === 'port') {
        ctx.strokeStyle = '#2b3a4a'; ctx.lineWidth = 2.2; ctx.beginPath();
        ctx.moveTo(x, y - 10); ctx.lineTo(x, y + 8); ctx.moveTo(x - 6, y - 4); ctx.lineTo(x + 6, y - 4);
        ctx.moveTo(x - 9, y + 2); ctx.quadraticCurveTo(x, y + 14, x + 9, y + 2); ctx.stroke();
        ctx.beginPath(); ctx.arc(x, y - 12, 2.5, 0, 7); ctx.stroke();
      }
    }
    _village(t, s) {
      const ctx = this.ctx, x = t.x, y = t.y, mine = this.opts.myVid && t.v.id === this.opts.myVid;
      if (mine) { this._outline(t, '#e6b83a', 3); }
      ctx.lineWidth = 1; ctx.strokeStyle = '#3a2616';
      ctx.fillStyle = '#e9dcbc'; ctx.fillRect(x - 11, y - 4, 22, 12); ctx.strokeRect(x - 11, y - 4, 22, 12);
      ctx.fillStyle = mine ? '#2f6db0' : '#b0452e';
      ctx.beginPath(); ctx.moveTo(x - 14, y - 3); ctx.lineTo(x, y - 15); ctx.lineTo(x + 14, y - 3); ctx.closePath(); ctx.fill(); ctx.stroke();
      ctx.fillStyle = '#3d2c1c'; ctx.fillRect(x - 2.5, y + 1, 5, 7);
      if (s >= 0.3) {
        const fs = Math.max(10, 11 / Math.max(0.6, s));
        ctx.font = `700 ${fs}px sans-serif`;
        ctx.textAlign = 'center';
        const label = t.v.name;
        const w = ctx.measureText(label).width + 8;
        ctx.fillStyle = 'rgba(250,240,214,.92)'; ctx.fillRect(x - w / 2, y + 12, w, fs + 4);
        ctx.fillStyle = '#3b2a18'; ctx.fillText(label, x, y + 12 + fs);
      }
    }
    _line(pts, color, w, dash) {
      if (pts.length < 2) return;
      const ctx = this.ctx;
      ctx.beginPath(); ctx.moveTo(pts[0].x, pts[0].y);
      for (const p of pts.slice(1)) ctx.lineTo(p.x, p.y);
      ctx.setLineDash(dash || []); ctx.strokeStyle = color; ctx.lineWidth = w; ctx.lineJoin = 'round'; ctx.lineCap = 'round';
      ctx.stroke(); ctx.setLineDash([]);
    }
    _paths() {
      for (const a of this.armies) {
        if (a.state !== 'moving' || !a.path || !a.mine) continue;
        const p = this.armyPos(a);
        const pts = [{ x: p.x, y: p.y }].concat(a.path.slice(p.idx + 1).map(([q, r]) => toPx(q, r)));
        this._line(pts, a.returning ? 'rgba(90,90,90,.8)' : 'rgba(47,109,176,.85)', 3, [7, 5]);
        const end = pts[pts.length - 1];
        this.ctx.fillStyle = a.returning ? '#666' : '#2f6db0';
        this.ctx.beginPath(); this.ctx.arc(end.x, end.y, 4, 0, 7); this.ctx.fill();
      }
    }
    _previewPath(pv) {
      const pts = pv.tiles.map(([q, r]) => toPx(q, r));
      this._line(pts, 'rgba(255,255,255,.9)', 6);
      this._line(pts, pv.ok === false ? '#b3261e' : '#e67e22', 3);
      if (pv.label && pts.length) {
        const ctx = this.ctx, e = pts[pts.length - 1];
        ctx.font = '700 12px sans-serif'; ctx.textAlign = 'center';
        const w = ctx.measureText(pv.label).width + 10;
        ctx.fillStyle = '#3b2a18'; ctx.fillRect(e.x - w / 2, e.y - 34, w, 18);
        ctx.fillStyle = '#fbefd0'; ctx.fillText(pv.label, e.x, e.y - 21);
      }
    }
    _armies(s) {
      const ctx = this.ctx;
      const stack = {};
      const list = this.armies.map((a) => ({ a, p: this.armyPos(a) }));
      list.sort((u, v) => u.p.y - v.p.y);
      for (const { a, p } of list) {
        // 같은 타일에 주둔한 부대는 옆으로 비켜 그림
        const k = a.state === 'moving' ? null : key(p.q, p.r);
        const n = k ? (stack[k] = (stack[k] || 0) + 1) - 1 : 0;
        const x = p.x + n * 14, y = p.y - 4 - n * 4;
        const R = 13;
        const col = a.mine ? '#2f6db0' : '#b3261e';
        ctx.beginPath(); ctx.arc(x, y, R + 2, 0, 7); ctx.fillStyle = 'rgba(0,0,0,.25)'; ctx.fill();
        ctx.beginPath(); ctx.arc(x, y, R, 0, 7); ctx.fillStyle = '#f4ecd8'; ctx.fill();
        ctx.lineWidth = 3; ctx.strokeStyle = col; ctx.stroke();
        if (this.selArmy === a.id) { ctx.beginPath(); ctx.arc(x, y, R + 5, 0, 7); ctx.lineWidth = 2; ctx.strokeStyle = '#e6b83a'; ctx.stroke(); }
        const img = unitImage(a.main, a.main_category, () => this.draw());
        if (img) ctx.drawImage(img, x - R, y - R - 2, R * 2, R * 2);
        if (s >= 0.35) {
          const label = a.total >= 1000 ? (a.total / 1000).toFixed(1) + 'k' : String(a.total);
          ctx.font = '700 10px sans-serif'; ctx.textAlign = 'center';
          const w = ctx.measureText(label).width + 6;
          ctx.fillStyle = col; ctx.fillRect(x - w / 2, y + R - 3, w, 12);
          ctx.fillStyle = '#fff'; ctx.fillText(label, x, y + R + 6);
          if (a.task) {
            ctx.fillStyle = '#e6b83a'; ctx.beginPath(); ctx.arc(x + R, y - R + 2, 5, 0, 7); ctx.fill();
            ctx.fillStyle = '#3b2a18'; ctx.font = '700 8px sans-serif'; ctx.fillText(a.task === 'bridge' ? '다' : '채', x + R, y - R + 5);
          }
        }
      }
    }

    // ───────────── 길찾기 (서버와 같은 규칙) ─────────────
    passable(t, ownVid) {
      if (!t) return false;
      if (t.v && t.v.id !== ownVid) return false;
      const d = this.map.terrains[t.t];
      return !!d && (!!d.passable || t.f === 'bridge');
    }
    cost(t) {
      if (t.f === 'bridge') return Math.max(0.1, this.map.bridge_move_cost);
      return Math.max(0.1, (this.map.terrains[t.t] || {}).move_cost || 1);
    }
    findPath(q0, r0, q1, r1, ownVid) {
      const start = key(q0, r0), goal = key(q1, r1);
      if (!this.tiles.has(start) || !this.passable(this.tiles.get(goal), ownVid)) return null;
      if (start === goal) return [[q0, r0]];
      let minCost = Math.max(0.1, this.map.bridge_move_cost);
      for (const c in this.map.terrains) if (this.map.terrains[c].passable) minCost = Math.min(minCost, Math.max(0.1, this.map.terrains[c].move_cost));
      const g = new Map([[start, 0]]), came = new Map(), closed = new Set();
      const open = [[0, start]];
      while (open.length) {
        // 작은 맵이라 단순 정렬 큐로 충분
        let bi = 0;
        for (let i = 1; i < open.length; i++) if (open[i][0] < open[bi][0]) bi = i;
        const [, k] = open.splice(bi, 1)[0];
        if (closed.has(k)) continue;
        if (k === goal) break;
        closed.add(k);
        const t = this.tiles.get(k);
        for (const [dq, dr] of DIRS) {
          const nk = key(t.q + dq, t.r + dr), n = this.tiles.get(nk);
          if (!n || closed.has(nk) || !this.passable(n, ownVid)) continue;
          const ng = g.get(k) + this.cost(n);
          if (ng < (g.has(nk) ? g.get(nk) : Infinity) - 1e-9) {
            g.set(nk, ng); came.set(nk, k);
            open.push([ng + hexDist(n.q, n.r, q1, r1) * minCost, nk]);
          }
        }
      }
      if (!g.has(goal)) return null;
      const path = [];
      for (let k = goal; k; k = came.get(k)) { const t = this.tiles.get(k); path.unshift([t.q, t.r]); if (k === start) break; }
      return path;
    }
    /** 경로 소요 시간(초) */
    pathTime(tiles, secPerTile, factor) {
      let t = 0;
      for (let i = 1; i < tiles.length; i++) t += this.cost(this.tiles.get(key(tiles[i][0], tiles[i][1]))) * secPerTile * (factor || 1);
      return t;
    }
  }

  VgMap.key = key;
  VgMap.hexDist = hexDist;
  VgMap.DIRS = DIRS;
  global.VgMap = VgMap;
})(window);
