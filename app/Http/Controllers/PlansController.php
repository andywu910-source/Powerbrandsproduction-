<?php

namespace App\Http\Controllers;

use App\Exceptions\GateFailed;
use App\Models\Ingredient;
use App\Models\Mmp;
use App\Models\MmpLine;
use App\Models\MmpStep;
use App\Models\Pqp;
use App\Models\ProcessTemplate;
use App\Models\Product;
use App\Models\User;
use App\Services\DriveOutbox;
use App\Services\PublicId;
use App\Services\SignatureService;
use App\Support\MixSpec;
use App\Support\Units;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Product Quality Plans and Master Manufacturing Protocols (recipes), with QC approval. */
class PlansController extends Controller
{
    public function __construct(private SignatureService $sig) {}

    public function index()
    {
        return view('plans.index', [
            'pqps' => Pqp::orderBy('product_type')->orderByDesc('version')->get(),
            'mmps' => Mmp::with('product.brand')->orderByDesc('id')->limit(200)->get(),
            'products' => Product::where('active', true)->orderBy('name')->get(),
        ]);
    }

    // ---- PQP
    public function storePqp(Request $r)
    {
        $d = $r->validate(['product_type' => ['required', Rule::in(array_keys(Product::FAMILIES))], 'title' => ['required', 'string', 'max:255'],
            'effective_date' => ['nullable', 'date'], 'body' => ['nullable', 'string']]);
        $v = (int) Pqp::where('product_type', $d['product_type'])->max('version') + 1;
        $p = Pqp::create($d + ['public_id' => PublicId::next('PQP'), 'version' => $v, 'status' => 'draft', 'recorded_by_user_id' => $r->user()->id]);

        return redirect()->route('plans.pqp', $p)->with('status', 'PQP draft created.');
    }

    public function showPqp(Pqp $pqp)
    {
        $pqp->load('signatures.user', 'revisions.correctedBy', 'attachments');

        return view('plans.pqp', ['p' => $pqp, 'signers' => User::signers()]);
    }

    public function updatePqp(Request $r, Pqp $pqp)
    {
        if ($pqp->status !== 'draft') {
            throw GateFailed::because('An approved PQP cannot be edited. Use a correction, or make a new version.');
        }
        $pqp->update($r->validate(['title' => ['required', 'string', 'max:255'], 'effective_date' => ['nullable', 'date'], 'body' => ['nullable', 'string']]));

        return $this->ok('Saved.');
    }

    public function approvePqp(Request $r, Pqp $pqp)
    {
        if ($pqp->status !== 'draft') {
            throw GateFailed::because('Only a draft can be approved.');
        }
        DB::transaction(function () use ($r, $pqp) {
            $this->sig->sign($pqp, 'approved', $this->signer($r), $r->user(), ['qc' => true]);
            Pqp::where('product_type', $pqp->product_type)->where('status', 'approved')->update(['status' => 'superseded']);
            $pqp->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->signer($r)]);
            DriveOutbox::enqueue($pqp, 'approved', [$pqp->public_id, $pqp->version, $pqp->title]);
        });

        return $this->ok('PQP approved.');
    }

    // ---- MMP
    public function storeMmp(Request $r)
    {
        $d = $r->validate(['product_id' => ['required', 'exists:products,id'], 'basis_units' => ['required', 'integer', 'min:1']]);
        $product = Product::findOrFail($d['product_id']);
        $template = ProcessTemplate::forFamily($product->family);
        if (! $template) {
            throw GateFailed::because("Set the product family for {$product->name} first (Setup → Products).");
        }
        $mmp = DB::transaction(function () use ($product, $d, $template, $r) {
            $v = (int) Mmp::where('product_id', $product->id)->max('version') + 1;
            $mmp = Mmp::create(['public_id' => PublicId::next('MMP'), 'product_id' => $product->id, 'version' => $v, 'title' => $product->name.' recipe',
                'basis_units' => $d['basis_units'], 'status' => 'draft', 'hardware_sku' => $product->hardware_sku, 'recorded_by_user_id' => $r->user()->id,
                'pqp_id' => Pqp::where('product_type', $product->family)->where('status', 'approved')->value('id')]);
            $n = 1;
            foreach ($template->activeStages as $stage) {
                foreach ((array) $stage->default_steps as $s) {
                    MmpStep::create(['mmp_id' => $mmp->id, 'step_no' => $n++, 'stage_code' => $stage->code, 'instruction' => $s['instruction'],
                        'parameter' => $s['parameter'] ?? null, 'unit' => $s['unit'] ?? null, 'limit_min' => $s['limit_min'] ?? null, 'limit_max' => $s['limit_max'] ?? null, 'requires_verifier' => true]);
                }
            }

            return $mmp;
        });

        return redirect()->route('plans.mmp', $mmp)->with('status', 'Recipe draft created with the '.$template->name.' steps. Fill in the ingredients and limits.');
    }

    public function showMmp(Mmp $mmp)
    {
        $mmp->load('product.brand', 'lines.ingredient', 'steps', 'pqp', 'signatures.user');
        $template = ProcessTemplate::forFamily($mmp->product->family);

        return view('plans.mmp', ['m' => $mmp, 'ingredients' => Ingredient::where('active', true)->orderBy('name')->get(), 'signers' => User::signers(),
            'pqps' => Pqp::where('status', 'approved')->get(), 'stages' => $template?->activeStages()->get() ?? collect(),
            'mixPreview' => $mmp->mix_spec ? $this->mixPreview($mmp->mix_spec) : null]);
    }

    private function mixPreview(string $spec): string
    {
        try {
            return implode(' / ', array_map(fn ($f) => rtrim(rtrim(number_format($f * 100, 2), '0'), '.').'%', MixSpec::parse($spec)));
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    public function updateMmp(Request $r, Mmp $mmp)
    {
        if (! $mmp->isEditable()) {
            throw GateFailed::because('An approved recipe cannot be edited. Make a new version.');
        }
        $d = $r->validate([
            'title' => ['required', 'string', 'max:255'], 'pqp_id' => ['nullable', 'exists:pqps,id'], 'basis_units' => ['required', 'integer', 'min:1'],
            'unit_fill_mass_g' => ['nullable', 'numeric', 'gt:0'], 'potency_basis' => ['required', 'in:pct,mg'],
            'target_potency' => ['nullable', 'numeric', 'min:0'], 'potency_min' => ['nullable', 'numeric', 'min:0'], 'potency_max' => ['nullable', 'numeric', 'min:0'],
            'yield_expected_pct' => ['required', 'numeric'], 'yield_min_pct' => ['required', 'numeric'], 'yield_max_pct' => ['required', 'numeric'],
            'infusion_type' => ['nullable', Rule::in(array_keys(Mmp::INFUSION_TYPES))], 'infusion_pct' => ['nullable', 'string', 'max:20'],
            'terp_brand' => ['nullable', 'string', 'max:100'], 'terp_flavor' => ['nullable', 'string', 'max:100'],
            'mix_type' => ['nullable', 'string', 'max:40'], 'mix_spec' => ['nullable', 'string', 'max:60'], 'hardware_sku' => ['nullable', 'string', 'max:100'],
            'packaging_desc' => ['nullable', 'string'], 'label_version' => ['nullable', 'string', 'max:100'],
            'lines' => ['nullable', 'array'], 'steps' => ['nullable', 'array'],
        ]);
        DB::transaction(function () use ($mmp, $d, $r) {
            $header = collect($d)->except(['lines', 'steps', 'infusion_pct'])->all();
            $header['infusion_pct'] = filled($d['infusion_pct'] ?? null) ? $this->pct($d['infusion_pct']) : null;
            if (filled($d['mix_spec'] ?? null)) {
                try {
                    MixSpec::parse($d['mix_spec']);
                } catch (\InvalidArgumentException $e) {
                    throw GateFailed::because('Mix: '.$e->getMessage());
                }
            }
            $mmp->update($header);
            $fill = $mmp->basis_units * (float) $mmp->unit_fill_mass_g;
            $mixParts = filled($d['mix_spec'] ?? null) && $r->boolean('apply_mix') ? MixSpec::parse($d['mix_spec']) : null;
            $massIdx = 0;

            // Lines: replace the draft's lines (drafts only; approved recipes are frozen).
            $keep = [];
            foreach ((array) ($d['lines'] ?? []) as $row) {
                if (empty($row['ingredient_id'])) {
                    continue;
                }
                $ing = Ingredient::findOrFail($row['ingredient_id']);
                $qtyIn = trim((string) ($row['qty'] ?? ''));
                $unit = Units::normalizeUnit($row['unit'] ?? $ing->unit);
                $mode = $row['scale_mode'] ?? 'by_mass';
                if ($mixParts !== null && in_array($mode, ['by_mass', 'potency_adjusted'], true) && isset($mixParts[$massIdx]) && $fill > 0) {
                    $qty = round($mixParts[$massIdx++] * $fill, 4);
                    $unit = 'g';
                } elseif (str_ends_with($qtyIn, '%')) {
                    if ($fill <= 0) {
                        throw GateFailed::because('Enter the fill per unit before using % amounts.');
                    }
                    $qty = round(MixSpec::percent($qtyIn) / 100 * $fill, 4);
                    $unit = 'g';
                } elseif ($qtyIn !== '' && is_numeric($qtyIn)) {
                    [$qty, $unit] = Units::toBase((float) $qtyIn, $unit);
                } elseif ($mode === 'potency_adjusted' || $mode === 'potency_balancer') {
                    $qty = 0;
                    [, $unit] = Units::toBase(0, $unit);
                } else {
                    throw GateFailed::because("Enter an amount for {$ing->name} (a number, or a % like 5%).");
                }
                $attrs = ['mmp_id' => $mmp->id, 'line_no' => count($keep) + 1, 'ingredient_id' => $ing->id, 'line_type' => $row['line_type'] ?? $ing->kind,
                    'qty_per_basis' => $qty, 'unit' => $unit, 'scale_mode' => $mode,
                    'tolerance_pct' => isset($row['tolerance_pct']) && $row['tolerance_pct'] !== '' ? (float) $row['tolerance_pct'] : null,
                    'overage_pct' => (float) ($row['overage_pct'] ?? 0)];
                $line = ! empty($row['id']) ? MmpLine::where('mmp_id', $mmp->id)->find($row['id']) : null;
                $line ? $line->update($attrs) : $line = MmpLine::create($attrs);
                $keep[] = $line->id;
            }
            // Lines removed from a draft are detached (moved off the recipe), never deleted.
            MmpLine::where('mmp_id', $mmp->id)->whereNotIn('id', $keep ?: [0])->get()->each(fn ($l) => $l->update(['line_no' => 0, 'qty_per_basis' => 0, 'line_type' => 'removed']));

            $keepSteps = [];
            foreach ((array) ($d['steps'] ?? []) as $row) {
                if (trim((string) ($row['instruction'] ?? '')) === '') {
                    continue;
                }
                $attrs = ['mmp_id' => $mmp->id, 'step_no' => count($keepSteps) + 1, 'stage_code' => $row['stage_code'] ?? null, 'instruction' => trim($row['instruction']),
                    'parameter' => $row['parameter'] ?? null, 'unit' => $row['unit'] ?? null,
                    'limit_min' => ($row['limit_min'] ?? '') !== '' ? (float) $row['limit_min'] : null, 'limit_max' => ($row['limit_max'] ?? '') !== '' ? (float) $row['limit_max'] : null,
                    'requires_verifier' => ! empty($row['requires_verifier'])];
                $s = ! empty($row['id']) ? MmpStep::where('mmp_id', $mmp->id)->find($row['id']) : null;
                $s ? $s->update($attrs) : $s = MmpStep::create($attrs);
                $keepSteps[] = $s->id;
            }
            MmpStep::where('mmp_id', $mmp->id)->whereNotIn('id', $keepSteps ?: [0])->get()->each(fn ($s) => $s->update(['step_no' => 0, 'stage_code' => 'removed']));
        });

        return $this->ok('Recipe saved.');
    }

    private function pct(string $s): float
    {
        try {
            return MixSpec::percent($s);
        } catch (\InvalidArgumentException $e) {
            throw GateFailed::because('Infusion %: '.$e->getMessage());
        }
    }

    public function approveMmp(Request $r, Mmp $mmp)
    {
        if ($mmp->status !== 'draft') {
            throw GateFailed::because('Only a draft can be approved.');
        }
        $mmp->load('pqp', 'lines', 'steps', 'product');
        $p = [];
        if (! $mmp->pqp || $mmp->pqp->status !== 'approved') $p[] = 'Link an approved Product Quality Plan (PQP) first.';
        if ($mmp->lines->where('line_no', '>', 0)->isEmpty()) $p[] = 'Add at least one ingredient.';
        if ($mmp->steps->where('step_no', '>', 0)->isEmpty()) $p[] = 'Add the manufacturing steps.';
        if ($p) {
            throw new GateFailed($p);
        }
        DB::transaction(function () use ($r, $mmp) {
            $this->sig->sign($mmp, 'approved', $this->signer($r), $r->user(), ['qc' => true]);
            Mmp::where('product_id', $mmp->product_id)->where('status', 'approved')->update(['status' => 'superseded']);
            $mmp->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->signer($r)]);
            DriveOutbox::enqueue($mmp, 'approved', [$mmp->public_id, $mmp->version, $mmp->product->name]);
        });

        return $this->ok('Recipe approved. Batches can now be started for this product.');
    }

    public function newVersion(Request $r, Mmp $mmp)
    {
        $new = DB::transaction(function () use ($mmp, $r) {
            $v = (int) Mmp::where('product_id', $mmp->product_id)->max('version') + 1;
            $copy = $mmp->replicate(['public_id', 'version', 'status', 'approved_at', 'approved_by', 'filing_status', 'retention_until', 'void_reason', 'voided_by', 'voided_at']);
            $copy->fill(['public_id' => PublicId::next('MMP'), 'version' => $v, 'status' => 'draft', 'recorded_by_user_id' => $r->user()->id])->save();
            foreach ($mmp->lines()->where('line_no', '>', 0)->get() as $l) {
                MmpLine::create(collect($l->getAttributes())->except(['id', 'mmp_id', 'created_at', 'updated_at'])->all() + ['mmp_id' => $copy->id]);
            }
            foreach ($mmp->steps()->where('step_no', '>', 0)->get() as $s) {
                MmpStep::create(collect($s->getAttributes())->except(['id', 'mmp_id', 'created_at', 'updated_at'])->all() + ['mmp_id' => $copy->id]);
            }

            return $copy;
        });

        return redirect()->route('plans.mmp', $new)->with('status', "Draft version {$new->version} created. The approved version stays in use until this one is approved.");
    }
}
