/*
 * Scan control (Nov 19). One file for every tag / UID / barcode field on the site.
 *
 * Markup (from resources/views/partials/scan.blade.php):
 *   <input id="X" ... data-scan="metrc|barcode|search|append" [data-scan-lot="#selectId"]>
 *   <button type="button" class="pb-scan-btn" data-scan-for="X">Scan</button>
 *   <small class="pb-scan-msg" data-scan-msg-for="X"></small>
 *
 * - Typing by hand works as before. Whitespace is stripped; metrc fields are uppercased and must be 24 letters/digits.
 * - Handheld scanners type the code + Enter: Enter in a scan field moves to the next field instead of submitting
 *   (except data-scan="search", where Enter runs the search as usual).
 * - "Scan" opens the rear camera and decodes Code 128, Code 39, QR and Data Matrix with the browser's BarcodeDetector,
 *   or the vendored ZXing library (public/js/vendor, loaded only when needed) on iOS Safari / Firefox.
 * - data-scan="fill" (generic correction box): only a camera scan is cleaned up; typed text is left alone.
 * - data-scan-lot="#select": after a fill, the lot with that Metrc package UID is looked up (/lots/lookup) and picked in
 *   the select; not found / not released / expired / not offered here shows an error.
 */
(function () {
    'use strict';
    var ZXING_SRC = (document.querySelector('meta[name="pb-zxing"]') || {}).content || '/js/vendor/zxing-library-0.21.3.min.js';
    var LOOKUP = (document.querySelector('meta[name="pb-lot-lookup"]') || {}).content || '/lots/lookup';
    var METRC_RE = /^[A-Z0-9]{24}$/;

    function msgFor(input) { return document.querySelector('[data-scan-msg-for="' + input.id + '"]'); }
    function say(input, text, kind) {
        var m = msgFor(input);
        if (!m) return;
        m.textContent = text || '';
        m.className = 'pb-scan-msg' + (kind ? ' is-' + kind : '');
    }

    /** Clean a value the way each kind expects; scanned QR text may hold a URL or label around the UID. */
    function normalise(input, raw, fromScan) {
        var kind = input.getAttribute('data-scan');
        var v = String(raw == null ? '' : raw);
        if (kind === 'metrc' || kind === 'append') {
            var up = v.toUpperCase();
            if (fromScan) { var m = up.match(/1A[0-9A-Z]{22}/); if (m) up = m[0]; }
            v = up.replace(/\s+/g, '');
        } else if (kind === 'search') {
            v = v.trim();
        } else if (kind === 'fill') {
            if (fromScan) { var t = v.trim(), u = t.toUpperCase().match(/1A[0-9A-Z]{22}/); v = u ? u[0] : t; }
        } else {
            v = v.replace(/\s+/g, '');
        }
        return v;
    }

    function validate(input) {
        var kind = input.getAttribute('data-scan');
        if (kind !== 'metrc') { input.setCustomValidity(''); return true; }
        var v = input.value;
        if (v === '' || METRC_RE.test(v)) {
            input.setCustomValidity('');
            if (v !== '') say(input, '✓ ' + v.length + ' characters', 'ok'); else say(input, '');
            return true;
        }
        var msg = 'A Metrc UID is exactly 24 letters and numbers (this one has ' + v.length + ').';
        input.setCustomValidity(msg);
        say(input, msg, 'err');
        return false;
    }

    function fire(input) {
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function fill(input, raw) {
        var v = normalise(input, raw, true);
        if (input.getAttribute('data-scan') === 'append' && input.value.trim() !== '') {
            input.value = input.value.replace(/\s+$/, '') + '\n' + v;
        } else {
            input.value = v;
        }
        fire(input);
        validate(input);
        if (input.getAttribute('data-scan-lot')) lookupLot(input);
        if (input.getAttribute('data-scan') === 'search' && input.form && input.form.getAttribute('method') !== 'post' && input.hasAttribute('data-scan-submit')) {
            input.form.submit();
        }
    }

    function nextField(input) {
        var form = input.form;
        var els = Array.prototype.filter.call((form || document).querySelectorAll('input, select, textarea, button'), function (el) {
            return !el.disabled && el.type !== 'hidden' && el.offsetParent !== null && !el.classList.contains('pb-scan-btn');
        });
        var i = els.indexOf(input);
        return i >= 0 ? els[i + 1] : null;
    }

    // ------------------------------------------------------------------ lot lookup
    function lookupLot(input) {
        var sel = document.querySelector(input.getAttribute('data-scan-lot'));
        var uid = input.value;
        if (!sel || !uid) return;
        say(input, 'Looking up ' + uid + '…');
        fetch(LOOKUP + '?uid=' + encodeURIComponent(uid), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { say(input, d.message, 'err'); return; }
                var opt = sel.querySelector('option[value="' + d.lot.id + '"]');
                if (!opt) { say(input, d.lot.public_id + ' (' + d.lot.ingredient + ') is released but is not offered here: wrong ingredient for this batch or not enough free stock.', 'err'); return; }
                sel.value = String(d.lot.id);
                sel.dispatchEvent(new Event('change', { bubbles: true }));
                var line = sel.getAttribute('data-scan-line');   // pick form: also choose the ingredient
                var group = opt.parentElement;
                if (line && group && group.tagName === 'OPTGROUP' && group.getAttribute('data-line')) {
                    var ls = document.querySelector(line);
                    if (ls) { ls.value = group.getAttribute('data-line'); ls.dispatchEvent(new Event('change', { bubbles: true })); }
                }
                say(input, '✓ Selected ' + d.lot.public_id + ' · ' + d.lot.ingredient, 'ok');
            })
            .catch(function () { say(input, 'Could not look up the lot. Pick it from the list.', 'err'); });
    }

    // ------------------------------------------------------------------ camera
    var overlay, video, statusEl, stream, timer, busy, current, zxingLoading, native;

    function buildOverlay() {
        if (overlay) return;
        overlay = document.createElement('div');
        overlay.className = 'pb-scan-overlay';
        overlay.id = 'pbScanOverlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Scan a barcode');
        overlay.innerHTML = '<div class="pb-scan-box"><video playsinline muted autoplay></video><div class="pb-scan-aim"></div>' +
            '<p class="pb-scan-status" aria-live="polite">Starting camera…</p>' +
            '<button type="button" class="pb-btn pb-scan-close">Cancel</button></div>';
        document.body.appendChild(overlay);
        video = overlay.querySelector('video');
        statusEl = overlay.querySelector('.pb-scan-status');
        overlay.querySelector('.pb-scan-close').addEventListener('click', close);
        overlay.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    }

    function close() {
        clearTimeout(timer);
        timer = null;
        if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
        if (overlay) overlay.classList.remove('is-open');
        if (current) { var c = current; current = null; c.focus(); }
    }

    function beep() {
        try { if (navigator.vibrate) navigator.vibrate(80); } catch (e) {}
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            var ctx = new Ctx(), o = ctx.createOscillator(), g = ctx.createGain();
            o.frequency.value = 1100; g.gain.value = 0.08;
            o.connect(g); g.connect(ctx.destination);
            o.start(); o.stop(ctx.currentTime + 0.09);
            o.onended = function () { ctx.close(); };
        } catch (e) {}
    }

    function done(text) {
        var input = current;
        beep();
        close();
        if (input) fill(input, text);
    }

    function nativeDetector() {
        if (native !== undefined) return Promise.resolve(native);
        if (!('BarcodeDetector' in window)) { native = null; return Promise.resolve(null); }
        var want = ['code_128', 'code_39', 'qr_code', 'data_matrix'];
        return window.BarcodeDetector.getSupportedFormats().then(function (have) {
            var f = want.filter(function (x) { return have.indexOf(x) >= 0; });
            native = f.length === want.length ? new window.BarcodeDetector({ formats: f }) : null;   // partial support: use ZXing
            return native;
        }).catch(function () { native = null; return null; });
    }

    function loadZxing() {
        if (window.ZXing) return Promise.resolve(window.ZXing);
        if (zxingLoading) return zxingLoading;
        zxingLoading = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = ZXING_SRC;
            s.onload = function () { window.ZXing ? resolve(window.ZXing) : reject(new Error('decoder')); };
            s.onerror = function () { zxingLoading = null; reject(new Error('decoder')); };
            document.head.appendChild(s);
        });
        return zxingLoading;
    }

    function zxingDecoder(Z) {
        var hints = new Map();
        hints.set(Z.DecodeHintType.POSSIBLE_FORMATS, [Z.BarcodeFormat.CODE_128, Z.BarcodeFormat.CODE_39, Z.BarcodeFormat.QR_CODE, Z.BarcodeFormat.DATA_MATRIX]);
        hints.set(Z.DecodeHintType.TRY_HARDER, true);
        var reader = new Z.MultiFormatReader();
        reader.setHints(hints);
        var canvas = document.createElement('canvas');
        return function () {
            var w = video.videoWidth, h = video.videoHeight;
            if (!w || !h) return null;
            var scale = Math.min(1, 960 / Math.max(w, h));
            canvas.width = Math.round(w * scale); canvas.height = Math.round(h * scale);
            canvas.getContext('2d', { willReadFrequently: true }).drawImage(video, 0, 0, canvas.width, canvas.height);
            try {
                var bmp = new Z.BinaryBitmap(new Z.HybridBinarizer(new Z.HTMLCanvasElementLuminanceSource(canvas)));
                return reader.decodeWithState(bmp).getText();
            } catch (e) { return null; }   // NotFound / checksum: try the next frame
        };
    }

    function loop(decode) {
        if (!stream) return;
        if (busy) { timer = setTimeout(function () { loop(decode); }, 120); return; }
        busy = true;
        Promise.resolve(decode()).then(function (text) {
            busy = false;
            if (text) done(text); else timer = setTimeout(function () { loop(decode); }, 120);
        }, function () { busy = false; timer = setTimeout(function () { loop(decode); }, 200); });
    }

    function open(input) {
        current = input;
        buildOverlay();
        overlay.classList.add('is-open');
        overlay.querySelector('.pb-scan-close').focus();
        if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            statusEl.textContent = 'This browser can\'t open the camera here. Type the number or use a handheld scanner.';
            return;
        }
        statusEl.textContent = 'Starting camera…';
        navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } } })
            .then(function (s) {
                if (!current) { s.getTracks().forEach(function (t) { t.stop(); }); return; }
                stream = s;
                video.srcObject = s;
                return video.play().catch(function () {}).then(function () {
                    statusEl.textContent = 'Point the camera at the tag\'s barcode.';
                    return nativeDetector().then(function (det) {
                        if (det) return loop(function () { return det.detect(video).then(function (r) { return r.length ? r[0].rawValue : null; }); });
                        return loadZxing().then(function (Z) { loop(zxingDecoder(Z)); }, function () {
                            statusEl.textContent = 'The barcode reader could not load. Type the number or use a handheld scanner.';
                        });
                    });
                });
            })
            .catch(function (err) {
                var n = err && err.name;
                statusEl.textContent = n === 'NotAllowedError' || n === 'SecurityError'
                    ? 'Camera access was blocked. Allow the camera for this site in your browser settings, or type the number / use a handheld scanner.'
                    : n === 'NotFoundError' || n === 'OverconstrainedError'
                        ? 'No camera found on this device. Type the number or use a handheld scanner.'
                        : 'The camera could not start (' + (n || 'error') + '). Type the number or use a handheld scanner.';
            });
    }

    // ------------------------------------------------------------------ wiring
    function wire(input) {
        if (input.__pbScan) return;
        input.__pbScan = true;
        input.setAttribute('autocomplete', 'off');
        // A pasted value with spaces or a prefix must not be cut short before it is cleaned; the 24-character rule still applies.
        if (input.getAttribute('data-scan') === 'metrc' && input.maxLength > 0 && input.maxLength < 100) input.maxLength = 100;
        input.setAttribute('spellcheck', 'false');
        input.addEventListener('keydown', function (e) {
            var k = input.getAttribute('data-scan');
            if (e.key !== 'Enter' || input.tagName === 'TEXTAREA' || k === 'search') return;
            e.preventDefault();   // a handheld scanner's Enter must not submit the form
            var v = normalise(input, input.value, true);
            if (v !== input.value) { input.value = v; fire(input); }
            validate(input);
            if (input.getAttribute('data-scan-lot')) lookupLot(input);
            var n = nextField(input);
            if (n) n.focus();
        });
        input.addEventListener('blur', function () {
            if (input.tagName === 'TEXTAREA' || input.getAttribute('data-scan') === 'fill') return;
            var v = normalise(input, input.value, false);
            if (v !== input.value) { input.value = v; fire(input); }
            validate(input);
        });
        input.addEventListener('input', function () {
            if (input.getAttribute('data-scan') === 'metrc' && input.validationMessage) validate(input);
        });
        if (input.form) input.form.addEventListener('submit', function (e) {
            if (input.tagName !== 'TEXTAREA' && input.getAttribute('data-scan') !== 'fill') input.value = normalise(input, input.value, false);
            if (!validate(input)) { e.preventDefault(); input.focus(); }
        });
    }

    function init(root) {
        (root || document).querySelectorAll('[data-scan]').forEach(wire);
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-scan-for]');
        if (!b) return;
        e.preventDefault();
        var input = document.getElementById(b.getAttribute('data-scan-for'));
        if (input) open(input);
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(); }); else init();
    window.PbScan = { init: init, fill: fill, open: open, close: close };
})();
