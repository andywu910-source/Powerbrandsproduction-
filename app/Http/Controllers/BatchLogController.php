<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Product;
use App\Services\Audit;
use App\Services\BatchSummaryService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BatchLogController extends Controller
{
    public function index(Request $r)
    {
        $tz = config('pbp.site_timezone');
        $status = (string) $r->input('status', 'all');
        $q = Batch::with('product.brand')->orderByRaw('COALESCE(completed_at, started_at, created_at) DESC')->orderByDesc('id');
        match (true) {
            $status === 'void' => $q->where('status', 'void'),
            $status === 'everything' => null,
            isset(Batch::LOG_STATUSES[$status]) => $q->where('status', '!=', 'void')->where('log_status', $status),
            default => $q->where('status', '!=', 'void'),
        };
        if ($p = $r->integer('product_id')) $q->where('product_id', $p);
        if ($from = $r->input('from')) $q->where(fn ($w) => $w->where('completed_at', '>=', Carbon::parse($from, $tz)->startOfDay()->utc())->orWhere(fn ($x) => $x->whereNull('completed_at')->where('started_at', '>=', Carbon::parse($from, $tz)->startOfDay()->utc())));
        if ($to = $r->input('to')) $q->where(fn ($w) => $w->where('completed_at', '<=', Carbon::parse($to, $tz)->endOfDay()->utc())->orWhere(fn ($x) => $x->whereNull('completed_at')->where('started_at', '<=', Carbon::parse($to, $tz)->endOfDay()->utc())));
        if ($t = trim((string) $r->input('q'))) {
            $q->where(function ($w) use ($t) {
                $w->where('batch_number', 'like', "%$t%")->orWhere('public_id', 'like', "%$t%")->orWhere('output_metrc_uid', 'like', "%$t%")->orWhere('barcode_number', 'like', "%$t%")
                    ->orWhereIn('id', \App\Models\BatchMaterialLine::where('metrc_package_uid', 'like', "%$t%")->pluck('batch_id'));
            });
        }

        return view('batchlog.index', ['batches' => $q->paginate(25)->withQueryString(), 'products' => Product::whereIn('id', Batch::select('product_id'))->orderBy('name')->get(), 'status' => $status]);
    }

    public function show(Batch $batch, BatchSummaryService $svc)
    {
        return view('batchlog.show', ['b' => $batch, 's' => $svc->build($batch)]);
    }

    /** Batch Log status (In production / Packaging/COA labeling / Finished). Anyone who can edit batch records; audited. */
    public function logStatus(Request $r, Batch $batch)
    {
        abort_if($r->user()->isViewer(), 403);
        $d = $r->validate(['log_status' => ['required', 'in:'.implode(',', array_keys(Batch::LOG_STATUSES))]], ['log_status.in' => 'Pick Incoming, In production, In lab, Packaging/COA labeling or Finished.']);
        if ($batch->status === 'void') {
            return back()->withErrors(['log_status' => "Batch {$batch->batch_number} is void; its status cannot change."]);
        }
        if ($batch->isIncoming() && $d['log_status'] !== 'incoming') {
            return back()->withErrors(['log_status' => "{$batch->batch_number} is Incoming (not started). Use \"Start this job\": it runs the start checks and moves it to In production."]);
        }
        if (! $batch->isIncoming() && $d['log_status'] === 'incoming') {
            return back()->withErrors(['log_status' => "{$batch->batch_number} has already started, so it can't go back to Incoming."]);
        }
        $old = $batch->logStatus();
        if ($old === $d['log_status'] && $batch->log_status === $old) {
            return back()->with('status', "{$batch->batch_number} is already ".Batch::LOG_STATUSES[$old].'.');
        }
        $batch->update(['log_status' => $d['log_status']]);   // Audited: update row with old and new
        Audit::log('batch_log_status', $batch, ['batch_number' => $batch->batch_number, 'old' => $old, 'new' => $d['log_status'],
            'old_label' => Batch::LOG_STATUSES[$old], 'new_label' => Batch::LOG_STATUSES[$d['log_status']]]);
        if ($r->expectsJson()) {
            return response()->json(['ok' => true, 'batch_number' => $batch->batch_number, 'log_status' => $d['log_status'], 'label' => Batch::LOG_STATUSES[$d['log_status']]]);
        }

        return back()->with('status', "{$batch->batch_number}: ".Batch::LOG_STATUSES[$old].' → '.Batch::LOG_STATUSES[$d['log_status']].'.');
    }

    /**
     * Batch log sign-off: "Batch log signed off by". Anyone who can write (staff included) signs in their own name;
     * the sign-off is immutable (an admin can void it with a reason). One live sign-off per person per batch.
     */
    public function signoff(Request $r, Batch $batch, \App\Services\SignatureService $sig)
    {
        abort_if($r->user()->isViewer(), 403);
        $r->validate(['signer_id' => ['required', 'integer']], ['signer_id.required' => 'Pick your name to sign off.']);
        if ($batch->status === 'void') {
            return back()->withErrors(['signer_id' => "Batch {$batch->batch_number} is void; it cannot be signed off."]);
        }
        if ($batch->isIncoming()) {
            return back()->withErrors(['signer_id' => "{$batch->batch_number} has not started yet (Incoming), so there is no batch log to sign off."]);
        }
        $signerId = (int) $r->input('signer_id');
        if ($batch->signatures()->where('meaning', 'log_signoff')->where('user_id', $signerId)->whereNull('invalidated_at')->exists()) {
            return back()->with('status', 'That person has already signed off this batch log.');
        }
        $s = $sig->sign($batch, 'log_signoff', $signerId, $r->user());

        return redirect()->to(route('batch-log.show', $batch).'#signoff')->with('status', "Batch log signed off by {$s->signerName()}.");
    }

    /** Barcode number printed on case labels. Admins and supervisors only; the change is in the audit log (old and new). */
    public function barcode(Request $r, Batch $batch)
    {
        abort_unless($r->user()->hasRole(\App\Models\User::ROLE_ADMIN, \App\Models\User::ROLE_SUPERVISOR), 403);
        $r->merge(['barcode_number' => strtoupper(preg_replace('/\s+/', '', (string) $r->input('barcode_number')))]);
        $r->validate(['barcode_number' => ['nullable', 'string', 'max:48', 'regex:/^[\x21-\x7E]+$/']],
            ['barcode_number.regex' => 'The barcode number can only use letters, numbers and symbols (no spaces).']);
        $new = $r->input('barcode_number') ?: null;
        if ($new === $batch->barcode_number) {
            return back()->with('status', 'Barcode number unchanged.');
        }
        $batch->update(['barcode_number' => $new]);   // Audited: logs old and new values

        return redirect()->route('batch-log.show', $batch)->with('status', $new ? "Barcode number saved for {$batch->batch_number}." : "Barcode number removed from {$batch->batch_number}.");
    }

    /** Expiration date (sets "Retain until" = expiration + 30 days). Admins and supervisors; audited (old and new). */
    public function expiration(Request $r, Batch $batch)
    {
        abort_unless($r->user()->hasRole(\App\Models\User::ROLE_ADMIN, \App\Models\User::ROLE_SUPERVISOR), 403);
        $d = $r->validate(['expiration_date' => ['nullable', 'date', 'after_or_equal:2020-01-01', 'before:2100-01-01']]);
        $new = ($d['expiration_date'] ?? null) ?: null;
        if ($new === $batch->expiration_date?->toDateString()) {
            return redirect()->route('batch-log.show', $batch)->with('status', 'Expiration date unchanged.');
        }
        $batch->update(['expiration_date' => $new]);   // Audited: old and new

        return redirect()->to(route('batch-log.show', $batch).'#retention')->with('status', $new ? "Expiration date saved. Keep the retention samples until ".\App\Support\Fmt::date($batch->fresh()->retainUntil())."." : 'Expiration date removed.');
    }

    public function print(Batch $batch, BatchSummaryService $svc)
    {
        Audit::log('export', $batch, ['format' => 'print']);

        return view('batchlog.print', ['s' => $svc->build($batch), 'generatedBy' => auth()->user()->name, 'generatedAt' => now()->timezone(config('pbp.site_timezone'))->format('M j, Y g:i A T'), 'pdf' => false]);
    }

    public function pdf(Batch $batch, BatchSummaryService $svc)
    {
        $s = $svc->build($batch);
        $html = view('batchlog.print', ['s' => $s, 'generatedBy' => auth()->user()->name, 'generatedAt' => now()->timezone(config('pbp.site_timezone'))->format('M j, Y g:i A T'), 'pdf' => true])->render();
        $opt = new Options;
        $opt->set('isRemoteEnabled', false);
        $opt->set('fontCache', storage_path('framework/cache'));
        $opt->set('tempDir', storage_path('framework/cache'));
        $opt->set('chroot', base_path());
        $pdf = new Dompdf($opt);
        $pdf->loadHtml($html);
        $pdf->setPaper('letter');
        $pdf->render();
        $out = $pdf->output();
        Audit::log('export', $batch, ['format' => 'pdf', 'sha256' => hash('sha256', $out), 'data_hash' => $s['hash']]);

        return response($out, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="Batch_'.$batch->batch_number.'_v1.pdf"']);
    }
}
