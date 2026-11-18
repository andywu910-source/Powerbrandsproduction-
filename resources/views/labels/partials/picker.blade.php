{{-- Batch picker: a live dropdown. From 2 typed characters it searches batch number, product
     (strain), brand, product type and barcode number (case-insensitive) and lists matches as you type.
     Picking one opens the label form by batch id (batch numbers can contain spaces or slashes).
     Pressing "Use this batch" with typed text still searches on the server (never a 404). --}}
<form method="GET" action="{{ route('labels.create') }}" class="pb-label-picker" id="batchPicker" autocomplete="off">
    <div class="f-field pb-combo">
        <label for="pickBatch">Batch</label>
        <input id="pickBatch" name="q" class="pb-input pb-bn" value="{{ $current ?? '' }}" placeholder="Type a batch #, product, strain, brand or barcode…"
            autocomplete="off" spellcheck="false" required role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="batchDrop">
        <input type="hidden" name="batch_id" id="pickBatchId" value="" disabled>
        <div class="pb-combo-drop" id="batchDrop" role="listbox" hidden></div>
    </div>
    <button class="pb-btn" type="submit">Use this batch</button>
</form>
<script>
(function () {
    var i = document.getElementById('pickBatch'), id = document.getElementById('pickBatchId'), f = document.getElementById('batchPicker'), drop = document.getElementById('batchDrop');
    var t, seq = 0, items = [], active = -1, URL = @json(route('labels.batches', [], false));
    function close() { drop.hidden = true; i.setAttribute('aria-expanded', 'false'); active = -1; }
    function choose(it) { id.value = it.id; id.disabled = false; i.value = it.batch_number; close(); window.location = it.url; }
    function render(j) {
        drop.innerHTML = ''; items = j.items || []; active = -1;
        if (!items.length) {
            var m = document.createElement('div'); m.className = 'pb-combo-msg'; m.textContent = j.message || 'No batch matches'; drop.appendChild(m);
        }
        items.forEach(function (it, k) {
            var o = document.createElement('div'); o.className = 'pb-combo-opt'; o.setAttribute('role', 'option'); o.id = 'bopt' + k;
            var a = document.createElement('b'); a.className = 'pb-bn'; a.textContent = it.batch_number;
            var b = document.createElement('span'); b.textContent = it.product;
            var c = document.createElement('small'); c.className = 'pb-muted'; c.textContent = it.status + (it.barcode ? ' · ' + it.barcode : '');
            o.append(a, b, c);
            o.addEventListener('mousedown', function (e) { e.preventDefault(); choose(it); });
            drop.appendChild(o);
        });
        drop.hidden = false; i.setAttribute('aria-expanded', 'true');
    }
    function search() {
        var q = i.value.trim(), my = ++seq;
        id.value = ''; id.disabled = true;
        if (q.length < 2) { close(); return; }
        fetch(URL + '?q=' + encodeURIComponent(q), {headers: {'Accept': 'application/json'}})
            .then(function (r) { return r.json(); }).then(function (j) { if (my === seq) render(j); }).catch(function () {});
    }
    function move(d) {
        var os = drop.querySelectorAll('.pb-combo-opt'); if (!os.length) return;
        active = (active + d + os.length) % os.length;
        os.forEach(function (o, k) { o.classList.toggle('on', k === active); });
        i.setAttribute('aria-activedescendant', os[active].id);
    }
    i.addEventListener('input', function () { clearTimeout(t); t = setTimeout(search, 200); });
    i.addEventListener('focus', function () { if (i.value.trim().length >= 2) search(); });
    i.addEventListener('blur', function () { setTimeout(close, 150); });
    i.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); if (drop.hidden) search(); else move(1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
        else if (e.key === 'Escape') { close(); }
        else if (e.key === 'Enter' && active >= 0 && items[active]) { e.preventDefault(); choose(items[active]); }
    });
})();
</script>
