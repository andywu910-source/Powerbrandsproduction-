@extends('layouts.app')
@section('title', 'Weekly Bookkeeping')
@section('page_title', 'Weekly Bookkeeping')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/bookkeeping.css') }}?v={{ @filemtime(public_path('css/bookkeeping.css')) }}">
@endpush

@section('content')
<div class="bk-week">
    <a class="pb-btn pb-btn-ghost" href="{{ route('bookkeeping.index', ['week' => $prevWeek]) }}">Previous week</a>
    <h3>Week of {{ $week->week_start->format('m/d/Y') }}</h3>
    @if ($showNext)
        <a class="pb-btn pb-btn-ghost" href="{{ route('bookkeeping.index', ['week' => $nextWeek]) }}">Next week</a>
    @endif
</div>

<section class="card bk-step" id="bk-step-1">
    <div class="card-head">
        <h3><span class="bk-num">1</span> Ledger update</h3>
    </div>
    <p><a class="link" href="{{ $ledgerUrl }}" target="_blank" rel="noopener noreferrer">Open the Google Sheet ledger</a></p>
    <p class="bk-status" id="bkLedgerStatus">Status: Last updated {{ $ledgerDate }}</p>
    @if ($ledgerSummary && $week->ledger_status !== 'statements_received')
        <p class="pb-muted">{{ $ledgerSummary }}</p>
    @endif
    @if ($week->ledger_status === 'statements_received')
        <p class="bk-status bk-pending" id="bkLedgerPending">Statements received, pending update</p>
    @endif

    <button type="button" class="btn-save" id="bkUpdateLedger" aria-controls="bkUploadWrap" aria-expanded="false">Update ledger</button>
    <div id="bkUploadWrap" hidden>
        <form method="post" action="{{ route('bookkeeping.statements.store') }}" enctype="multipart/form-data" class="bk-upload" id="bkUpload">
            @csrf
            <input type="hidden" name="week" value="{{ $weekDate }}">
            <div class="bk-drop" id="bkDrop" data-detect="{{ route('bookkeeping.statements.detect') }}">
                <p>Drop this week's bank statements here (CSV or PDF, any order), or choose files. Each file's account
                    ({{ collect($accounts)->map(fn ($a) => $accountLabels[$a] ?? $a)->implode(', ') }}) is detected from its contents or file name.</p>
                <label for="bkFile" class="pb-muted">Choose files</label>
                <input type="file" name="statements[]" id="bkFile" multiple accept=".csv,.pdf,text/csv,application/pdf" required>
            </div>
            @error('statements')<div class="pb-alert pb-alert-err" id="bkUploadErrors">@foreach ($errors->get('statements') as $msg)<div>{{ $msg }}</div>@endforeach</div>@enderror
            @error('statements.*')<div class="pb-alert pb-alert-err">{{ $message }}</div>@enderror
            <div class="table-wrap bk-table" id="bkFilesWrap" hidden>
                <table class="pb-table" id="bkFiles">
                    <thead><tr><th>File</th><th>Account</th><th>How</th><th></th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
            <template id="bkAccountOptions"><option value="">— pick the account —</option>@foreach ($accounts as $a)<option value="{{ $a }}">{{ $accountLabels[$a] ?? $a }}</option>@endforeach</template>
            <p class="pb-alert pb-alert-err" id="bkFilesProblem" hidden></p>
            <button type="submit" class="btn-save" id="bkSaveStatements">Save statements</button>
        </form>
    </div>
    <noscript><style>#bkUploadWrap{display:block !important}</style></noscript>

    @if ($statements->isNotEmpty())
        <div class="table-wrap bk-table">
            <table class="pb-table">
                <thead><tr><th>Account</th><th>File</th><th>Uploaded by</th><th>When</th></tr></thead>
                <tbody>
                @foreach ($statements as $statement)
                    <tr>
                        <td>{{ $statement->account }}</td>
                        <td><a href="{{ route('bookkeeping.statements.download', $statement) }}">{{ $statement->original_name }}</a></td>
                        <td>{{ $statement->uploader->name ?? '—' }}</td>
                        <td>{{ $statement->created_at->timezone($tz)->format('m/d/Y g:i A') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <form method="post" action="{{ route('bookkeeping.ledger.update') }}" class="pb-inline-form">
        @csrf
        <input type="hidden" name="week" value="{{ $weekDate }}">
        <div class="f-field">
            <label for="bkUpdatedOn">Date updated</label>
            <input class="pb-input" type="date" name="updated_on" id="bkUpdatedOn" required value="{{ old('updated_on', $today) }}" max="{{ $today }}">
        </div>
        <div class="f-field">
            <label for="bkSummary">Summary</label>
            <input class="pb-input" type="text" name="summary" id="bkSummary" required maxlength="500" placeholder="59 rows added, 21 flagged" value="{{ old('summary') }}">
        </div>
        <button type="submit" class="btn-save">Mark ledger updated</button>
    </form>
</section>

<section class="card bk-step" id="bk-step-2">
    <div class="card-head">
        <h3><span class="bk-num">2</span> Payments recorded in Distru</h3>
    </div>
    <p class="pb-muted">Confirm the week's payments are in Distru. That unlocks brand search for this week.</p>
    @if ($week->paymentsRecorded())
        <p class="bk-status">Recorded by {{ optional($week->paymentsBy)->name ?? 'an admin' }} on {{ $week->payments_recorded_at->timezone($tz)->format('m/d/Y g:i A') }}.</p>
    @endif
    <form method="post" action="{{ route('bookkeeping.payments') }}">
        @csrf
        <input type="hidden" name="week" value="{{ $weekDate }}">
        <button type="submit" class="btn-save">I've recorded payments in Distru</button>
    </form>
</section>

@if (! $week->paymentsRecorded())
    <section class="card bk-step is-locked" id="bk-step-3">
        <div class="card-head"><h3><span class="bk-num">3</span> Brand search</h3></div>
        <p class="pb-muted">Record payments in Distru to unlock this step.</p>
    </section>
    <section class="card bk-step is-locked" id="bk-step-4">
        <div class="card-head"><h3><span class="bk-num">4</span> Email template</h3></div>
        <p class="pb-muted">Record payments in Distru to unlock this step.</p>
    </section>
    <section class="card bk-step is-locked" id="bk-step-5">
        <div class="card-head"><h3><span class="bk-num">5</span> Approve &amp; send to selected</h3></div>
        <p class="pb-muted">Record payments in Distru to unlock this step.</p>
    </section>
@else
<section class="card bk-step" id="bk-step-3">
    <div class="card-head">
        <h3><span class="bk-num">3</span> Brand search</h3>
    </div>
    @if (! $distruReady)
        <div class="pb-alert pb-alert-warn" id="bkDistruSetup">Set DISTRU_API_KEY in the server environment (never in git). Brand search and invoice lookup stay off until then.</div>
    @else
        @if ($searchNotice)
            <div class="pb-alert pb-alert-err">{{ $searchNotice }}</div>
        @endif
        @if ($distruError)
            <div class="pb-alert pb-alert-err">{{ $distruError }}</div>
        @endif
        <form method="get" action="{{ route('bookkeeping.index') }}" class="bk-search" id="bkSearch" data-brands="{{ route('bookkeeping.brands') }}" data-add="{{ route('bookkeeping.brands.add') }}">
            <input type="hidden" name="week" value="{{ $weekDate }}">
            <div class="pb-combo">
                <label for="bkQuery">Brand</label>
                <input class="pb-input" type="search" name="q" id="bkQuery" value="{{ $q }}" placeholder="Type a brand name" autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="bkDropList">
                <div class="pb-combo-drop" id="bkDropList" role="listbox" hidden></div>
            </div>
            <button type="submit" class="pb-btn pb-btn-ghost">Search</button>
        </form>
        @if ($matches)
            <ul class="bk-matches">
                @foreach ($matches as $match)
                    <li>
                        <form method="post" action="{{ route('bookkeeping.brands.add') }}">
                            @csrf
                            <input type="hidden" name="week" value="{{ $weekDate }}">
                            <input type="hidden" name="company_id" value="{{ $match['id'] }}">
                            <button type="submit" class="bk-match">
                                <b>{{ $match['name'] }}</b>
                                <span>{{ $match['balance_label'] }}</span>
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @elseif ($q !== '')
            <p class="pb-muted">No brands match that search.</p>
        @endif

        <h4 class="bk-sub">Send list</h4>
        @if ($cards === [])
            <p class="pb-muted" id="bkSendEmpty">No brands yet. Search and add the ones to email.</p>
        @endif
        <div class="bk-cards" id="bkCards">
            @foreach ($cards as $card)
                <article class="bk-card" data-company="{{ $card['id'] }}" data-invoices="@json(array_column($card['invoices'], 'number'))">
                    <div class="bk-card-head">
                        <h4>{{ $card['name'] }}</h4>
                        <form method="post" action="{{ route('bookkeeping.brands.remove') }}">
                            @csrf
                            <input type="hidden" name="week" value="{{ $weekDate }}">
                            <input type="hidden" name="company_id" value="{{ $card['id'] }}">
                            <button type="submit" class="pb-btn pb-btn-ghost">Remove</button>
                        </form>
                    </div>
                    @if ($card['invoices'] === [])
                        <p>No open invoices</p>
                    @else
                        <ul class="bk-invoices">
                            @foreach ($card['invoices'] as $invoice)
                                <li>
                                    <span>{{ $invoice['number'] }}</span>
                                    <span>{{ $invoice['date'] }}</span>
                                    <span>{{ $invoice['amount_label'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <p>Total due <b>{{ $card['total_label'] }}</b></p>
                    <p>Email: {{ $card['email_label'] }}</p>
                    <label class="pb-check-inline">
                        <input form="bkSend" type="checkbox" name="exclude[]" value="{{ $card['id'] }}">
                        Exclude
                    </label>
                    <input form="bkSend" type="hidden" name="companies[]" value="{{ $card['id'] }}">

                    <details class="bk-override">
                        <summary>{{ $card['override'] ? 'Edit brand email' : 'Customize email' }}</summary>
                        <form method="post" action="{{ route('bookkeeping.template.save') }}" class="bk-template-form">
                            @csrf
                            <input type="hidden" name="week" value="{{ $weekDate }}">
                            <input type="hidden" name="company_id" value="{{ $card['id'] }}">
                            <input type="hidden" name="company_name" value="{{ $card['name'] }}">
                            <div class="f-field">
                                <label>Subject</label>
                                <input class="pb-input bk-subject" name="subject" maxlength="200" required value="{{ $card['subject'] }}">
                            </div>
                            <div class="f-field">
                                <label>Body</label>
                                <textarea class="pb-input bk-body" name="body" rows="6" maxlength="5000" required>{{ $card['body'] }}</textarea>
                            </div>
                            <button type="submit" class="btn-save">Save override</button>
                        </form>
                        @if ($card['override'])
                            <form method="post" action="{{ route('bookkeeping.template.revert') }}">
                                @csrf
                                <input type="hidden" name="week" value="{{ $weekDate }}">
                                <input type="hidden" name="company_id" value="{{ $card['id'] }}">
                                <button type="submit" class="pb-btn pb-btn-ghost">Revert to default</button>
                            </form>
                        @endif
                    </details>
                    <div class="bk-preview">
                        <p class="bk-preview-subject">{{ $card['subject'] }}</p>
                        <div class="bk-preview-body">{!! $card['preview_html'] !!}</div>
                        <p class="bk-attach">{{ $card['indicator'] }}</p>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>

<section class="card bk-step" id="bk-step-4">
    <div class="card-head">
        <h3><span class="bk-num">4</span> Email template</h3>
    </div>
    <details id="bkTemplate" data-preview="{{ route('bookkeeping.preview') }}">
        <summary>Edit email template</summary>
        <form method="post" action="{{ route('bookkeeping.template.save') }}" id="bkDefaultForm">
            @csrf
            <input type="hidden" name="week" value="{{ $weekDate }}">
            <div class="f-field">
                <label for="bkSubject">Subject</label>
                <input class="pb-input" id="bkSubject" name="subject" maxlength="200" required value="{{ old('subject', $defaultSubject) }}">
            </div>
            <div class="f-field">
                <label for="bkBody">Body</label>
                <textarea class="pb-input" id="bkBody" name="body" rows="8" maxlength="5000" required>{{ old('body', $defaultBody) }}</textarea>
                <small class="pb-muted">[signature] is replaced by the signature below.</small>
            </div>
            <button type="submit" class="btn-save">Save default template</button>
        </form>
        <form method="post" action="{{ route('bookkeeping.template.revert') }}">
            @csrf
            <input type="hidden" name="week" value="{{ $weekDate }}">
            <button type="submit" class="pb-btn pb-btn-ghost">Revert default to the original wording</button>
        </form>

        <form method="post" action="{{ route('bookkeeping.signature') }}" class="bk-signature">
            @csrf
            <input type="hidden" name="week" value="{{ $weekDate }}">
            <div class="f-field">
                <label for="bkSignature">Signature (HTML)</label>
                <textarea class="pb-input" id="bkSignature" name="signature_html" rows="5" maxlength="20000">{{ old('signature_html', $signature) }}</textarea>
                <small class="pb-muted">Leave this blank to send without a signature block. Links must be https or mailto.</small>
            </div>
            <button type="submit" class="btn-save">Save signature</button>
        </form>
        @if ($signatureStored)
            <form method="post" action="{{ route('bookkeeping.signature.reset') }}">
                @csrf
                <input type="hidden" name="week" value="{{ $weekDate }}">
                <button type="submit" class="pb-btn pb-btn-ghost">Use environment signature</button>
            </form>
        @endif

        <div class="bk-preview" id="bkDefaultPreview" aria-live="polite">
            <p class="bk-preview-subject" id="bkPreviewSubject">{{ $defaultSubject }}</p>
            <div class="bk-preview-body" id="bkPreviewBody">{!! $previewHtml !!}</div>
            <p class="bk-attach" id="bkPreviewAttach">Attached: open invoice PDF(s)</p>
        </div>
    </details>
</section>

<section class="card bk-step" id="bk-step-5">
    <div class="card-head">
        <h3><span class="bk-num">5</span> Approve &amp; send to selected</h3>
    </div>
    <p class="pb-muted">One email per brand that is not excluded, from {{ config('bookkeeping.mail.from_address') }}, with that brand's open invoice PDFs attached. Nothing is sent until you confirm.</p>
    @if (! $mailReady)
        <div class="pb-alert pb-alert-warn" id="bkMailSetup">Email is not set up. Add BOOKKEEPING_MAIL_TRANSPORT and SMTP or Gmail API credentials to the server environment. The send button stays off until then. No email will be sent.</div>
    @endif
    @if (! $distruReady)
        <div class="pb-alert pb-alert-warn">Invoice PDFs come from Distru. Set DISTRU_API_KEY before sending.</div>
    @endif
    <form method="post" action="{{ route('bookkeeping.send') }}" id="bkSend">
        @csrf
        <input type="hidden" name="week" value="{{ $weekDate }}">
        <label class="pb-check-inline">
            <input type="checkbox" name="confirm" value="yes" required @disabled(! $mailReady || ! $distruReady || $cards === [])>
            I confirm these emails should be sent
        </label>
        <button type="submit" class="btn-save" id="bkSendButton" @disabled(! $mailReady || ! $distruReady || $cards === [])>Approve &amp; send to selected</button>
    </form>
    @if ($sends->isNotEmpty())
        <div class="table-wrap bk-table">
            <table class="pb-table">
                <thead><tr><th>When</th><th>Who</th><th>Brand</th><th>To</th><th>Invoices</th><th>Result</th></tr></thead>
                <tbody>
                @foreach ($sends as $send)
                    <tr>
                        <td>{{ $send->sent_at->timezone($tz)->format('m/d/Y g:i A') }}</td>
                        <td>{{ $send->sender->name ?? '—' }}</td>
                        <td>{{ $send->company_name }}</td>
                        <td>{{ implode(', ', $send->recipients ?? []) }}</td>
                        <td>{{ collect($send->invoices ?? [])->pluck('number')->implode(', ') }}</td>
                        <td>{{ $send->status }}@if ($send->error) — {{ $send->error }}@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endif
@endsection

@push('scripts')
<script src="{{ asset('js/bookkeeping.js') }}?v={{ @filemtime(public_path('js/bookkeeping.js')) }}"></script>
@endpush
