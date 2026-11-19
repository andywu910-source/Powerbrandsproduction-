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

    var drop = document.getElementById('bkDrop');
    var fileInput = document.getElementById('bkFile');
    if (drop && fileInput) {
        ['dragenter', 'dragover'].forEach(function (name) {
            drop.addEventListener(name, function (event) {
                event.preventDefault();
                drop.classList.add('over');
            });
        });
        ['dragleave', 'drop'].forEach(function (name) {
            drop.addEventListener(name, function (event) {
                event.preventDefault();
                drop.classList.remove('over');
            });
        });
        drop.addEventListener('drop', function (event) {
            if (event.dataTransfer && event.dataTransfer.files.length) {
                fileInput.files = event.dataTransfer.files;
            }
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
