// 공용 카메라: 휠 줌(커서 기준), 드래그 이동, 두 손가락 핀치, +/−/맞춤.
// 드래그 직후의 click 은 무시한다. 마을(SVG)과 세계 맵에서 같이 쓴다.
// target: transform 을 적용할 요소(SVG <g>), host: 입력을 받을 요소, apply: (tx,ty,s) => void 로 대체 가능
(function (global) {
  'use strict';

  class Camera {
    constructor(host, opts) {
      this.host = host;
      this.opts = Object.assign({ min: 0.25, max: 3, dragThreshold: 6 }, opts || {});
      this.tx = 0; this.ty = 0; this.s = 1;
      this.pointers = new Map();
      this.dragged = false;
      this._pinch = null;
      this._bind();
    }

    apply() {
      if (this.opts.apply) this.opts.apply(this.tx, this.ty, this.s);
      else if (this.opts.target) this.opts.target.setAttribute('transform', `translate(${this.tx},${this.ty}) scale(${this.s})`);
      if (this.opts.onChange) this.opts.onChange(this);
    }

    /** 화면(host 기준) 좌표 → 월드 좌표 */
    toWorld(x, y) { return { x: (x - this.tx) / this.s, y: (y - this.ty) / this.s }; }

    _local(e) {
      const r = this.host.getBoundingClientRect();
      return { x: e.clientX - r.left, y: e.clientY - r.top };
    }

    /** 화면 점 (x,y) 를 고정한 채 배율 변경 */
    zoomAt(x, y, factor) {
      const ns = Math.min(this.opts.max, Math.max(this.opts.min, this.s * factor));
      const k = ns / this.s;
      this.tx = x - (x - this.tx) * k;
      this.ty = y - (y - this.ty) * k;
      this.s = ns;
      this.apply();
    }

    zoomCenter(factor) {
      const r = this.host.getBoundingClientRect();
      this.zoomAt(r.width / 2, r.height / 2, factor);
    }

    /** 월드 사각형 {x,y,w,h} 가 화면에 꽉 차도록 */
    fit(box, pad) {
      pad = pad == null ? 24 : pad;
      const r = this.host.getBoundingClientRect();
      if (!r.width || !r.height || !box.w || !box.h) return;
      const s = Math.min((r.width - pad * 2) / box.w, (r.height - pad * 2) / box.h);
      this.s = Math.min(this.opts.max, Math.max(this.opts.min, s));
      this.tx = r.width / 2 - (box.x + box.w / 2) * this.s;
      this.ty = r.height / 2 - (box.y + box.h / 2) * this.s;
      this.apply();
    }

    _bind() {
      const h = this.host;
      h.style.touchAction = 'none';
      h.addEventListener('wheel', (e) => {
        e.preventDefault();
        const p = this._local(e);
        const f = Math.exp(-(e.deltaMode === 1 ? e.deltaY * 16 : e.deltaY) * 0.0015);
        this.zoomAt(p.x, p.y, f);
      }, { passive: false });

      h.addEventListener('pointerdown', (e) => {
        if (e.button !== undefined && e.button !== 0 && e.pointerType === 'mouse') return;
        const p = this._local(e);
        this.pointers.set(e.pointerId, p);
        if (this.pointers.size === 1) {
          this.dragged = false;
          this._start = { x: p.x, y: p.y, tx: this.tx, ty: this.ty };
        } else if (this.pointers.size === 2) {
          const [a, b] = [...this.pointers.values()];
          this._pinch = { d: Math.hypot(a.x - b.x, a.y - b.y), s: this.s, cx: (a.x + b.x) / 2, cy: (a.y + b.y) / 2, tx: this.tx, ty: this.ty };
          this.dragged = true;
        }
      });

      h.addEventListener('pointermove', (e) => {
        if (!this.pointers.has(e.pointerId)) return;
        const p = this._local(e);
        this.pointers.set(e.pointerId, p);
        if (this.pointers.size === 2 && this._pinch) {
          const [a, b] = [...this.pointers.values()];
          const d = Math.hypot(a.x - b.x, a.y - b.y);
          const cx = (a.x + b.x) / 2, cy = (a.y + b.y) / 2;
          const P = this._pinch;
          const ns = Math.min(this.opts.max, Math.max(this.opts.min, P.s * d / Math.max(1, P.d)));
          // 처음 핀치 중심의 월드 좌표가 현재 핀치 중심에 오도록
          const wx = (P.cx - P.tx) / P.s, wy = (P.cy - P.ty) / P.s;
          this.s = ns; this.tx = cx - wx * ns; this.ty = cy - wy * ns;
          this.apply();
          return;
        }
        if (this.pointers.size === 1 && this._start) {
          const dx = p.x - this._start.x, dy = p.y - this._start.y;
          if (!this.dragged && Math.hypot(dx, dy) < this.opts.dragThreshold) return;
          if (!this.dragged) { this.dragged = true; try { h.setPointerCapture(e.pointerId); } catch (_) {} }
          this.tx = this._start.tx + dx; this.ty = this._start.ty + dy;
          this.apply();
        }
      });

      const end = (e) => {
        this.pointers.delete(e.pointerId);
        if (this.pointers.size < 2) this._pinch = null;
        if (this.pointers.size === 1) {
          // 핀치 후 한 손가락이 남으면 그 위치에서 다시 드래그 시작
          const p = [...this.pointers.values()][0];
          this._start = { x: p.x, y: p.y, tx: this.tx, ty: this.ty };
        }
        if (this.pointers.size === 0) this._start = null;
      };
      h.addEventListener('pointerup', end);
      h.addEventListener('pointercancel', end);

      // 드래그/핀치 직후의 클릭은 삼킨다
      h.addEventListener('click', (e) => {
        if (this.dragged) { e.stopPropagation(); e.preventDefault(); this.dragged = false; }
      }, true);
    }
  }

  global.VgCamera = Camera;
})(window);
