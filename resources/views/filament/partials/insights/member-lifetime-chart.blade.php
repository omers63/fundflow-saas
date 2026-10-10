@props(['rows' => [], 'currency' => null])

@php
    $rows = array_values($rows);
    $i18n = [
        'title' => __('Contributions & repayments — lifetime'),
        'months' => __('cycles'),
        'lifetime' => __('lifetime'),
        'contributions' => __('Contributions collected'),
        'repayments' => __('Repayments paid'),
        'disbursed' => __('Loan disbursed'),
        'earlier' => __('12 earlier'),
        'later' => __('12 later'),
        'curves' => __('Peak curves'),
        'on' => __('On'),
        'off' => __('Off'),
        'maxContribution' => __('Max contribution'),
        'maxRepayment' => __('Max repayment'),
        'inView' => __('in view'),
        'thisView' => __('This view'),
        'ofExpected' => __('of expected'),
        'ofDue' => __('of due'),
        'legendNote' => __('One bar group per contribution cycle (cycle start day → day before the next) · dashed lines: expected / due · the y-axis rescales to the 12 cycles in view · scroll or use the arrows.'),
        'rateNote' => __('C = contributions collected ÷ expected · R = repayments paid ÷ instalments due in that cycle (≥90% green · 50–89% amber · <50% red) · D = loan amount disbursed that cycle (red). Cards follow the 12 cycles in view.'),
        'curveHint' => __('Smooth curves through the tops of the contribution, repayment and disbursement bars'),
        'paidOn' => __('Paid on'),
        'early' => __('early'),
        'late' => __('late'),
        'expected' => __('Expected'),
        'due' => __('Due'),
        'disbursedOn' => __('Disbursed on'),
        'currency' => $currency ?? '',
    ];
    $factory = <<<'JS'
(function (rows, t) {
  var PER = 12, H = 260, TOP = 8, XH = 22;
  var COL = { c: '#534ab7', r: '#10b981', d: '#dc2626' };
  function fmt(n) { return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function compact(n) { return n >= 1e6 ? (+(n / 1e6).toFixed(2)) + 'M' : n >= 1e3 ? (+(n / 1e3).toFixed(1)) + 'K' : String(Math.round(n)); }
  function nice(max) {
    if (!(max > 0)) return { top: 1, ticks: [0, 0.25, 0.5, 0.75, 1] };
    var exp = Math.pow(10, Math.floor(Math.log10(max))), f = max / exp;
    var n = [1, 2, 2.5, 4, 5, 8, 10].find(function (x) { return f <= x; }) || 10, top = n * exp;
    return { top: top, ticks: [0, 1, 2, 3, 4].map(function (i) { return (top / 4) * i; }) };
  }
  function mono(p) {
    var n = p.length; if (n === 0) return '';
    if (n === 1) return 'M' + p[0][0] + ',' + p[0][1];
    var dx = [], m = [], tg = [], i;
    for (i = 0; i < n - 1; i++) { dx[i] = p[i + 1][0] - p[i][0]; m[i] = (p[i + 1][1] - p[i][1]) / dx[i]; }
    tg[0] = m[0]; tg[n - 1] = m[n - 2];
    for (i = 1; i < n - 1; i++) { tg[i] = m[i - 1] * m[i] <= 0 ? 0 : (m[i - 1] + m[i]) / 2; }
    for (i = 0; i < n - 1; i++) {
      if (m[i] === 0) { tg[i] = 0; tg[i + 1] = 0; continue; }
      var a = tg[i] / m[i], b = tg[i + 1] / m[i], s = a * a + b * b;
      if (s > 9) { var k = 3 / Math.sqrt(s); tg[i] = k * a * m[i]; tg[i + 1] = k * b * m[i]; }
    }
    var d = 'M' + p[0][0] + ',' + p[0][1];
    for (i = 0; i < n - 1; i++) {
      var h = dx[i] / 3;
      d += ' C' + (p[i][0] + h) + ',' + (p[i][1] + tg[i] * h) + ' ' + (p[i + 1][0] - h) + ',' + (p[i + 1][1] - tg[i + 1] * h) + ' ' + p[i + 1][0] + ',' + p[i + 1][1];
    }
    return d;
  }
  function peak(list, k) {
    var best = null;
    list.forEach(function (r) { if (r[k] > (best ? best.v : 0)) best = { v: r[k], label: r.label }; });
    return best;
  }
  function day(iso) { var p = String(iso).split('-'); var m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']; return p[2] + ' ' + m[+p[1] - 1] + ' ' + p[0]; }
  // Items are ISO dates or { on, timing }; a payment made outside its cycle window is marked (early) / (late).
  function dates(list, max) {
    max = max || 4;
    if (!list || !list.length) return '';
    return list.slice(0, max).map(function (x) {
      var on = typeof x === 'object' && x !== null ? x.on : x;
      var tm = typeof x === 'object' && x !== null && x.timing ? ' (' + (x.timing === 'early' ? t.early : t.late) + ')' : '';
      return day(on) + tm;
    }).join(', ') + (list.length > max ? ' +' + (list.length - max) + ' more' : '');
  }
  function pct(a, b) { return b > 0 ? Math.round((a / b) * 100) + '%' : '—'; }
  return {
    rows: rows, t: t, start: Math.max(0, rows.length - PER), viewW: 0, H: H, sel: null, scrollX: 0,
    showCurve: (function () { try { return localStorage.getItem('ff-member-chart-curve') !== '0'; } catch (e) { return true; } })(),
    init: function () {
      var self = this, el = this.$refs.scroller;
      var measure = function () {
        self.viewW = el.clientWidth;
        self.$nextTick(function () { el.scrollLeft = el.scrollWidth; self.start = Math.max(0, self.rows.length - PER); });
      };
      measure();
      if (window.ResizeObserver) { new ResizeObserver(function () { self.viewW = el.clientWidth; }).observe(el); }
    },
    get monthW() { return this.viewW > 0 ? this.viewW / PER : 0; },
    get innerW() { return Math.max(this.rows.length * this.monthW, this.viewW); },
    get win() { return this.rows.slice(this.start, this.start + PER); },
    get scale() {
      var vals = [0];
      this.win.forEach(function (r) { vals.push(r.contributionsPosted, r.repaymentsPaid, r.contributionsExpected, r.repaymentsDue, r.disbursed || 0); });
      return nice(Math.max.apply(null, vals));
    },
    get plotH() { return H - TOP - XH; },
    tickTop: function (v) { return TOP + this.plotH - (v / this.scale.top) * this.plotH; },
    compact: compact, fmt: fmt,
    onScroll: function () {
      if (this.monthW <= 0) return;
      var el = this.$refs.scroller;
      this.scrollX = el.scrollLeft;
      this.start = Math.min(Math.max(0, this.rows.length - PER), Math.max(0, Math.round(el.scrollLeft / this.monthW)));
    },
    goTo: function (i) {
      var c = Math.min(Math.max(0, this.rows.length - PER), Math.max(0, i));
      this.$refs.scroller.scrollTo({ left: c * this.monthW, behavior: 'smooth' });
    },
    // Touch screens have no hover, so SVG <title> tooltips never show: tapping a cycle column opens this pop-up instead.
    pick: function (e) {
      var n = e.target && e.target.closest ? e.target.closest('[data-i]') : null;
      if (!n) { this.sel = null; return; }
      var i = +n.getAttribute('data-i');
      this.sel = this.sel === i ? null : i;
    },
    get tipRow() { return this.sel === null ? null : this.rows[this.sel] || null; },
    get tipStyle() {
      var w = 210, x = (this.sel + 0.5) * this.monthW - this.scrollX - w / 2;
      x = Math.max(0, Math.min(x, Math.max(0, this.viewW - w)));
      // An object (not a string): Alpine merges it per property, so it never wipes the inline display:none that
      // x-show sets — a string replaced the whole style attribute and left an empty box visible after scrolling.
      return { left: x + 'px', top: '4px', width: w + 'px' };
    },
    tipHtml: function () {
      var r = this.tipRow; if (!r) return '';
      var esc = function (v) { return String(v).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
      var line = function (color, label, amount, list, verb) {
        var dl = dates(list);
        return '<div class="mt-1 flex items-start gap-1.5"><span class="mt-1 h-2 w-2 shrink-0 rounded-sm" style="background:' + color + '"></span><div><div>' + esc(label) + ': <span class="font-mono font-semibold">' + fmt(amount) + '</span></div>' +
          (amount > 0 && dl ? '<div class="text-[10px] text-gray-500">' + esc(verb) + ' ' + esc(dl) + '</div>' : '') + '</div></div>';
      };
      return '<div class="font-semibold">' + esc(r.label) + '</div>' +
        line(COL.c, t.contributions, r.contributionsPosted, r.contributionsPostedOn || [], t.paidOn) +
        line(COL.r, t.repayments, r.repaymentsPaid, (r.repaymentsPaidOn || []), t.paidOn) +
        line(COL.d, t.disbursed, r.disbursed || 0, (r.disbursedOn || []).map(function (x) { return x.on; }), t.disbursedOn) +
        '<div class="mt-1 border-t border-gray-200 pt-1 text-[10px] text-gray-500">' + esc(t.expected) + ' ' + fmt(r.contributionsExpected) + ' · ' + esc(t.due) + ' ' + fmt(r.repaymentsDue) + '</div>';
    },
    toggleCurve: function () {
      this.showCurve = !this.showCurve;
      try { localStorage.setItem('ff-member-chart-curve', this.showCurve ? '1' : '0'); } catch (e) {}
    },
    get lifeC() { return this.rows.reduce(function (a, r) { return a + r.contributionsPosted; }, 0); },
    get lifeR() { return this.rows.reduce(function (a, r) { return a + r.repaymentsPaid; }, 0); },
    get maxC() { return peak(this.rows, 'contributionsPosted'); },
    get maxR() { return peak(this.rows, 'repaymentsPaid'); },
    get viewC() { return peak(this.win, 'contributionsPosted'); },
    get viewR() { return peak(this.win, 'repaymentsPaid'); },
    get viewSummary() {
      var s = function (k) { return this.win.reduce(function (a, r) { return a + r[k]; }, 0); }.bind(this);
      return t.thisView + ': ' + fmt(s('contributionsPosted')) + ' (' + pct(s('contributionsPosted'), s('contributionsExpected')) + ' ' + t.ofExpected + ') · ' +
        fmt(s('repaymentsPaid')) + ' (' + pct(s('repaymentsPaid'), s('repaymentsDue')) + ' ' + t.ofDue + ')';
    },
    rate: function (v) { return v === null || v === undefined ? 'bg-gray-100 text-gray-500' : v >= 90 ? 'bg-emerald-100 text-emerald-800' : v >= 50 ? 'bg-amber-100 text-amber-800' : 'bg-rose-100 text-rose-800'; },
    svg: function () {
      var mw = this.monthW; if (mw <= 0) return '';
      var sc = this.scale, self = this, W = this.innerW, plotH = this.plotH, out = [];
      var y = function (v) { return TOP + plotH - (Math.min(v, sc.top) / sc.top) * plotH; };
      out.push('<svg xmlns="http://www.w3.org/2000/svg" width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" role="img">');
      sc.ticks.forEach(function (tk) { out.push('<line x1="0" x2="' + W + '" y1="' + y(tk) + '" y2="' + y(tk) + '" stroke="currentColor" stroke-opacity="0.12"/>'); });
      var gw = mw * 0.72, bw = gw / 3, cs = [], rs = [], ds = [], ce = [], rd = [];
      this.rows.forEach(function (r, i) {
        var cx = (i + 0.5) * mw, x0 = cx - gw / 2;
        [['contributionsPosted', COL.c, 0, t.contributions, (r.contributionsPostedOn || [])], ['repaymentsPaid', COL.r, 1, t.repayments, (r.repaymentsPaidOn || [])], ['disbursed', COL.d, 2, t.disbursed, (r.disbursedOn || []).map(function (x) { return x.on; })]].forEach(function (b) {
          var v = r[b[0]] || 0; if (v <= 0) return;
          var yy = y(v), dl = dates(b[4]);
          out.push('<rect x="' + (x0 + b[2] * bw + 0.5) + '" y="' + yy + '" width="' + (bw - 1) + '" height="' + Math.max(0, TOP + plotH - yy) + '" rx="2" fill="' + b[1] + '"><title>' + r.label + ' · ' + b[3] + ': ' + fmt(v) + (dl ? '\n' + (b[2] === 2 ? t.disbursedOn : t.paidOn) + ' ' + dl : '') + '</title></rect>');
        });
        cs.push([cx, y(r.contributionsPosted)]); rs.push([cx, y(r.repaymentsPaid)]); ds.push([cx, y(r.disbursed || 0)]);
        ce.push([cx, y(r.contributionsExpected)]); rd.push([cx, y(r.repaymentsDue)]);
        out.push('<text x="' + cx + '" y="' + (H - 7) + '" text-anchor="middle" font-size="9" fill="currentColor" fill-opacity="0.7">' + r.label + '</text>');
      });
      // Full-height transparent column per cycle = the tap target (and a light highlight for the selected one).
      this.rows.forEach(function (r, i) {
        out.push('<rect data-i="' + i + '" x="' + (i * mw) + '" y="0" width="' + mw + '" height="' + (H - XH + 14) + '" fill="' + (self.sel === i ? 'currentColor' : 'transparent') + '" fill-opacity="0.07" style="cursor:pointer"/>');
      });
      var step = function (p) { var d = ''; p.forEach(function (q, i) { d += (i === 0 ? 'M' : 'H' + q[0] + 'V') + (i === 0 ? q[0] + ',' + q[1] : q[1]); }); return d; };
      out.push('<path d="' + step(ce) + '" fill="none" stroke="' + COL.c + '" stroke-width="1.5" stroke-dasharray="4 3"/>');
      out.push('<path d="' + step(rd) + '" fill="none" stroke="' + COL.r + '" stroke-width="1.5" stroke-dasharray="4 3"/>');
      if (this.showCurve) {
        [[cs, COL.c], [rs, COL.r], [ds, COL.d]].forEach(function (c) {
          out.push('<path d="' + mono(c[0]) + '" fill="none" stroke="' + c[1] + '" stroke-width="2.25"/>');
          c[0].forEach(function (q) { out.push('<circle cx="' + q[0] + '" cy="' + q[1] + '" r="2.4" fill="' + c[1] + '"/>'); });
        });
      }
      out.push('</svg>');
      return out.join('');
    }
  };
})
JS;
    $xData = '('.$factory.')('.json_encode($rows, JSON_UNESCAPED_UNICODE).','.json_encode($i18n, JSON_UNESCAPED_UNICODE).')';
@endphp

@if (count($rows) > 0)
    <div
        wire:ignore
        x-data="{!! e($xData) !!}"
        class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800"
        dir="ltr"
    >
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
            <div class="min-w-0">
                <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400" x-text="t.title"></h4>
                <p class="text-[11px] text-gray-500 dark:text-gray-400">
                    <span x-text="rows.length + ' ' + t.months + ' · ' + t.lifetime + ' ' + fmt(lifeC) + ' / ' + fmt(lifeR)"></span>
                </p>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold tabular-nums" style="border-color:#534ab7">
                    <span class="h-2 w-2 rounded-sm" style="background:#534ab7"></span>
                    <span x-text="t.maxContribution + ' ' + (maxC ? fmt(maxC.v) + ' · ' + maxC.label : '—')"></span>
                    <span class="font-normal text-gray-500" x-show="viewC && maxC && viewC.v !== maxC.v" x-text="'(' + t.inView + ' ' + (viewC ? fmt(viewC.v) : '') + ')'"></span>
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold tabular-nums" style="border-color:#10b981">
                    <span class="h-2 w-2 rounded-sm" style="background:#10b981"></span>
                    <span x-text="t.maxRepayment + ' ' + (maxR ? fmt(maxR.v) + ' · ' + maxR.label : '—')"></span>
                    <span class="font-normal text-gray-500" x-show="viewR && maxR && viewR.v !== maxR.v" x-text="'(' + t.inView + ' ' + (viewR ? fmt(viewR.v) : '') + ')'"></span>
                </span>
            </div>
        </div>
        <div class="px-3 py-2.5">
            <div class="mb-2 flex flex-wrap items-center gap-2 text-[11px]">
                <button type="button" class="rounded border border-gray-300 px-2 py-0.5 disabled:opacity-40 dark:border-gray-600" :disabled="start <= 0" @click="goTo(start - 12)">← <span x-text="t.earlier"></span></button>
                <span class="font-semibold" x-text="win.length ? win[0].label + ' – ' + win[win.length - 1].label : ''"></span>
                <button type="button" class="rounded border border-gray-300 px-2 py-0.5 disabled:opacity-40 dark:border-gray-600" :disabled="start >= rows.length - 12" @click="goTo(start + 12)"><span x-text="t.later"></span> →</button>
                <button type="button" role="switch" :aria-checked="showCurve" @click="toggleCurve()" class="rounded border px-2 py-0.5" :class="showCurve ? 'border-indigo-500 bg-indigo-50 text-indigo-800' : 'border-gray-300 dark:border-gray-600'" :title="t.curveHint">
                    <span x-text="t.curves + ': ' + (showCurve ? t.on : t.off)"></span>
                </button>
                <span class="text-gray-500" x-text="viewSummary"></span>
            </div>
            <div class="flex">
                <div class="relative shrink-0 text-gray-500" style="width:46px;height:260px" aria-hidden="true">
                    <template x-for="tk in scale.ticks" :key="tk">
                        <span class="absolute right-1 -translate-y-1/2 text-[9px] tabular-nums" :style="'top:' + tickTop(tk) + 'px'" x-text="compact(tk)"></span>
                    </template>
                </div>
                <div class="relative min-w-0 flex-1" @click.outside="sel = null">
                    <div x-ref="scroller" @scroll.passive="onScroll()" @click="pick($event)" class="overflow-x-auto text-gray-700 dark:text-gray-300" style="touch-action:pan-x pan-y">
                        <div :style="'width:' + innerW + 'px'" x-html="svg()"></div>
                    </div>
                    <div x-show="tipRow" x-cloak :style="tipStyle" class="pointer-events-none absolute z-10 rounded-md border border-gray-200 bg-white px-2.5 py-2 text-[11px] text-gray-800 shadow-lg dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" x-html="tipHtml()"></div>
                </div>
            </div>
            <ul class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-6 xl:grid-cols-12">
                <template x-for="r in win" :key="r.key">
                    <li class="rounded-md border border-gray-200 px-2 py-1.5 text-center text-[10.5px] dark:border-gray-700">
                        <div class="mb-1 font-semibold" x-text="r.label"></div>
                        <div class="mb-0.5 rounded px-1 py-0.5" :class="rate(r.contributionRate)" :title="fmt(r.contributionsPosted) + ' / ' + fmt(r.contributionsExpected)" x-text="'C ' + (r.contributionRate === null ? '—' : r.contributionRate + '%')"></div>
                        <div class="rounded px-1 py-0.5" :class="rate(r.repaymentRate)" :title="fmt(r.repaymentsPaid) + ' / ' + fmt(r.repaymentsDue) + ' (' + r.repaymentsCount + ')'" x-text="'R ' + (r.repaymentRate === null ? '—' : r.repaymentRate + '%')"></div>
                        <div class="mt-0.5 rounded bg-red-100 px-1 py-0.5 text-red-700" x-show="r.disbursed" :title="fmt(r.disbursed)" x-text="'D ' + compact(r.disbursed)"></div>
                    </li>
                </template>
            </ul>
            <div class="mt-2 flex flex-wrap gap-3 text-[10.5px] text-gray-500">
                <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-sm" style="background:#534ab7"></span><span x-text="t.contributions"></span></span>
                <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-sm" style="background:#10b981"></span><span x-text="t.repayments"></span></span>
                <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-sm" style="background:#dc2626"></span><span x-text="t.disbursed"></span></span>
                <span x-text="t.legendNote"></span>
            </div>
            <p class="mt-1 text-[10.5px] text-gray-500" x-text="t.rateNote"></p>
        </div>
    </div>
@endif
