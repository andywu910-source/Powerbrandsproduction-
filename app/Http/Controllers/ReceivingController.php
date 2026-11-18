<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\MaterialLot;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ReceivingService;
use App\Support\AttachmentStore;
use App\Support\Units;
use Illuminate\Http\Request;

class ReceivingController extends Controller
{
    public function __construct(private ReceivingService $svc) {}

    public function index(Request $r)
    {
        $q = MaterialLot::with('ingredient', 'supplier')->orderByDesc('id');
        if ($s = $r->input('status')) $q->where('status', $s);
        if ($i = $r->integer('ingredient_id')) $q->where('ingredient_id', $i);
        if ($t = trim((string) $r->input('q'))) {
            $q->where(fn ($w) => $w->where('public_id', 'like', "%$t%")->orWhere('supplier_lot', 'like', "%$t%")->orWhere('metrc_package_uid', 'like', "%$t%"));
        }

        return view('receiving.index', ['lots' => $q->paginate(30)->withQueryString(), 'ingredients' => Ingredient::orderBy('name')->get()]);
    }

    public function create()
    {
        return view('receiving.create', [
            'suppliers' => Supplier::where('active', true)->orderBy('name')->get(), 'ingredients' => Ingredient::where('active', true)->orderBy('name')->get(),
            'signers' => User::signers(), 'units' => Units::entryOptions(),
        ]);
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'received_at' => ['nullable', 'date'], 'supplier_id' => ['required', 'exists:suppliers,id'], 'ingredient_id' => ['required', 'exists:ingredients,id'],
            'supplier_lot' => ['nullable', 'string', 'max:100'], 'grade' => ['nullable', 'string', 'max:100'], 'qty' => ['required', 'numeric', 'gt:0'],
            'unit' => ['required', 'in:'.implode(',', array_keys(Units::ENTRY))], 'metrc_manifest_no' => ['nullable', 'string', 'max:100'],
            'metrc_package_uid' => ['nullable', 'string', 'max:100'], 'condition_ok' => ['nullable'], 'seals_ok' => ['nullable'],
            'condition_notes' => ['nullable', 'string', 'max:2000'], 'expiration_date' => ['nullable', 'date'], 'signer_id' => ['required', 'integer'],
        ]);
        $lot = $this->svc->receive($d, (int) $d['signer_id'], $r->user());

        return redirect()->route('receiving.show', $lot)->with('status', "Received as {$lot->public_id}. It is in quarantine until QC releases it.");
    }

    public function show(MaterialLot $lot)
    {
        $lot->load('ingredient', 'supplier', 'ledger.user', 'signatures.user', 'revisions.correctedBy', 'attachments.uploader', 'reservations');

        return view('receiving.show', ['lot' => $lot, 'signers' => User::signers(), 'qcReviewers' => User::signers()->filter->isQcReviewer(),
            'releaseProblems' => $lot->status === 'quarantine' ? $this->svc->releaseProblems($lot) : [], 'units' => Units::entryOptions()]);
    }

    public function verify(Request $r, MaterialLot $lot)
    {
        $this->svc->verifyReceipt($lot, $this->signer($r), $r->user());

        return $this->ok('Receipt verified.');
    }

    public function paperwork(Request $r, MaterialLot $lot)
    {
        $r->validate(['potency_pct' => ['nullable', 'numeric', 'min:0', 'max:100'], 'coa_result' => ['nullable', 'in:pass,fail']]);
        $d = $r->only(['potency_pct', 'coa_result']);
        foreach (['attestation_ok', 'source_records_ok', 'banned_ingredient_ok', 'inhaled_allowed_ok'] as $f) {
            $d[$f] = $r->boolean($f);
        }
        $this->svc->recordPaperwork($lot, $d, $this->signer($r), $r->user());

        return $this->ok('Paperwork checked and signed.');
    }

    public function upload(Request $r, MaterialLot $lot)
    {
        $r->validate(['kind' => ['required', 'in:coa,attestation,source_records,other'], 'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,heic,webp,xlsx,csv']]);
        AttachmentStore::store($lot, $r->input('kind'), $r->file('file'), $r->user());

        return $this->ok('File uploaded.');
    }

    public function release(Request $r, MaterialLot $lot)
    {
        $this->svc->release($lot, $this->signer($r), $r->user(), $r->input('note'));

        return $this->ok("Lot {$lot->public_id} released. Its quantity is now the opening balance.");
    }

    public function reject(Request $r, MaterialLot $lot)
    {
        $this->svc->reject($lot, $this->signer($r), $r->user(), (string) $r->input('note'));

        return $this->ok("Lot {$lot->public_id} rejected. It can never be used.");
    }

    public function adjust(Request $r, MaterialLot $lot)
    {
        $r->validate(['type' => ['required'], 'qty' => ['required', 'numeric', 'gt:0'], 'unit' => ['required'], 'reason' => ['required', 'string', 'max:1000']]);
        $this->svc->adjust($lot, $r->input('type'), (float) $r->input('qty'), $r->input('unit'), $r->input('reason'), $this->signer($r), $r->user());

        return $this->ok('Stock change recorded.');
    }
}
