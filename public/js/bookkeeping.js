(function () {
    var tokenEl = document.querySelector('meta[name="csrf-token"]');
    var token = tokenEl ? tokenEl.getAttribute('content') : '';

    var openBtn = document.getElementById('bkUpdateLedger');
    var wrap = document.getElementById('bkUploadWrap');
    if (openBtn && wrap) {
        openBtn.addEventListener('click', function () {
            var open = wrap.hasAttribute('hidden');
            if (open) wrap.removeAttribute('hidden');
            else wrap.setAttribute('hidden', '');
            openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                var file = document.getElementById('bkFile');
                if (file) file.focus();
            }
        });
    }

    // Step 1: several statements at once; each file's account is detected on the server, the admin can correct it.
    var drop = document.getElementById('bkDrop');
    var fileInput = document.getElementById('bkFile');
    var form = document.getElementById('bkUpload');
    var tableWrap = document.getElementById('bkFilesWrap');
    var tbody = tableWrap ? tableWrap.querySelector('tbody') : null;
    var optionsTpl = document.getElementById('bkAccountOptions');
    var problem = document.getElementById('bkFilesProblem');
    var saveBtn = document.getElementById('bkSaveStatements');
    var files = [];   // {file, account, note, source, error}

    function key(f) { return f.name + '|' + f.size + '|' + f.lastModified; }

    function addFiles(list) {
        Array.prototype.forEach.call(list || [], function (f) {
            if (!files.some(function (x) { return key(x.file) === key(f); })) {
                files.push({ file: f, account: '', note: 'Checking…', source: null, error: null });
            }
        });
        syncInput();
        render();
        detect();
    }

    function syncInput() {
        if (typeof DataTransfer === 'undefined') return;
        var dt = new DataTransfer();
        files.forEach(function (x) { dt.items.add(x.file); });
        fileInput.files = dt.files;
        fileInput.required = files.length === 0;
    }

    function detect() {
        if (!files.length) return;
        var fd = new FormData();
        files.forEach(function (x) { fd.append('statements[]', x.file); });
        var snapshot = files.slice();
        fetch(drop.getAttribute('data-detect'), {
            method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' }
        }).then(function (r) { return r.ok ? r.json() : Promise.reject(r); }).then(function (data) {
            (data.files || []).forEach(function (row) {
                var x = snapshot[row.index];
                if (!x || files.indexOf(x) === -1) return;
                if (!x.touched) x.account = row.account || '';
                x.note = row.error ? row.error : row.note;
                x.error = row.error || null;
                x.source = row.source;
            });
            render();
        }).catch(function () {
            snapshot.forEach(function (x) { if (!x.touched && !x.account) x.note = 'Could not check this file. Pick the account.'; });
            render();
        });
    }

    function render() {
        if (!tbody) return;
        tbody.replaceChildren();
        tableWrap.hidden = files.length === 0;
        files.forEach(function (x, i) {
            var tr = document.createElement('tr');
            var name = document.createElement('td'); name.textContent = x.file.name;
            var acct = document.createElement('td');
            var sel = document.createElement('select');
            sel.name = 'accounts[]'; sel.className = 'pb-select'; sel.setAttribute('aria-label', 'Account for ' + x.file.name);
            sel.appendChild(optionsTpl.content.cloneNode(true));
            sel.value = x.account || '';
            sel.addEventListener('change', function () { x.account = sel.value; x.touched = true; x.source = 'picked'; x.note = 'Picked'; render(); });
            acct.appendChild(sel);
            var how = document.createElement('td'); how.className = 'pb-muted'; how.textContent = x.note || '';
            var rm = document.createElement('td');
            var b = document.createElement('button'); b.type = 'button'; b.className = 'pb-btn pb-btn-ghost btn-sm'; b.textContent = 'Remove';
            b.addEventListener('click', function () { files.splice(i, 1); syncInput(); render(); });
            rm.appendChild(b);
            tr.append(name, acct, how, rm);
            tbody.appendChild(tr);
        });
        check();
    }

    function check() {
        var msgs = [];
        var seen = {};
        files.forEach(function (x) {
            if (x.error) msgs.push(x.file.name + ': ' + x.error);
            else if (!x.account) msgs.push('Pick the account for ' + x.file.name + '.');
            else if (seen[x.account]) msgs.push(seen[x.account] + ' and ' + x.file.name + ' are both account ' + x.account + '.');
            else seen[x.account] = x.file.name;
        });
        if (problem) { problem.hidden = msgs.length === 0; problem.textContent = msgs.join(' '); }
        if (saveBtn) saveBtn.disabled = files.length === 0 || msgs.length > 0;
        return msgs.length === 0;
    }

    if (drop && fileInput && form) {
        ['dragenter', 'dragover'].forEach(function (name) {
            drop.addEventListener(name, function (event) { event.preventDefault(); drop.classList.add('over'); });
        });
        ['dragleave', 'drop'].forEach(function (name) {
            drop.addEventListener(name, function (event) { event.preventDefault(); drop.classList.remove('over'); });
        });
        drop.addEventListener('drop', function (event) {
            if (event.dataTransfer && event.dataTransfer.files.length) addFiles(event.dataTransfer.files);
        });
        fileInput.addEventListener('change', function () {
            var picked = Array.prototype.slice.call(fileInput.files || []);
            if (typeof DataTransfer === 'undefined') return;   // very old browser: plain multi-file upload, server detects
            addFiles(picked);
        });
        form.addEventListener('submit', function (event) {
            if (typeof DataTransfer !== 'undefined' && files.length && !check()) event.preventDefault();
        });
    }

    var search = document.getElementById('bkSearch');
    var query = document.getElementById('bkQuery');
    var list = document.getElementById('bkDropList');
    if (search && query && list) {
        var timer = null;
        query.addEventListener('input', function () {
            window.clearTimeout(timer);
            var value = query.value.trim();
            if (value.length < 2) {
                list.hidden = true;
                list.replaceChildren();
                query.setAttribute('aria-expanded', 'false');
                return;
            }
            timer = window.setTimeout(function () { lookup(value); }, 200);
        });

        function lookup(value) {
            var week = search.querySelector('input[name="week"]').value;
            var url = search.getAttribute('data-brands') + '?week=' + encodeURIComponent(week) + '&q=' + encodeURIComponent(value);
            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(function (res) { return res.json(); })
                .then(function (data) { paint(data); })
                .catch(function () { paint({ ok: false, message: 'Search failed.' }); });
        }

        function paint(data) {
            list.replaceChildren();
            if (!data.ok) {
                list.appendChild(note(data.message || 'Search failed.'));
            } else if (!data.brands || !data.brands.length) {
                list.appendChild(note('No brands match.'));
            } else {
                data.brands.forEach(function (brand) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'pb-combo-opt';
                    button.setAttribute('role', 'option');
                    var name = document.createElement('b');
                    name.textContent = brand.name;
                    var balance = document.createElement('small');
                    balance.textContent = brand.balance_label;
                    button.appendChild(name);
                    button.appendChild(balance);
                    button.addEventListener('click', function () { addBrand(brand.id); });
                    list.appendChild(button);
                });
            }
            list.hidden = false;
            query.setAttribute('aria-expanded', 'true');
        }

        function note(text) {
            var el = document.createElement('div');
            el.className = 'pb-combo-msg';
            el.textContent = text;
            return el;
        }

        function addBrand(id) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = search.getAttribute('data-add');
            hidden(form, '_token', token);
            hidden(form, 'week', search.querySelector('input[name="week"]').value);
            hidden(form, 'company_id', id);
            document.body.appendChild(form);
            form.submit();
        }
    }

    var template = document.getElementById('bkTemplate');
    var subject = document.getElementById('bkSubject');
    var body = document.getElementById('bkBody');
    var signature = document.getElementById('bkSignature');
    var previewSubject = document.getElementById('bkPreviewSubject');
    var previewBody = document.getElementById('bkPreviewBody');
    var previewAttach = document.getElementById('bkPreviewAttach');
    if (template && subject && body && previewBody) {
        var previewTimer = null;
        [subject, body, signature].forEach(function (el) {
            if (el) el.addEventListener('input', schedule);
        });
        function schedule() {
            window.clearTimeout(previewTimer);
            previewTimer = window.setTimeout(run, 250);
        }
        function run() {
            var data = new FormData();
            data.append('subject', subject.value);
            data.append('body', body.value);
            data.append('signature_html', signature ? signature.value : '');
            data.append('week', template.querySelector('input[name="week"]').value);
            fetch(template.getAttribute('data-preview'), {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                body: data
            }).then(function (res) { return res.json(); }).then(function (payload) {
                if (!payload || !payload.html) return;
                previewSubject.textContent = payload.subject || '';
                previewBody.innerHTML = payload.html;
                if (previewAttach) previewAttach.textContent = payload.indicator || 'Attached: open invoice PDF(s)';
            }).catch(function () {});
        }
    }

    function hidden(form, name, value) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }
})();
