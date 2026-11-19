{{--
    Scan control: a "Scan" button + message line for a tag / UID / barcode input. Logic: public/js/scan.js (loaded on every page).
    Usage (wrap the input so the button sits beside it):
        <div class="pb-scan-wrap">
            <input id="my_uid" name="my_uid" class="pb-input" data-scan="metrc">
            @include('partials.scan', ['for' => 'my_uid'])
        </div>
    data-scan on the input: metrc (uppercase, 24 letters/digits) · barcode (no spaces, case kept) · append (textarea: each scan on a new line)
    · search (Enter still submits). Add data-scan-lot="#selectId" to pick the MaterialLot with that Metrc package UID in a select.
--}}
<button type="button" class="pb-scan-btn" data-scan-for="{{ $for }}" title="Scan a barcode with the camera" aria-label="Scan a barcode with the camera{{ isset($label) ? ' for '.$label : '' }}"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M7 8v8M10 8v8M13 8v8M17 8v8"/></svg><span>Scan</span></button>
<small class="pb-scan-msg" data-scan-msg-for="{{ $for }}" aria-live="polite"></small>
