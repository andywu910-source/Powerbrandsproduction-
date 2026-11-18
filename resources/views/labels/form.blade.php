@extends('layouts.app')
@section('title', 'Label')
@section('page_title', 'Label'.($batch ? ' · '.$batch->batch_number : ''))
@section('parent_title', 'Labels')
@section('parent_url', route('labels.index'))
@php
    $ro = auth()->user()->isViewer(); $src = $batch ? ['batch_id' => $batch->id] : ['product' => $product?->id];
    $isCase = $f['label_type'] === 'case'; $cb = \App\Services\LabelService::caseBoxes($f);
    $caseCopies = $cb['full'] ?? 1; $perTxt = trim(($f['per_case'] ?? '').' '.$f['per_case_label']);
@endphp
@section('content')
<div class="pb-label-layout">
<section class="card pb-form-card">
    <div class="card-head"><h3>Label text</h3>
        @if ($from)<span class="pill blue">Copied from print {{ $from->public_id }}</span>@endif</div>
    @include('labels.partials.picker', ['current' => $batch?->batch_number])
    <form method="POST" action="{{ route('labels.print', $src) }}" target="_blank" id="labelForm" class="pb-form">@csrf
        <fieldset class="pb-label-type" id="labelType"><legend>Label type</legend>
            @foreach (\App\Services\LabelService::TYPES as $k => $l)
                <label class="pb-seg {{ $f['label_type'] === $k ? 'on' : '' }}"><input type="radio" name="f[label_type]" value="{{ $k }}" @checked($f['label_type'] === $k)>
                    <b>{{ $l }}</b><small>{{ $k === 'master' ? 'Total count, cases and count per case' : 'One small box: product, batch # and its count' }}</small></label>
            @endforeach
        </fieldset>
        <div class="pb-label-total">
            <div class="f-field"><label for="f_total">Total count <span class="pb-muted" data-only="case" @if (! $isCase) hidden @endif>(optional for Case box: sets how many labels)</span></label>
                <input id="f_total" class="pb-input pb-input-big" type="number" min="0" step="1" inputmode="numeric" name="f[total]" value="{{ $f['total'] }}" placeholder="e.g. 320" @if (! $ro) autofocus @endif>
                @if ($batch && ($batch->units_packed || $batch->target_units))<small class="pb-muted">Batch record: {{ $batch->units_packed ? $batch->units_packed.' units packed' : $batch->target_units.' units planned' }}.</small>@endif
            </div>
            <div class="pb-case-math" id="caseMath" aria-live="polite"></div>
        </div>
        <h4 class="pb-subhead">Label lines <span class="pb-muted">filled in from the batch · change anything</span></h4>
        <div class="f-field"><label for="f_title">Product line: brand, strain, size and type</label><input id="f_title" class="pb-input" name="f[title]" value="{{ $f['title'] }}" maxlength="120">
            <small class="pb-muted">e.g. "Bodega Boyz Watermelon Zkittlez 0.7g Shortiez Preroll". A long line wraps onto 2 lines before the text gets smaller.</small></div>
        @if (trim((string) $f['type_text']) !== '')
            <div class="f-field"><label for="f_type">Product type line <span class="pb-muted">(from an older print)</span></label><input id="f_type" class="pb-input" name="f[type_text]" value="{{ $f['type_text'] }}" maxlength="120"></div>
        @else
            <input type="hidden" name="f[type_text]" value="">
        @endif
        <div class="pb-label-pair">
            <div class="f-field"><label for="f_bl">Batch line wording</label><input id="f_bl" class="pb-input" name="f[batch_label]" value="{{ $f['batch_label'] }}" maxlength="40"></div>
            <div class="f-field"><label for="f_bn">Batch number</label><input id="f_bn" class="pb-input pb-bn" name="f[batch_number]" value="{{ $f['batch_number'] }}" maxlength="40"></div>
        </div>
        <div class="pb-label-pair" data-only="master" @if ($isCase) hidden @endif>
            <div class="f-field"><label for="f_cl">After the total</label><input id="f_cl" class="pb-input" name="f[count_label]" value="{{ $f['count_label'] }}" maxlength="40"></div>
            <div class="f-field"><label for="f_cases">Cases</label><input id="f_cases" class="pb-input" type="number" min="0" step="1" inputmode="numeric" name="f[cases]" value="{{ $f['cases'] }}"></div>
        </div>
        <div class="pb-label-pair">
            <div class="f-field" data-only="master" @if ($isCase) hidden @endif><label for="f_casel">After the cases</label><input id="f_casel" class="pb-input" name="f[case_label]" value="{{ $f['case_label'] }}" maxlength="40"></div>
            <div class="f-field"><label for="f_per">Count per case</label><input id="f_per" class="pb-input" type="number" min="1" step="1" inputmode="numeric" name="f[per_case]" value="{{ $f['per_case'] }}"></div>
        </div>
        <div class="f-field"><label for="f_perl">After the count per case</label><input id="f_perl" class="pb-input" name="f[per_case_label]" value="{{ $f['per_case_label'] }}" maxlength="40"></div>
        <small class="pb-muted" id="caseHint">Cases are worked out from the total and the count per case{{ $product?->case_pack_qty ? ' ('.$product->case_pack_qty.' per case for this product, from Setup › Products)' : ' (set "Count per case" on the product in Setup)' }}.</small>
        @php $bcBatch = strtoupper(preg_replace('/\s+/', '', (string) ($batch?->barcode_number ?? ''))); $bcReq = ! empty($f['barcode_required']); @endphp
        <div class="f-field" id="barcodeRow"><label for="f_bv">Barcode number (Code 128) @if ($bcReq)<span class="pill warn">{{ $product?->brand?->name ?? 'This brand' }} labels need one</span>@endif</label>
            <input id="f_bv" class="pb-input pb-bn" name="f[barcode_value]" value="{{ $f['barcode_value'] }}" maxlength="48" autocomplete="off" spellcheck="false" placeholder="{{ $bcReq ? 'Required: type or scan the barcode number' : 'Optional: type or scan a barcode number' }}">
            <small class="pb-muted" id="bcHint">@if ($bcBatch !== '')From the Batch Log for batch {{ $batch->batch_number }}. Printed on every label (Master and Case box). Change it here for this print only, or <a href="{{ route('batch-log.show', $batch) }}#barcode">in the Batch Log</a>.@elseif ($batch)Batch {{ $batch->batch_number }} has no barcode number in the Batch Log. Anything typed here is printed on this print's labels (Master and Case box). <a href="{{ route('batch-log.show', $batch) }}#barcode">Save it to the batch</a>.@else No batch picked: anything typed here is printed on this print's labels (Master and Case box).@endif</small>
            @if ($missingBarcode)
                <div class="pb-alert pb-alert-err" id="barcodeMissing">{{ $product?->brand?->name ?? 'This brand' }} labels need a barcode, and
                    @if ($batch) batch <b class="pb-bn">{{ $batch->batch_number }}</b> has no barcode number yet. Type it above, or <a href="{{ route('batch-log.show', $batch) }}#barcode">add it in the Batch Log</a>.
                    @else no barcode number is typed yet. Type it above. @endif</div>
            @endif
        </div>
        <div class="pb-label-print" data-only="master" @if ($isCase) hidden @endif>
            <div class="f-field"><label for="copies">How many labels</label><input id="copies" class="pb-input" type="number" min="1" max="{{ config('pbp_labels.max_copies') }}" step="1" inputmode="numeric" name="copies" value="{{ old('copies', $f['cases'] ?: 1) }}" @disabled($isCase)></div>
            @unless ($ro)<button class="btn-save js-print" type="submit" name="which" value="full" id="printBtn" @disabled($missingBarcode || $errors_now)>Print (PDF)</button>@endunless
        </div>
        <div class="pb-casebox-print" data-only="case" @if (! $isCase) hidden @endif>
            <div class="pb-casebox-row" id="cbFullRow" @if ($cb['full'] === 0) hidden @endif>
                <div class="pb-casebox-what"><b>Case box labels</b><span id="cbFullText">{{ $perTxt }}</span></div>
                <div class="f-field"><label for="copiesCase">How many</label><input id="copiesCase" class="pb-input" type="number" min="1" max="{{ config('pbp_labels.max_copies') }}" step="1" inputmode="numeric" name="copies" value="{{ $caseCopies ?: 1 }}" @disabled(! $isCase || $cb['full'] === 0)></div>
                @unless ($ro)<button class="btn-save js-print" type="submit" name="which" value="full" id="printCaseBtn" @disabled($missingBarcode || $errors_now)>Print (PDF)</button>@endunless
            </div>
            <div class="pb-casebox-row partial" id="cbPartialRow" @if (! $cb['remainder']) hidden @endif>
                <div class="pb-casebox-what"><b>Partial case label</b><span id="cbPartialText">{{ trim($cb['remainder'].' '.$f['per_case_label']) }}</span></div>
                <div class="f-field"><label for="copiesPartial">How many</label><input id="copiesPartial" class="pb-input" type="number" min="1" max="{{ config('pbp_labels.max_copies') }}" step="1" inputmode="numeric" name="partial_copies" value="1" @disabled(! $isCase || ! $cb['remainder'])></div>
                @unless ($ro)<button class="btn-save js-print" type="submit" name="which" value="partial" id="printPartialBtn" @disabled($missingBarcode || $errors_now)>Print partial (PDF)</button>@endunless
            </div>
            <small class="pb-muted" id="cbHint">How many = full case boxes (total ÷ count per case, rounded down). Any leftover gets one partial case label. Change the numbers if you need more or fewer.</small>
        </div>
        <input type="hidden" name="layout" id="layoutInput" value="{{ $layoutEdited ? json_encode($layoutJson) : '' }}">
        <p class="pb-muted">Opens a PDF with one 3 × 2 in label per page. Print it at "actual size" on the thermal printer. Every print is logged.</p>
    </form>
</section>
<section class="card pb-label-preview-card">
    <div class="card-head"><h3>Preview</h3><span class="pb-spacer"></span><span class="pb-muted" id="pvSize"></span></div>
    <div class="pb-muted pb-pv-caption" id="pvCaption">{{ $isCase ? 'Case box label' : 'Master box label' }}</div>
    <div class="pb-label-preview pb-label-editable" id="preview" title="Drag a line or the barcode to move it">{!! $svg !!}</div>
    <div class="pb-ed-bar">
        <span class="pill {{ $layoutEdited ? 'amber' : 'gray' }}" id="edState">{{ $layoutSource === 'template' ? 'Product template' : ($layoutSource === 'print' ? 'Edited layout (from that print)' : 'Standard layout') }}</span>
        <span class="pb-spacer"></span>
        @unless ($ro)
            <button type="button" class="btn-ghost" id="edToggle" aria-expanded="false">Edit on label</button>
            <button type="button" class="btn-ghost" id="edReset" @if (! $layoutEdited) disabled @endif>Reset to default</button>
        @endunless
    </div>
    @unless ($ro)
    <div class="pb-ed" id="labelEditor" hidden>
        <p class="pb-muted">Changes here apply to this print job only. Drag lines or the barcode on the preview to move them. Press Enter in a line to break it onto a new line. The preview is drawn from the same layout as the PDF.</p>
        <div class="pb-ed-lines" id="edLines"></div>
        <div class="pb-ed-add"><button type="button" class="btn-ghost" id="edAddLine">+ Add line</button><button type="button" class="btn-ghost" id="edAddSpace">+ Add blank space at the bottom of the text</button></div>
        <div class="pb-ed-bc" id="edBarcode" @if (! $layoutJson['barcode']) hidden @endif>
            <b>Barcode</b>
            <label>Width <input type="number" class="pb-input pb-input-sm" id="edBcW" step="1"> pt</label>
            <label>Height <input type="number" class="pb-input pb-input-sm" id="edBcH" step="1" min="15" max="80"> pt</label>
            <small class="pb-muted" id="edBcMin"></small>
        </div>
        @if ($canTemplate)
            <div class="pb-ed-tpl"><button type="button" class="btn-ghost" id="edSaveTpl">Save as template for this product</button>
                <small class="pb-muted">Admin / supervisor. Future {{ $isCase ? 'Case box' : 'Master box' }} labels for {{ ($product ?? $batch?->product)?->name }} start from this layout (batch #, counts and the product line still fill in from the batch).</small>
                <div class="pb-muted" id="edTplMsg" aria-live="polite"></div></div>
        @endif
    </div>
    @endunless
    <div id="pvPartialWrap" @if (! $partialSvg) hidden @endif><div class="pb-muted pb-pv-caption">Partial case label</div><div class="pb-label-preview" id="pvPartial">{!! $partialSvg !!}</div></div>
    <div id="pvErrors" class="pb-alert pb-alert-err" @if (! $errors_now) hidden @endif>@foreach ($errors_now as $e)<div>{{ $e }}</div>@endforeach</div>
    <div id="pvWarnings" class="pb-alert pb-alert-warn" @if (! $warnings) hidden @endif>@foreach ($warnings as $w)<div>{{ $w }}</div>@endforeach</div>
    @if ($batch)<p class="pb-muted">From batch <a href="{{ route('batch-log.show', $batch) }}" class="pb-bn">{{ $batch->batch_number }}</a> · <span class="{{ $batch->logStatusClass() }}">{{ $batch->logStatusLabel() }}</span></p>@endif
</section>
</div>
@push('scripts')
<script>
(function () {
    var form = document.getElementById('labelForm'), t, seq = 0;
    var total = document.getElementById('f_total'), per = document.getElementById('f_per'), cases = document.getElementById('f_cases');
    var copies = document.getElementById('copies'), mathEl = document.getElementById('caseMath');
    var copiesCase = document.getElementById('copiesCase'), copiesPartial = document.getElementById('copiesPartial');
    var casesTouched = false, copiesTouched = false, caseTouched = false;
    cases.addEventListener('input', function () { casesTouched = true; });
    copies.addEventListener('input', function () { copiesTouched = true; });
    copiesCase.addEventListener('input', function () { caseTouched = true; });
    function type() { var c = form.querySelector('input[name="f[label_type]"]:checked'); return c ? c.value : 'master'; }
    function showType() {
        var t = type();
        form.querySelectorAll('.pb-seg').forEach(function (l) { l.classList.toggle('on', l.querySelector('input').checked); });
        document.querySelectorAll('[data-only]').forEach(function (el) { el.hidden = el.getAttribute('data-only') !== t; });
        document.getElementById('pvCaption').textContent = t === 'case' ? 'Case box label' : 'Master box label';
        if (t !== 'case') document.getElementById('pvPartialWrap').hidden = true;
        copies.disabled = t !== 'master';
    }
    function caseRows() {
        var a = parseInt(total.value, 10), b = parseInt(per.value, 10), lbl = document.getElementById('f_perl').value.trim();
        var full = null, rem = 0;
        if (a >= 0 && b > 0) { full = Math.floor(a / b); rem = a % b; }
        var isCase = type() === 'case';
        document.getElementById('cbFullText').textContent = ((b > 0 ? b : '') + ' ' + lbl).trim();
        document.getElementById('cbPartialText').textContent = (rem + ' ' + lbl).trim();
        document.getElementById('cbFullRow').hidden = full === 0;
        document.getElementById('cbPartialRow').hidden = !rem;
        copiesCase.disabled = !isCase || full === 0;
        copiesPartial.disabled = !isCase || !rem;
        if (full !== null && full > 0 && !caseTouched) copiesCase.value = full;
        if (isCase) {
            mathEl.className = 'pb-case-math ' + (rem ? 'warn' : (full ? 'ok' : ''));
            mathEl.textContent = full === null ? '' : (full + ' full case box' + (full === 1 ? '' : 'es') + (rem ? ' + 1 partial of ' + rem : ''));
        }
    }
    function autoCases() {
        caseRows();
        if (type() === 'case') return;
        var a = parseInt(total.value, 10), b = parseInt(per.value, 10), txt = '';
        if (a > 0 && b > 0) {
            var full = Math.floor(a / b), rem = a % b, n = full + (rem ? 1 : 0);
            if (!casesTouched) cases.value = n;
            if (!copiesTouched) copies.value = n;
            txt = rem ? (full ? full + ' full case' + (full === 1 ? '' : 's') + ' + 1 case of ' + rem : '1 case of ' + rem + ' (less than a full case)') : n + ' full case' + (n === 1 ? '' : 's') + ' of ' + b;
            mathEl.className = 'pb-case-math ' + (rem ? 'warn' : 'ok');
        } else { mathEl.className = 'pb-case-math'; }
        mathEl.textContent = txt;
    }
    total.addEventListener('input', autoCases); per.addEventListener('input', function () { casesTouched = false; caseTouched = false; autoCases(); });
    document.getElementById('f_perl').addEventListener('input', caseRows);
    document.getElementById('labelType').addEventListener('change', function () { showType(); autoCases(); });
    showType(); autoCases();
    function list(el, items) { el.innerHTML = ''; (items || []).forEach(function (m) { var d = document.createElement('div'); d.textContent = m; el.appendChild(d); }); el.hidden = !(items && items.length); }
    function refresh() {
        var fd = new FormData(form), my = ++seq, sentEdit = editSeq;
        if (!active && useTpl) fd.append('use_template', '1');
        fetch('{{ route('labels.preview', $src) }}', {method: 'POST', body: fd, headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content}})
            .then(function (r) { return r.json(); }).then(function (j) {
                if (my !== seq) return;
                document.getElementById('preview').innerHTML = j.svg;
                if (j.layout && sentEdit === editSeq) {   // an edit made while this request was out wins; the next preview brings it
                    var n = L.lines.length; L = j.layout;
                    if (!active && j.edited) { active = true; layoutInput.value = JSON.stringify({v: 1, lines: L.lines, barcode: L.barcode}); }
                    else if (active) layoutInput.value = JSON.stringify({v: 1, lines: L.lines, barcode: L.barcode});
                    var foc = rowsEl && rowsEl.contains(document.activeElement);
                    if (ed && !ed.hidden && (!foc || n !== L.lines.length)) rows();
                }
                var pw = document.getElementById('pvPartialWrap');
                if (j.partial_svg) { document.getElementById('pvPartial').innerHTML = j.partial_svg; pw.hidden = false; } else { pw.hidden = true; }
                document.getElementById('pvSize').textContent = 'Text ' + j.size + ' pt';
                var bm = document.getElementById('barcodeMissing'); if (bm) bm.hidden = !j.missing_barcode;
                list(document.getElementById('pvErrors'), j.errors); list(document.getElementById('pvWarnings'), j.warnings);
                form.querySelectorAll('.js-print').forEach(function (b) { b.disabled = j.errors && j.errors.length > 0; });
            }).catch(function () {});
    }
    // ---------- On-label editor ----------
    var L = @json($layoutJson), active = @json((bool) $layoutEdited), useTpl = false, templates = @json($templates), editSeq = 0;
    var layoutInput = document.getElementById('layoutInput'), ed = document.getElementById('labelEditor'), rowsEl = document.getElementById('edLines');
    var MIN = {{ (float) \App\Support\Labels\CaseLabel::cfg('text.min_size', 6) }}, MAX = {{ (float) \App\Support\Labels\CaseLabel::cfg('editor.max_size', 24) }}, LEAD = {{ (float) \App\Support\Labels\CaseLabel::cfg('text.leading', 1.135) }};
    var ROLE = {product: 'Product line', type: 'Product type', batch: 'Batch #', total: 'Total count', cases: 'Cases', per_case: 'Count per case', custom: 'Your line'};
    function setState(txt, on) { var s = document.getElementById('edState'); if (!s) return; s.textContent = txt; s.className = 'pill ' + (on ? 'amber' : 'gray'); }
    function commit(now) {
        active = true; editSeq++; layoutInput.value = JSON.stringify({v: 1, lines: L.lines, barcode: L.barcode});
        var r = document.getElementById('edReset'); if (r) r.disabled = false;
        setState('Edited for this print', true);
        clearTimeout(t); t = setTimeout(refresh, now ? 10 : 250);
    }
    function lowestY() { var m = 144; L.lines.forEach(function (l) { if (l.text !== '' && l.y < m) m = l.y; }); return m; }
    function rows() {
        if (!rowsEl) return;
        rowsEl.innerHTML = '';
        L.lines.forEach(function (l, i) {
            var row = document.createElement('div'); row.className = 'pb-ed-row'; row.dataset.i = i;
            var lab = document.createElement('span'); lab.className = 'pb-ed-role'; lab.textContent = (ROLE[l.role] || 'Line') + (l.edited ? ' ✎' : '');
            var ta = document.createElement('textarea'); ta.className = 'pb-input pb-ed-text'; ta.rows = 1; ta.maxLength = 120; ta.value = l.text; ta.setAttribute('aria-label', 'Line ' + (i + 1) + ' text');
            ta.addEventListener('input', function () { var c = L.lines[i]; if (!c) return; c.text = ta.value; c.edited = true; commit(ta.value.indexOf('\n') >= 0); });
            var sz = document.createElement('input'); sz.type = 'number'; sz.className = 'pb-input pb-input-sm pb-ed-size'; sz.min = MIN; sz.max = MAX; sz.step = 0.5; sz.value = l.size; sz.title = 'Font size (pt)'; sz.setAttribute('aria-label', 'Line ' + (i + 1) + ' font size');
            sz.addEventListener('input', function () { var v = parseFloat(sz.value), c = L.lines[i]; if (c && v >= MIN && v <= MAX) { c.size = v; commit(); } });
            var sp = document.createElement('button'); sp.type = 'button'; sp.className = 'btn-ghost btn-sm'; sp.textContent = '↧ space'; sp.title = 'Add a blank line of space below this line';
            sp.addEventListener('click', function () { var c = L.lines[i]; if (!c) return; var d = c.size * LEAD; L.lines.forEach(function (o) { if (o !== c && o.y < c.y) o.y = Math.max(1, Math.round((o.y - d) * 100) / 100); }); commit(true); });
            var del = document.createElement('button'); del.type = 'button'; del.className = 'btn-ghost btn-sm'; del.textContent = 'Remove'; del.title = 'Remove this line from this label';
            del.addEventListener('click', function () { L.lines.splice(i, 1); rows(); commit(true); });
            row.append(lab, ta, sz, document.createTextNode(' pt '), sp, del); rowsEl.appendChild(row);
        });
        var bc = document.getElementById('edBarcode');
        if (bc) {
            bc.hidden = !L.barcode;
            if (L.barcode) {
                var w = document.getElementById('edBcW'), h = document.getElementById('edBcH');
                if (document.activeElement !== w) { w.value = L.barcode.w; } w.min = L.barcode_min_w;
                if (document.activeElement !== h) { h.value = L.barcode.h; }
                document.getElementById('edBcMin').textContent = 'Smallest width that still scans: ' + L.barcode_min_w + ' pt (it can move but not shrink below this).';
            }
        }
    }
    if (ed) {
        document.getElementById('edToggle').addEventListener('click', function () { ed.hidden = !ed.hidden; this.setAttribute('aria-expanded', String(!ed.hidden)); this.textContent = ed.hidden ? 'Edit on label' : 'Close editor'; rows(); });
        document.getElementById('edReset').addEventListener('click', function () { active = false; useTpl = false; layoutInput.value = ''; this.disabled = true; setState('Standard layout', false); refresh(true); });
        document.getElementById('edAddLine').addEventListener('click', function () {
            // Below the text; with a barcode, just above the bars (drag it where it should go).
            var s = 8, y = lowestY() - s * LEAD;
            if (L.barcode && y < L.barcode.y + L.barcode.h + 1) y = L.barcode.y + L.barcode.h + 1.5;
            L.lines.push({role: 'custom', text: 'New line', x: 9, y: Math.max(1 + s * 0.21, Math.round(y * 100) / 100), size: s, edited: true}); rows(); commit(true); });
        document.getElementById('edAddSpace').addEventListener('click', function () {
            // Moves every text line up by one blank line, leaving space at the bottom of the text.
            var d = 9 * LEAD; L.lines.forEach(function (o) { o.y = Math.round((o.y + d) * 100) / 100; }); commit(true); });
        var bw = document.getElementById('edBcW'), bh = document.getElementById('edBcH');
        bw.addEventListener('change', function () { var v = Math.max(L.barcode_min_w, parseFloat(bw.value) || 0); bw.value = v; L.barcode.w = v; commit(true); });
        bh.addEventListener('change', function () { var v = Math.min(80, Math.max(15, parseFloat(bh.value) || 0)); bh.value = v; L.barcode.h = v; commit(true); });
        var st = document.getElementById('edSaveTpl');
        if (st) st.addEventListener('click', function () {
            var fd = new FormData(); fd.append('layout', JSON.stringify({v: 1, lines: L.lines, barcode: L.barcode})); fd.append('label_type', type());
            fetch('{{ route('labels.template', $src) }}', {method: 'POST', body: fd, headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content}})
                .then(function (r) { return r.json().then(function (j) { return [r.ok, j]; }); })
                .then(function (a) { document.getElementById('edTplMsg').textContent = a[0] ? a[1].message : (a[1].message || 'Could not save the template.'); if (a[0]) templates[type()] = true; });
        });
        // Drag on the preview: SVG units are PDF points (y flipped), so a move here is the same move in the PDF.
        var pv = document.getElementById('preview'), drag = null;
        pv.addEventListener('pointerdown', function (e) {
            var el = e.target.closest('[data-line],[data-barcode]'); if (!el) return;
            var svg = pv.querySelector('svg'), m = svg.getScreenCTM().inverse(), p = new DOMPoint(e.clientX, e.clientY).matrixTransform(m);
            var isBc = el.hasAttribute('data-barcode'), item = isBc ? L.barcode : L.lines[parseInt(el.getAttribute('data-line'), 10)];
            if (!item) return;
            drag = {el: el, svg: svg, m: m, sx: p.x, sy: p.y, item: item, ox: item.x, oy: item.y};
            el.setPointerCapture(e.pointerId); el.classList.add('dragging'); e.preventDefault();
        });
        pv.addEventListener('pointermove', function (e) {
            if (!drag) return;
            var p = new DOMPoint(e.clientX, e.clientY).matrixTransform(drag.m), dx = p.x - drag.sx, dy = p.y - drag.sy;
            drag.el.setAttribute('transform', 'translate(' + dx + ' ' + dy + ')');
            drag.item.x = Math.round((drag.ox + dx) * 4) / 4; drag.item.y = Math.round((drag.oy - dy) * 4) / 4;
        });
        function endDrag() { if (!drag) return; drag.el.classList.remove('dragging'); drag = null; if (!ed.hidden) rows(); commit(true); }
        pv.addEventListener('pointerup', endDrag); pv.addEventListener('pointercancel', endDrag);
    }
    document.getElementById('labelType').addEventListener('change', function () {
        // A layout belongs to one label type: switching starts from that type's template or the standard layout.
        active = false; layoutInput.value = ''; useTpl = !!templates[type()];
        var r = document.getElementById('edReset'); if (r) r.disabled = !useTpl;
        setState(useTpl ? 'Product template' : 'Standard layout', useTpl);
    });
    form.addEventListener('input', function () { clearTimeout(t); t = setTimeout(refresh, 250); });
    form.addEventListener('change', function () { clearTimeout(t); t = setTimeout(refresh, 50); });
})();
</script>
@endpush
@endsection
