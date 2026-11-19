<?php

namespace App\Http\Controllers;

use App\Exceptions\GateFailed;
use App\Models\Batch;
use App\Models\Equipment;
use App\Models\LogDefinition;
use App\Models\LogEntry;
use App\Models\LogEntryLink;
use App\Models\Person;
use App\Models\Room;
use App\Models\User;
use App\Services\SignatureService;
use App\Support\AttachmentStore;
use App\Support\LogHub;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Compliance hub: every OCM / GMP log in one place, with due status, entry forms and related entries. */
class LogHubController extends Controller
{
    private function definition(Request $request, LogDefinition $log): LogDefinition
    {
        abort_unless($log->visibleTo($request->user()), 404);

        return $log;
    }

    /** Retired logs (Nov 19): admins / supervisors can still open their past entries, read-only. */
    private function readable(Request $request, LogDefinition $log): LogDefinition
    {
        if (! $log->active && $request->user()?->isSupervisorOrAdmin()) {
            return $log;
        }

        return $this->definition($request, $log);
    }

    public function index(Request $request)
    {
        $u = $request->user();
        $board = LogHub::board(LogHub::visible($u));
        $due = in_array($request->query('due'), ['overdue', 'today', 'week'], true) ? $request->query('due') : 'all';
        $findings = $board['rows']->map(fn ($r) => $r['def']->finding ?? '')->unique()
            ->sortBy(fn ($f) => $f === '' ? 999 : ($f === 'T-1' ? 998 : (int) $f))->values();

        $pending = LogEntry::pendingVerification()->whereIn('log_definition_id', LogHub::visible($u)->pluck('id'))->count();

        return view('logs.hub', [
            'pending' => $pending,
            'canVerify' => $u->isAdmin(),
            'groups' => collect(config('pbp_logs.groups'))->map(fn ($label, $key) => [
                'key' => $key, 'label' => $label, 'managers' => in_array($key, (array) config('pbp_logs.manager_groups'), true),
                'rows' => $board['rows']->filter(fn ($r) => $r['def']->group_key === $key)->values(),
            ])->filter(fn ($g) => $g['rows']->isNotEmpty())->values(),
            'counts' => $board['counts'],
            'due' => $due,
            'q' => (string) $request->query('q', ''),
            'finding' => (string) $request->query('finding', ''),
            'findings' => $findings,
            'today' => LogHub::today(),
        ]);
    }

    public function show(Request $request, LogDefinition $log)
    {
        $log = $this->readable($request, $log);
        $retired = ! $log->active;
        $entries = LogEntry::with(['enteredBy', 'signatures.user'])->where('log_definition_id', $log->id)
            ->orderByDesc('occurred_on')->orderByDesc('id')->limit(25)->get();
        $selected = $request->query('entry') ? $entries->firstWhere('id', (int) $request->query('entry'))
            ?? LogEntry::where('log_definition_id', $log->id)->find((int) $request->query('entry')) : $entries->firstWhere('voided_at', null);
        $selected?->load('links', 'signatures.user', 'attachments');

        return view('logs.show', [
            'log' => $log,
            'status' => $retired ? ['state' => 'as_needed', 'label' => 'Retired'] : LogHub::status($log),
            'entries' => $entries,
            'selected' => $selected,
            'related' => $selected ? LogHub::related($selected, $request->user()) : collect(),
            'lists' => $this->lists($log),
            'signers' => User::signers(),
            'retired' => $retired,
            'retiredWhy' => $retired ? collect(config('pbp_logs.logs'))->firstWhere('code', $log->code)['retired'] ?? null : null,
            'canWrite' => ! $retired && ! $request->user()->isViewer(),
            'canVoid' => ! $retired && $request->user()->isSupervisorOrAdmin(),
            'canVerify' => ! $retired && $request->user()->isAdmin(),
            'signMode' => (string) \App\Models\Setting::get('signing_auth_mode', 'name'),
        ]);
    }

    /** Every entry waiting for admin verification, across all logs this user can see, newest first. */
    public function queue(Request $request)
    {
        $u = $request->user();
        $entries = LogEntry::with(['definition', 'enteredBy'])->pendingVerification()
            ->whereIn('log_definition_id', LogHub::visible($u)->pluck('id'))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(100);

        return view('logs.queue', ['entries' => $entries, 'canVerify' => $u->isAdmin(), 'me' => $u->id]);
    }

    /** Admin e-signature: the entry is verified. Never by the person who entered it. */
    public function verify(Request $request, LogEntry $entry, SignatureService $sign)
    {
        $u = $request->user();
        abort_unless($u->isAdmin(), 403);
        $log = $this->definition($request, $entry->definition);
        DB::transaction(function () use ($entry, $u, $sign) {
            $e = LogEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if (! $e->isPendingVerification()) {
                throw GateFailed::because($e->voided_at ? 'This entry is void, so it cannot be verified.' : 'This entry has already been verified.');
            }
            $sig = $sign->sign($e, 'verified', $u, $u, [
                'roles' => [User::ROLE_ADMIN],
                'differs_from' => [(int) $e->entered_by_user_id],
                'differs_message' => 'You entered this entry, so another admin has to verify it.',
            ]);
            $e->forceFill(['verification_status' => LogEntry::VERIFIED, 'verified_by_user_id' => $u->id,
                'verified_at' => $sig->signed_at ?? now(), 'verification_signature_id' => $sig->id])->save();
            \App\Services\Audit::log('verify', $e, ['signature_id' => $sig->id, 'entered_by' => $e->entered_by_user_id]);
        });

        return $this->back($request, $log, $entry, 'Entry verified.');
    }

    /** Admin sends an entry back with a reason: it is voided (so it no longer counts) and marked Returned. */
    public function sendBack(Request $request, LogEntry $entry)
    {
        $u = $request->user();
        abort_unless($u->isAdmin(), 403);
        $log = $this->definition($request, $entry->definition);
        $reason = trim((string) $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason']);
        DB::transaction(function () use ($entry, $u, $reason) {
            $e = LogEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if (! $e->isPendingVerification()) {
                throw GateFailed::because($e->voided_at ? 'This entry is already void.' : 'This entry has already been verified.');
            }
            if ((int) $e->entered_by_user_id === $u->id) {
                throw GateFailed::because('You entered this entry, so another admin has to review it. To withdraw it, void it instead.');
            }
            $e->verification_status = LogEntry::RETURNED;
            $e->void('Returned by '.$u->name.': '.$reason, $u);
            \App\Services\Audit::log('return', $e, ['reason' => $reason, 'entered_by' => $e->entered_by_user_id]);
        });

        return $this->back($request, $log, $entry, 'Entry sent back. The person who entered it can record a corrected entry.');
    }

    private function back(Request $request, LogDefinition $log, LogEntry $entry, string $msg)
    {
        return $request->input('from') === 'queue'
            ? redirect()->route('logs.queue')->with('status', $msg)
            : redirect()->route('logs.show', [$log, 'entry' => $entry->id])->with('status', $msg);
    }

    public function store(Request $request, LogDefinition $log, SignatureService $sign)
    {
        $log = $this->definition($request, $log);
        $u = $request->user();
        // Metrc UIDs are compared without spaces, in capitals.
        $in = (array) $request->input('f', []);
        foreach (LogHub::formFields($log) as $f) {
            if ($f['type'] === 'metrc' && isset($in[$f['key']]) && is_string($in[$f['key']])) {
                $in[$f['key']] = LogHub::normaliseMetrc($in[$f['key']]);
            }
            if ($f['type'] === 'bool') {
                $in[$f['key']] = ! empty($in[$f['key']]);
            }
        }
        $request->merge(['f' => $in]);
        $valid = $request->validate(LogHub::rules($log), LogHub::messages($log), LogHub::attributes($log))['f'] ?? [];

        $entry = DB::transaction(function () use ($log, $valid, $request, $u, $sign) {
            $data = [];
            $occurred = null;
            foreach (LogHub::formFields($log) as $f) {
                $v = $valid[$f['key']] ?? null;
                if (in_array($f['type'], ['signature', 'file'], true)) {
                    continue;   // filled in after the entry exists
                }
                if (is_string($v)) {
                    $v = trim($v);
                    $v = $v === '' ? null : $v;
                }
                $data[$f['key']] = $v;
                if ($occurred === null && $v && in_array($f['type'], ['date', 'datetime'], true)) {
                    $occurred = CarbonImmutable::parse($v)->toDateString();
                }
            }
            $entry = LogEntry::create([
                'log_definition_id' => $log->id, 'data' => $data,
                'occurred_on' => $occurred ?? LogHub::today()->toDateString(), 'entered_by_user_id' => $u->id,
            ]);

            $signedBy = [];
            foreach (LogHub::formFields($log) as $f) {
                if ($f['type'] === 'signature' && ! empty($valid[$f['key']])) {
                    $rules = [];
                    if (! empty($f['differs_from']) && isset($signedBy[$f['differs_from']])) {
                        $rules = ['differs_from' => [$signedBy[$f['differs_from']]], 'differs_message' => "{$f['label']} must be a different person."];
                    }
                    $sig = $sign->sign($entry, $f['meaning'] ?? 'performed', (int) $valid[$f['key']], $u, $rules);
                    $signedBy[$f['key']] = $sig->user_id;
                    $data[$f['key']] = ['user_id' => $sig->user_id, 'name' => $sig->user?->name ?? User::find($sig->user_id)?->name, 'signature_id' => $sig->id, 'at' => $sig->signed_at?->toIso8601String()];
                }
                if ($f['type'] === 'file' && $request->hasFile('f.'.$f['key'])) {
                    $a = AttachmentStore::store($entry, 'other', $request->file('f.'.$f['key']), $u);
                    $data[$f['key']] = ['attachment_id' => $a->id, 'filename' => $a->filename];
                }
            }
            if ($data !== $entry->data) {
                $entry->data = $data;
                $entry->save();
            }
            foreach (LogHub::extractLinks($log, $data) as $l) {
                LogEntryLink::create(['log_entry_id' => $entry->id] + $l);
            }

            return $entry;
        });

        return redirect()->route('logs.show', [$log, 'entry' => $entry->id])->with('status', "Saved to {$log->code} {$log->name}.");
    }

    public function void(Request $request, LogEntry $entry)
    {
        abort_unless($request->user()->isSupervisorOrAdmin(), 403);
        $log = $this->definition($request, $entry->definition);
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];
        $entry->void($reason, $request->user());

        return redirect()->route('logs.show', [$log, 'entry' => $entry->id])->with('status', 'Entry voided.');
    }

    /** Everything else sharing one link key (e.g. all entries for one Metrc UID). */
    public function related(Request $request)
    {
        $kind = (string) $request->query('kind');
        abort_unless(isset(LogEntryLink::KINDS[$kind]), 404);
        $value = LogHub::normaliseLink($kind, (string) $request->query('value', ''));
        $visible = LogHub::visible($request->user())->pluck('id')->all();
        $entries = LogEntry::with(['definition', 'enteredBy'])->whereNull('voided_at')->whereIn('log_definition_id', $visible)
            ->whereIn('id', LogEntryLink::where('kind', $kind)->where('value', $value)->select('log_entry_id'))
            ->orderByDesc('occurred_on')->orderByDesc('id')->limit(200)->get();

        return view('logs.related', compact('kind', 'value', 'entries'));
    }

    /** Suggestions for the room / equipment / employee / batch pickers. */
    private function lists(LogDefinition $log): array
    {
        $types = collect($log->fields)->pluck('type')->unique();

        return [
            'employee' => $types->contains('employee') ? Person::where('active', true)->orderBy('full_name')->pluck('full_name')
                ->merge(User::where('is_active', true)->orderBy('name')->pluck('name'))->unique()->values() : collect(),
            'room' => $types->contains('room') ? Room::where('active', true)->orderBy('room_no')->get(['room_no', 'name']) : collect(),
            'equipment' => $types->contains('equipment') ? Equipment::where('active', true)->orderBy('equip_id')->get(['equip_id', 'name']) : collect(),
            'batch' => $types->contains('batch') ? Batch::whereNotIn('status', ['void'])->orderByDesc('id')->limit(200)->pluck('batch_number') : collect(),
        ];
    }
}
