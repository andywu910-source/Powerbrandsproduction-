<?php

namespace App\Http\Controllers;

use App\Models\BookkeepingSend;
use App\Models\BookkeepingSetting;
use App\Models\BookkeepingStatement;
use App\Models\BookkeepingTemplate;
use App\Models\BookkeepingWeek;
use App\Models\User;
use App\Services\Bookkeeping\BookkeepingMailer;
use App\Services\Bookkeeping\BookkeepingMessage;
use App\Services\Bookkeeping\DistruClient;
use App\Services\Bookkeeping\DistruException;
use App\Services\Bookkeeping\EmailComposer;
use App\Services\Bookkeeping\StatementStore;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BookkeepingController extends Controller
{
    public function index(Request $request, DistruClient $distru, BookkeepingMailer $mail)
    {
        $week = $this->week($request);
        $q = trim((string) $request->query('q', ''));
        $matches = [];
        $searchNotice = null;
        $cards = [];
        $distruError = null;

        if ($week->paymentsRecorded()) {
            if (! $distru->configured()) {
                $searchNotice = 'Set DISTRU_API_KEY in the server environment (never in git). Brand search and invoice lookup stay off until then.';
            } elseif ($q !== '') {
                try {
                    $matches = $distru->searchBrands($q);
                } catch (DistruException $e) {
                    $searchNotice = $e->getMessage();
                }
            }
            try {
                $cards = $this->cards($week, $distru);
            } catch (DistruException $e) {
                $distruError = $e->getMessage();
            }
        }

        $tz = config('pbp.site_timezone', 'America/New_York');
        $defaultBody = BookkeepingTemplate::bodyFor('');
        [$previewText, $previewHtml] = EmailComposer::render($defaultBody, EmailComposer::signatureHtml());

        return view('bookkeeping.index', [
            'week' => $week,
            'weekDate' => $week->week_start->toDateString(),
            'prevWeek' => $week->week_start->copy()->subWeek()->toDateString(),
            'nextWeek' => $week->week_start->copy()->addWeek()->toDateString(),
            'showNext' => $week->week_start->copy()->addWeek()->lte(BookkeepingWeek::monday()),
            'ledgerUrl' => config('bookkeeping.ledger_url'),
            'ledgerDate' => BookkeepingSetting::ledgerDateLabel(),
            'ledgerSummary' => BookkeepingSetting::ledgerSummary(),
            'accounts' => config('bookkeeping.accounts'),
            'accountLabels' => (array) config('bookkeeping.account_labels', []),
            'statements' => $week->statements()->with('uploader')->get(),
            'sends' => $week->sends()->with('sender')->get(),
            'tz' => $tz,
            'today' => Carbon::now($tz)->toDateString(),
            'q' => $q,
            'matches' => $matches,
            'searchNotice' => $searchNotice,
            'cards' => $cards,
            'distruError' => $distruError,
            'distruReady' => $distru->configured(),
            'mailReady' => $mail->configured(),
            'defaultSubject' => BookkeepingTemplate::subjectFor(''),
            'defaultBody' => $defaultBody,
            'previewText' => $previewText,
            'previewHtml' => $previewHtml,
            'signature' => EmailComposer::signatureHtml(),
            'signatureStored' => EmailComposer::signatureIsStored(),
        ]);
    }

    /**
     * Step 1 (Nov 19): several statements at once, any order. Each file's account is detected (contents first,
     * then file name) or picked by the admin (accounts[i]). Every file needs an account and no two files may share
     * one; nothing is saved unless all files pass.
     */
    public function upload(Request $request, StatementStore $store, \App\Services\Bookkeeping\AccountDetector $detector)
    {
        $week = $this->week($request);
        $accounts = (array) config('bookkeeping.accounts');
        $request->validate([
            'statements' => ['required', 'array', 'min:1', 'max:'.count($accounts)],
            'statements.*' => ['required', 'file', 'max:10240'],
            'accounts' => ['nullable', 'array'],
            'accounts.*' => ['nullable', 'in:'.implode(',', $accounts)],
        ], [
            'statements.required' => 'Add at least one CSV or PDF statement.',
            'statements.max' => 'Upload at most one statement per account ('.count($accounts).' files).',
        ]);

        $plan = $this->plan($request, $store, $detector);
        $errors = [];
        $byAccount = [];
        foreach ($plan as $i => $p) {
            if ($p['account'] === null) {
                $errors[] = "Pick the account for {$p['name']}. {$p['note']}";
            } else {
                $byAccount[$p['account']][] = $p['name'];
            }
        }
        foreach ($byAccount as $acct => $names) {
            if (count($names) > 1) {
                $errors[] = implode(' and ', $names)." are both account {$acct}. Each account gets one statement.";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages(['statements' => $errors]);
        }

        $saved = DB::transaction(fn () => collect($plan)->map(fn ($p) => $store->store($week, $p['account'], $p['file'], $request->user(), $p['inspected']))->all());
        $week->forceFill(['ledger_status' => 'statements_received'])->save();

        return $this->back($week)->with('status', 'Saved '.collect($saved)->map(fn ($s) => $s->original_name.' → '.$s->account)->implode(', ').'. Statements received, pending update.');
    }

    /** JSON preview for the drop zone: each file's detected account. Saves nothing. */
    public function detect(Request $request, StatementStore $store, \App\Services\Bookkeeping\AccountDetector $detector)
    {
        $request->validate(['statements' => ['required', 'array', 'min:1', 'max:20'], 'statements.*' => ['required', 'file', 'max:10240']]);
        $rows = [];
        foreach ((array) $request->file('statements') as $i => $file) {
            try {
                $in = $store->inspect($file);
                $d = $detector->detect($file->getClientOriginalName(), $in['ext'], $in['bytes']);
                $rows[] = ['index' => $i, 'name' => $file->getClientOriginalName()] + $d + ['error' => null];
            } catch (ValidationException $e) {
                $rows[] = ['index' => $i, 'name' => $file->getClientOriginalName(), 'account' => null, 'source' => null, 'note' => '',
                    'error' => collect($e->errors())->flatten()->first()];
            }
        }

        return response()->json(['files' => $rows]);
    }

    /** @return array<int, array{file: \Illuminate\Http\UploadedFile, name: string, inspected: array, account: ?string, note: string}> */
    private function plan(Request $request, StatementStore $store, \App\Services\Bookkeeping\AccountDetector $detector): array
    {
        $picked = (array) $request->input('accounts', []);
        $plan = [];
        $bad = [];
        foreach (array_values((array) $request->file('statements')) as $i => $file) {
            $name = mb_substr((string) $file->getClientOriginalName(), 0, 120);
            try {
                $in = $store->inspect($file);
            } catch (ValidationException $e) {
                $bad[] = $name.': '.collect($e->errors())->flatten()->first();
                continue;
            }
            $choice = (string) ($picked[$i] ?? '');
            $d = $choice !== '' ? ['account' => $choice, 'note' => ''] : $detector->detect($name, $in['ext'], $in['bytes']);
            $plan[] = ['file' => $file, 'name' => $name, 'inspected' => $in, 'account' => $d['account'], 'note' => $d['note']];
        }
        if ($bad) {
            throw ValidationException::withMessages(['statements' => $bad]);
        }

        return $plan;
    }

    public function download(BookkeepingStatement $statement)
    {
        $path = (string) $statement->path;
        abort_unless(preg_match('#^bookkeeping/statements/\d+/[0-9a-f-]{36}\.(csv|pdf)$#', $path) === 1, 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, $statement->original_name, [
            'Content-Type' => $statement->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function markUpdated(Request $request)
    {
        $week = $this->week($request);
        $data = $request->validate([
            'updated_on' => ['required', 'date_format:Y-m-d'],
            'summary' => ['required', 'string', 'max:500'],
        ]);
        $tz = config('pbp.site_timezone', 'America/New_York');
        $day = Carbon::parse($data['updated_on'], $tz)->startOfDay();
        $today = Carbon::now($tz)->startOfDay();
        if ($day->lt(Carbon::parse('2000-01-01', $tz)) || $day->gt($today)) {
            throw ValidationException::withMessages(['updated_on' => 'Use a date from 2000 through today.']);
        }
        $summary = trim($data['summary']);
        $week->forceFill([
            'ledger_status' => 'updated',
            'ledger_updated_on' => $day->toDateString(),
            'ledger_summary' => $summary,
            'ledger_updated_by' => $request->user()->id,
            'ledger_updated_at' => now(),
        ])->save();
        BookkeepingSetting::put('ledger_updated_on', $day->toDateString(), $request->user()->id);
        BookkeepingSetting::put('ledger_summary', $summary, $request->user()->id);

        return $this->back($week)->with('status', 'Ledger marked updated '.$day->format('m/d/Y').'.');
    }

    public function paymentsRecorded(Request $request)
    {
        $week = $this->week($request);
        $week->forceFill([
            'payments_recorded_at' => now(),
            'payments_recorded_by' => $request->user()->id,
        ])->save();

        return $this->back($week)->with('status', 'Payments recorded. Brand search is unlocked for this week.');
    }

    public function brands(Request $request, DistruClient $distru)
    {
        $week = $this->week($request);
        $this->ensurePayments($week);
        if (! $distru->configured()) {
            return response()->json([
                'ok' => false,
                'message' => 'Set DISTRU_API_KEY in the server environment. Brand search is off until then.',
            ]);
        }
        try {
            return response()->json([
                'ok' => true,
                'brands' => $distru->searchBrands((string) $request->query('q', '')),
            ]);
        } catch (DistruException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 502);
        }
    }

    public function addBrand(Request $request, DistruClient $distru)
    {
        $week = $this->week($request);
        $this->ensurePayments($week);
        if (! $distru->configured()) {
            return $this->back($week)->withErrors(['q' => 'Set DISTRU_API_KEY in the server environment. Brand search is off until then.']);
        }
        $id = (string) $request->input('company_id');
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            throw ValidationException::withMessages(['company_id' => 'Choose a brand from the list.']);
        }
        try {
            $found = $distru->companies([$id]);
        } catch (DistruException $e) {
            return $this->back($week)->withErrors(['q' => $e->getMessage()]);
        }
        if (! isset($found[$id])) {
            return $this->back($week)->withErrors(['q' => 'That brand was not found in Distru.']);
        }
        $ids = $this->sendIds($week);
        if (! in_array($id, $ids, true)) {
            if (count($ids) >= 50) {
                return $this->back($week)->withErrors(['q' => 'The send list is full (50 brands).']);
            }
            $ids[] = $id;
            $request->session()->put($this->listKey($week), $ids);
        }

        return $this->back($week)->with('status', $found[$id]['name'].' added to the send list.');
    }

    public function removeBrand(Request $request)
    {
        $week = $this->week($request);
        $this->ensurePayments($week);
        $id = (string) $request->input('company_id');
        $ids = array_values(array_filter($this->sendIds($week), fn ($existing) => $existing !== $id));
        $request->session()->put($this->listKey($week), $ids);

        return $this->back($week)->with('status', 'Removed from the send list.');
    }

    public function saveTemplate(Request $request)
    {
        $week = $this->week($request);
        $this->ensurePayments($week);
        $data = $request->validate([
            'company_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'company_name' => ['nullable', 'string', 'max:200'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
        ]);
        $companyId = (string) ($data['company_id'] ?? '');
        $companyName = trim((string) ($data['company_name'] ?? ''));
        if ($companyId !== '' && $companyName === '') {
            throw ValidationException::withMessages(['company_name' => 'Name the brand for this override.']);
        }
        BookkeepingTemplate::query()->updateOrCreate(
            ['company_id' => $companyId],
            [
                'company_name' => $companyId === '' ? null : $companyName,
                'subject' => $data['subject'],
                'body' => $data['body'],
                'updated_by' => $request->user()->id,
            ]
        );

        return $this->back($week)->with('status', $companyId === '' ? 'Default email template saved.' : 'Saved the email override for '.$companyName.'.');
    }

    public function revertTemplate(Request $request)
    {
        $week = $this->week($request);
        $this->ensurePayments($week);
        $companyId = (string) $request->input('company_id', '');
        if ($companyId !== '' && ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $companyId)) {
            throw ValidationException::withMessages(['company_id' => 'That brand override could not be reverted.']);
        }
        BookkeepingTemplate::query()->where('company_id', $companyId)->delete();

        return $this->back($week)->with('status', $companyId === '' ? 'Default template restored.' : 'Brand template reverted to the default.');
    }

    public function saveSignature(Request $request)
    {
        $week = $this->week($request);
        $this->ensurePayments($week);
        $data = $request->validate(['signature_html' => ['nullable', 'string', 'max:20000']]);
        BookkeepingSetting::put('signature_html', EmailComposer::sanitize((string) ($data['signature_html'] ?? '')), $request->user()->id);

        return $this->back($week)->with('status', 'Signature saved.');
    }

    public function resetSignature(Request $request)
    {
        $week = $this->week($request);
        $this->ensurePayments($week);
        BookkeepingSetting::forget('signature_html');

        return $this->back($week)->with('status', 'Signature now uses BOOKKEEPING_SIGNATURE_HTML from the server environment.');
    }

    public function preview(Request $request)
    {
        $week = $this->week($request);
        $this->ensurePayments($week);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'signature_html' => ['nullable', 'string', 'max:20000'],
            'invoice_numbers' => ['array', 'max:50'],
            'invoice_numbers.*' => ['string', 'max:80'],
        ]);
        [$text, $html] = EmailComposer::render($data['body'], EmailComposer::sanitize((string) ($data['signature_html'] ?? '')));

        return response()->json([
            'subject' => $data['subject'],
            'text' => $text,
            'html' => $html,
            'indicator' => EmailComposer::attachmentIndicator($data['invoice_numbers'] ?? []),
        ]);
    }

    public function send(Request $request, DistruClient $distru, BookkeepingMailer $mail)
    {
        $week = $this->week($request);
        $this->ensurePayments($week);
        if (! $distru->configured()) {
            return $this->back($week)->withErrors(['send' => 'Set DISTRU_API_KEY in the server environment. No email was sent.']);
        }
        if (! $mail->configured()) {
            return $this->back($week)->withErrors(['send' => 'Email is not set up. Add BOOKKEEPING_MAIL_TRANSPORT and credentials to the server environment. No email was sent.']);
        }

        $data = $request->validate([
            'confirm' => ['required', 'in:yes'],
            'companies' => ['required', 'array', 'min:1', 'max:50'],
            'companies.*' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'exclude' => ['nullable', 'array'],
            'exclude.*' => ['string'],
        ]);
        $excluded = array_flip($data['exclude'] ?? []);
        $ids = [];
        foreach ($data['companies'] as $id) {
            if (! isset($excluded[$id])) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if ($ids === []) {
            return $this->back($week)->withErrors(['send' => 'Every brand is excluded. Leave at least one included. No email was sent.']);
        }

        try {
            $companies = $distru->companies($ids);
            $invoices = $distru->openInvoicesFor($ids);
        } catch (DistruException $e) {
            return $this->back($week)->withErrors(['send' => $e->getMessage()]);
        }

        $sent = 0;
        $notes = [];
        foreach ($ids as $id) {
            $brand = $companies[$id] ?? null;
            $name = $brand['name'] ?? $id;
            $rows = $invoices[$id] ?? [];
            $email = $brand['email'] ?? null;
            if (! $brand || ! $email) {
                $notes[] = $name.': not emailed (Not on file).';

                continue;
            }
            if ($rows === []) {
                $notes[] = $name.': not emailed (No open invoices).';

                continue;
            }
            $subject = BookkeepingTemplate::subjectFor($id);
            $body = BookkeepingTemplate::bodyFor($id);
            [$text, $html] = EmailComposer::render($body, EmailComposer::signatureHtml());
            try {
                $attachments = [];
                foreach ($rows as $invoice) {
                    if ($invoice['id'] === '') {
                        throw new DistruException('An invoice was missing an id. Nothing was emailed for '.$name.'.');
                    }
                    $attachments[] = [
                        'filename' => EmailComposer::pdfName($invoice['number']),
                        'mime' => 'application/pdf',
                        'bytes' => $distru->invoicePdf($invoice['id']),
                    ];
                }
                $mail->send(new BookkeepingMessage(
                    $this->fromAddress(),
                    trim((string) config('bookkeeping.mail.from_name')) ?: 'Powerbrands',
                    $email,
                    $subject,
                    $text,
                    $html,
                    $attachments,
                ));
                $this->logSend($week, $request->user(), $id, $name, [$email], $rows, $subject, 'sent', null);
                $sent++;
            } catch (\Throwable $e) {
                $message = $this->publicError($e);
                $this->logSend($week, $request->user(), $id, $name, [$email], $rows, $subject, 'failed', $message);
                $notes[] = $name.': '.$message;
            }
        }

        $status = $sent === 1 ? 'Sent 1 email.' : "Sent {$sent} emails.";
        $redirect = $this->back($week)->with('status', $status);
        if ($notes) {
            $redirect = $redirect->withErrors(['send' => implode(' ', $notes)]);
        }

        return $redirect;
    }

    /** @return array<int, array<string, mixed>> */
    private function cards(BookkeepingWeek $week, DistruClient $distru): array
    {
        if (! $week->paymentsRecorded() || ! $distru->configured()) {
            return [];
        }
        $ids = $this->sendIds($week);
        if ($ids === []) {
            return [];
        }
        $companies = $distru->companies($ids);
        $invoices = $distru->openInvoicesFor($ids);
        $cards = [];
        foreach ($ids as $id) {
            $brand = $companies[$id] ?? [
                'id' => $id,
                'name' => 'Unknown brand',
                'legal_name' => '',
                'balance' => 0.0,
                'balance_label' => 'No open invoices',
                'email' => null,
                'email_label' => 'Not on file',
            ];
            $rows = $invoices[$id] ?? [];
            $total = round(array_sum(array_map(fn ($row) => (float) $row['amount_due'], $rows)), 2);
            $subject = BookkeepingTemplate::subjectFor($id);
            $body = BookkeepingTemplate::bodyFor($id);
            [$text, $html] = EmailComposer::render($body, EmailComposer::signatureHtml());
            $cards[] = array_merge($brand, [
                'missing' => ! isset($companies[$id]),
                'invoices' => $rows,
                'total' => $total,
                'total_label' => '$'.number_format($total, 2),
                'subject' => $subject,
                'body' => $body,
                'override' => BookkeepingTemplate::hasOverride($id),
                'preview_text' => $text,
                'preview_html' => $html,
                'indicator' => EmailComposer::attachmentIndicator(array_column($rows, 'number')),
            ]);
        }

        return $cards;
    }

    /** @param  array<int, array<string, mixed>>  $invoices */
    private function logSend(BookkeepingWeek $week, User $user, string $companyId, string $name, array $recipients, array $invoices, string $subject, string $status, ?string $error): void
    {
        BookkeepingSend::create([
            'bookkeeping_week_id' => $week->id,
            'company_id' => $companyId,
            'company_name' => mb_substr($name, 0, 255),
            'recipients' => array_values($recipients),
            'invoices' => array_map(fn ($invoice) => [
                'id' => $invoice['id'],
                'number' => $invoice['number'],
                'amount_due' => $invoice['amount_due'],
            ], $invoices),
            'subject' => mb_substr($subject, 0, 200),
            'status' => $status,
            'error' => $error,
            'sent_by' => $user->id,
            'sent_at' => now(),
        ]);
    }

    private function publicError(\Throwable $e): string
    {
        if ($e instanceof DistruException || $e instanceof RuntimeException) {
            $message = $e->getMessage();
            if (! str_contains($message, '://') && ! str_contains(strtolower($message), 'password')) {
                return mb_substr($message, 0, 300);
            }
        }

        return 'The email could not be sent.';
    }

    private function fromAddress(): string
    {
        $address = trim((string) config('bookkeeping.mail.from_address'));

        return $address !== '' ? $address : 'billing@powerbrandsny.com';
    }

    private function ensurePayments(BookkeepingWeek $week): void
    {
        abort_unless($week->paymentsRecorded(), 403, 'Record payments in Distru before this step.');
    }

    /** @return array<int, string> */
    private function sendIds(BookkeepingWeek $week): array
    {
        $ids = session($this->listKey($week), []);
        if (! is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
                $out[$id] = $id;
            }
        }

        return array_values($out);
    }

    private function listKey(BookkeepingWeek $week): string
    {
        return 'bk.send.'.$week->week_start->toDateString();
    }

    private function week(Request $request): BookkeepingWeek
    {
        $date = $request->input('week');

        return BookkeepingWeek::containing(is_string($date) ? $date : null);
    }

    private function back(BookkeepingWeek $week): RedirectResponse
    {
        return redirect()->route('bookkeeping.index', ['week' => $week->week_start->toDateString()]);
    }
}
