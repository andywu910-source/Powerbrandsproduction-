{{-- Start a new batch: Brand › Product line › Flavor (Andy, Nov 17: "drop boxes for brand then product
     line then flavor"). Three live dropdowns that depend on each other; each one can be typed into to
     search, tapped to see the whole list (big rows for the iPad), or used with the keyboard (arrows,
     Enter, Esc). The three choices resolve to exactly one product; the form then submits product_id
     exactly like the old single list. A product picked another way (link / full list) fills all three. --}}
@php
    $pickerItems = \App\Services\ProductPicker::forJs($readyIds);
    $sel = $selected ? collect($pickerItems)->firstWhere('id', $selected->id) : null;
    $jpEmbed = $pickerEmbed ?? false;   // true: inside another form (Weekly Schedule job), picking only fills product_id
@endphp
@if ($jpEmbed)<div id="jobPicker" class="pb-job-picker">@else
<form method="GET" action="{{ route('production.index') }}" id="jobPicker" class="pb-job-picker" autocomplete="off">@endif
    <input type="hidden" name="product_id" id="jpProduct" value="{{ $selected?->id }}">
    @if (request()->integer('schedule_job'))<input type="hidden" name="schedule_job" value="{{ request()->integer('schedule_job') }}">@endif
    <div class="pb-job-steps">
        @foreach ([['brand', '1. Brand', 'Type or tap to pick a brand', 'b'], ['line', '2. Product line', 'Pick a brand first', 'l'], ['flavor', '3. Flavor / strain', 'Pick a product line first', 'f']] as [$k, $label, $ph, $short])
            <div class="f-field pb-combo pb-job-step" data-step="{{ $k }}">
                <label for="jp-{{ $k }}">{{ $label }}</label>
                <div class="pb-combo-box">
                    <input id="jp-{{ $k }}" class="pb-input pb-combo-input" type="text" placeholder="{{ $ph }}" autocomplete="off" spellcheck="false"
                        role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="jp-{{ $k }}-list"
                        value="{{ $sel[$short] ?? '' }}" @disabled($k !== 'brand' && ! $sel)>
                    <button type="button" class="pb-combo-toggle" tabindex="-1" aria-label="Show the whole list" @disabled($k !== 'brand' && ! $sel)>▾</button>
                </div>
                <div class="pb-combo-drop pb-combo-big" id="jp-{{ $k }}-list" role="listbox" hidden></div>
            </div>
        @endforeach
    </div>
    <p class="pb-muted pb-job-hint" id="jpHint" aria-live="polite">{{ $sel ? '' : count($pickerItems).' active products. Product lines show size and type; products not sorted into a line yet are under "'.\App\Support\ProductLine::OTHER.'".' }}</p>
    <details class="pb-details pb-job-all"><summary>Or pick from the full list</summary>
        <select id="pp" class="pb-select" onchange="if (this.value) window.pbJobPicker.set(+this.value)" aria-label="Product (full list)">
            <option value="">— pick a product —</option>
            @foreach ($products as $p)
                <option value="{{ $p->id }}" @selected($selected?->id === $p->id)>{{ $p->name }}{{ $p->brand ? ' · '.$p->brand->name : '' }}{{ in_array($p->id, $readyIds) ? '' : ' (no approved recipe yet)' }}</option>
            @endforeach
        </select>
    </details>
    @unless ($jpEmbed)<noscript><button class="pb-btn pb-btn-ghost">Choose</button></noscript>@endunless
@if ($jpEmbed)</div>@else</form>@endif
<script>
(function () {
    var ITEMS = @json($pickerItems), OTHER = @json(\App\Support\ProductLine::OTHER);
    var EMBED = @json($jpEmbed), form = document.getElementById('jobPicker'), outer = EMBED ? form.closest('form') : form, pid = document.getElementById('jpProduct'), hint = document.getElementById('jpHint');
    var order = ['brand', 'line', 'flavor'], key = {brand: 'b', line: 'l', flavor: 'f'}, chosen = {brand: null, line: null, flavor: null}, C = {};
    @if ($sel) chosen = {brand: @json($sel['b']), line: @json($sel['l']), flavor: @json($sel['f'])}; @endif
    function norm(s) { return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
    function options(step) {
        var seen = {}, out = [];
        ITEMS.forEach(function (it) {
            if (step !== 'brand' && it.b !== chosen.brand) return;
            if (step === 'flavor' && it.l !== chosen.line) return;
            var v = it[key[step]];
            if (!seen[v]) { seen[v] = {value: v, count: 0, item: it}; out.push(seen[v]); }
            seen[v].count++;
        });
        return out;
    }
    function sub(step, o) {
        if (step === 'brand') return o.count + (o.count === 1 ? ' product' : ' products');
        if (step === 'line') return o.value === OTHER ? o.count + ' (listed by full product name)' : o.count + (o.count === 1 ? ' flavor' : ' flavors');
        return o.item.n + (o.item.r ? '' : ' · no approved recipe yet');
    }
    function reset(from) {
        var start = order.indexOf(from);
        for (var k = start; k < order.length; k++) {
            var s = order[k], c = C[s]; chosen[s] = null; c.input.value = '';
            var off = k > start || !chosen[order[k - 1]];
            c.input.disabled = off; c.toggle.disabled = off;
        }
        pid.value = '';
    }
    function pick(step, value) {
        var c = C[step], i = order.indexOf(step);
        c.input.value = value; c.close();
        if (chosen[step] !== value) {
            chosen[step] = value;
            if (i < 2) reset(order[i + 1]);
        }
        if (step === 'flavor') {
            var it = ITEMS.filter(function (x) { return x.b === chosen.brand && x.l === chosen.line && x.f === value; })[0];
            if (it) { pid.value = it.id; if (EMBED) { hint.textContent = 'Picked: ' + it.n; pid.dispatchEvent(new Event('change', {bubbles: true})); } else { hint.textContent = 'Opening ' + it.n + '…'; form.submit(); } }
            return;
        }
        var next = order[i + 1], n = C[next];
        n.input.disabled = false; n.toggle.disabled = false;
        n.input.placeholder = next === 'line' ? 'Type or tap to pick a product line' : 'Type or tap to pick a flavor';
        var opts = options(next);
        if (opts.length === 1) { pick(next, opts[0].value); return; }   // only one choice: take it
        n.input.focus(); n.open(true);
    }
    function Combo(step) {
        var root = form.querySelector('[data-step="' + step + '"]'), input = root.querySelector('input'), toggle = root.querySelector('.pb-combo-toggle');
        var drop = root.querySelector('.pb-combo-drop'), list = [], active = -1, self = this;
        this.input = input; this.toggle = toggle;
        this.close = function () { drop.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); active = -1; };
        this.open = function (all) {
            var q = (all || input.value === (chosen[step] || '')) ? '' : norm(input.value).trim(), words = q.split(/\s+/).filter(Boolean);
            list = options(step).filter(function (o) { var v = norm(o.value + ' ' + (step === 'flavor' ? o.item.n : '')); return words.every(function (w) { return v.indexOf(w) >= 0; }); });
            drop.innerHTML = ''; active = -1;
            if (!list.length) { var m = document.createElement('div'); m.className = 'pb-combo-msg'; m.textContent = 'Nothing matches "' + input.value + '"'; drop.appendChild(m); }
            list.forEach(function (o, k) {
                var el = document.createElement('div'); el.className = 'pb-combo-opt' + (o.value === chosen[step] ? ' cur' : '') + (o.value === OTHER ? ' other' : '');
                el.setAttribute('role', 'option'); el.id = 'jp-' + step + '-o' + k; el.setAttribute('aria-selected', o.value === chosen[step] ? 'true' : 'false');
                var a = document.createElement('b'); a.textContent = o.value; var b = document.createElement('small'); b.className = 'pb-muted'; b.textContent = sub(step, o);
                el.append(a, b);
                el.addEventListener('pointerdown', function (e) { e.preventDefault(); });   // keep focus so the tap lands
                el.addEventListener('mousedown', function (e) { e.preventDefault(); });
                el.addEventListener('click', function () { pick(step, o.value); });
                drop.appendChild(el);
            });
            drop.hidden = false; input.setAttribute('aria-expanded', 'true');
            var cur = drop.querySelector('.cur'); if (cur) cur.scrollIntoView({block: 'nearest'});
        };
        function move(d) {
            var os = drop.querySelectorAll('.pb-combo-opt'); if (!os.length) return;
            active = (active + d + os.length) % os.length;
            os.forEach(function (o, k) { o.classList.toggle('on', k === active); });
            input.setAttribute('aria-activedescendant', os[active].id); os[active].scrollIntoView({block: 'nearest'});
        }
        input.addEventListener('input', function () {
            if (chosen[step] !== null && input.value !== chosen[step]) { var i = order.indexOf(step); chosen[step] = null; if (i < 2) reset(order[i + 1]); pid.value = ''; }
            self.open(false);
        });
        input.addEventListener('focus', function () { self.open(true); });
        input.addEventListener('click', function () { if (drop.hidden) self.open(true); });
        input.addEventListener('blur', function () { setTimeout(function () { self.close(); if (chosen[step] !== null && input.value !== chosen[step]) input.value = chosen[step]; }, 150); });
        toggle.addEventListener('click', function () { if (drop.hidden) { input.focus(); self.open(true); } else self.close(); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); if (drop.hidden) self.open(true); else move(1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
            else if (e.key === 'Escape') { if (!drop.hidden) { e.preventDefault(); self.close(); } }
            else if (e.key === 'Enter') {
                e.preventDefault();
                if (active >= 0 && list[active]) pick(step, list[active].value);
                else if (list.length === 1) pick(step, list[0].value);
            }
            else if (e.key === 'Tab') self.close();
        });
    }
    order.forEach(function (s) { C[s] = new Combo(s); });
    if (outer) outer.addEventListener('submit', function (e) { if (!pid.value) { e.preventDefault(); C[chosen.brand ? (chosen.line ? 'flavor' : 'line') : 'brand'].input.focus(); } });
    window.pbJobPicker = {set: function (id) {
        if (!EMBED) { pid.value = id; form.submit(); return; }
        var it = ITEMS.filter(function (x) { return x.id === id; })[0]; if (!it) return;
        chosen = {brand: it.b, line: it.l, flavor: it.f};
        order.forEach(function (s) { C[s].input.value = chosen[s]; C[s].input.disabled = false; C[s].toggle.disabled = false; });
        pid.value = it.id; hint.textContent = 'Picked: ' + it.n;
    }};   // a product picked another way: the page reloads with all three filled
})();
</script>
