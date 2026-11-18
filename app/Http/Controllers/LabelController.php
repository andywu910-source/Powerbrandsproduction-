<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\LabelPrint;
use App\Models\Product;
use App\Services\LabelService;
use App\Support\Labels\CaseLabel;
use Illuminate\Http\Request;

/** Labels tab: case / carton labels (3 x 2 in) filled from the batch record. */
class LabelController extends Controller
{
    public function __construct(private LabelService $svc) {}

    public function index(Request $r, ?string $q = null, $hits = null)
    {
        return view('labels.index', ['q' => $q, 'hits' => $hits,
            'pickList' => collect(), 'batchCount' => Batch::where('status', '!=', 'void')->count(),
            'products' => Product::with('brand')->where('active', true)->orderBy('name')->get(),
            'prints' => LabelPrint::with('batch', 'product', 'printer')->orderByDesc('id')->limit(25)->get(),
        ]);
    }

    /** Batches for the type-to-search picker (newest first). */
    private function pickList()
    {
        return Batch::with('product.brand')->where('status', '!=', 'void')->orderByDesc('id')->limit(500)->get();
    }

    /** Batch from batch_id (what the picker sends), or an exact batch number (older links). */
    private function findBatch(Request $r): ?Batch
    {
        if ($r->filled('batch_id')) {
            return Batch::with('product.brand')->findOrFail((int) $r->input('batch_id'));
        }
        if ($r->filled('batch')) {
            $n = trim((string) $r->input('batch'));

            return Batch::with('product.brand')->where('batch_number', $n)->first();
        }

        return null;
    }

    private function source(Request $r): array
    {
        $batch = $this->findBatch($r);
        abort_if(! $batch && $r->filled('batch') && ! $r->filled('product'), 404);
        $product = ! $batch && $r->filled('product') ? Product::with('brand')->findOrFail((int) $r->input('product')) : null;

        return [$batch, $product];
    }

    /** The editor's layout (JSON string or array); null = standard layout. */
    private function layoutInput(Request $r): ?array
    {
        $v = $r->input('layout');
        if (is_string($v)) {
            $v = trim($v) === '' ? null : json_decode($v, true);
        }

        return is_array($v) && ! empty($v['lines']) && is_array($v['lines']) ? $v : null;
    }

    public function create(Request $r)
    {
        $from = $r->filled('from') ? LabelPrint::findOrFail((int) $r->input('from')) : null;
        if ($from) {
            $r->merge(['batch_id' => $from->batch_id, 'product' => $from->product_id]);
        }
        // Typed text that is not a batch number (e.g. a product name): search instead of 404.
        $q = trim((string) $r->input('q', $r->input('batch', '')));
        if (! $from && ! $r->filled('batch_id') && ! $r->filled('product') && ($q !== '' || $r->has('q'))) {
            // The picker's option text is "BATCH · product": the batch number is the part before " · ".
            $num = str_contains($q, ' · ') ? trim(explode(' · ', $q, 2)[0]) : $q;
            $exact = $q === '' ? null : (Batch::where('batch_number', $q)->first() ?? Batch::where('batch_number', $num)->first());
            if (! $exact) {
                $hits = Batch::search($q);
                if ($hits->count() !== 1) {
                    return $this->index($r, $q, $hits);
                }
                $exact = $hits->first();
            }
            $r->merge(['batch_id' => $exact->id]);
            $r->request->remove('batch');
            $r->query->remove('batch');
        }
        [$batch, $product] = $this->source($r);
        abort_unless($batch || $product, 404);
        $fields = $this->svc->applyBarcode($from ? $this->svc->clean($from->fields) : $this->svc->defaults($batch, $product), $batch, $product);
        if (! $from && $r->input('type') === 'case') {
            $fields['label_type'] = 'case';
        }
        $fields['partial'] = false;
        // Edited layout: the reprinted job's own layout, else this product's saved template.
        $edit = $from?->layout_edited ? $from->layout : $this->svc->productTemplate($product ?? $batch?->product, $fields['label_type']);
        $source = $from?->layout_edited ? 'print' : ($edit ? 'template' : 'standard');
        try {
            $label = $this->svc->label($fields, $edit);
        } catch (\Throwable) {
            [$label, $edit, $source] = [$this->svc->label($fields), null, 'standard'];
        }
        $layout = $label->layout();

        return view('labels.form', ['pickList' => $this->pickList(), 'batch' => $batch, 'product' => $product ?? $batch?->product, 'f' => $fields, 'from' => $from,
            'svg' => $label->svg(), 'warnings' => $this->svc->warnings($fields, $batch, $layout), 'errors_now' => $this->svc->errors($fields, 1),
            'defaults' => $this->svc->defaults($batch, $product), 'missingBarcode' => LabelService::missingBarcode($fields),
            'partialSvg' => $this->partialSvg($fields, $edit), 'layoutJson' => $label->toLayout(), 'layoutEdited' => (bool) $edit, 'layoutSource' => $source,
            'templates' => [
                'master' => (bool) $this->svc->productTemplate($product ?? $batch?->product, 'master'),
                'case' => (bool) $this->svc->productTemplate($product ?? $batch?->product, 'case'),
            ],
            'canTemplate' => (bool) ($r->user()?->hasRole(\App\Models\User::ROLE_ADMIN, \App\Models\User::ROLE_SUPERVISOR) && ($product ?? $batch?->product))]);
    }

    /** Case box with a remainder: the partial case label, for the second preview. */
    private function partialSvg(array $f, ?array $layout = null): ?string
    {
        if (! LabelService::isCaseBox($f) || ! LabelService::caseBoxes($f)['remainder']) {
            return null;
        }
        try {
            return $this->svc->label(['partial' => true] + $f, $layout)->svg();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Batch picker search (JSON): batch number, product or brand. */
    public function batches(Request $r)
    {
        $q = trim((string) $r->input('q', ''));
        $total = Batch::where('status', '!=', 'void')->count();
        $items = $total ? Batch::search($q, 30)->map(fn (Batch $b) => [
            'id' => $b->id, 'batch_number' => $b->batch_number, 'product' => trim(($b->product?->brand?->name ?? '').' '.($b->product?->name ?? '')),
            'barcode' => $b->barcode_number, 'status' => $b->logStatusLabel(), 'url' => route('labels.create', ['batch_id' => $b->id]),
        ])->values() : collect();
        $msg = $total === 0 ? 'The Batch Log has no batches yet' : ($items->isEmpty() ? 'No batch matches "'.$q.'"' : null);

        return response()->json(['items' => $items, 'total_batches' => $total, 'q' => $q, 'message' => $msg]);
    }

    /** Live preview (no logging): SVG + warnings. */
    public function preview(Request $r)
    {
        [$batch, $product] = $this->source($r);
        $f = $this->svc->applyBarcode($this->svc->withCases($this->svc->clean(['partial' => false] + (array) $r->input('f', []))), $batch, $product);
        $errors = $this->svc->errors($f, max(1, (int) $r->input('copies', 1)));
        $edit = $this->layoutInput($r);
        if (! $edit && $r->boolean('use_template')) {
            $edit = $this->svc->productTemplate($product ?? $batch?->product, $f['label_type']);
        }
        try {
            $label = $this->svc->label($f, $edit);
            $layout = $label->layout();
            $svg = $label->svg();
        } catch (\Throwable) {
            $f['barcode_on'] = false;
            $bad = $f['barcode_value'];
            $f['barcode_value'] = '';
            $label = $this->svc->label($f, $edit);
            $f['barcode_value'] = $bad;
            $layout = $label->layout();
            $svg = $label->svg();
            $errors[] = 'This barcode number cannot be drawn as Code 128.';
        }

        if ($edit && ($layout['overlaps_barcode'] || ! $layout['fits'])) {
            $errors[] = $layout['overlaps_barcode'] ? 'A line overlaps the barcode. Move it so the barcode stays scannable.' : 'A line or the barcode runs off the edge of the label. Move it or make it smaller.';
        }
        $cb = LabelService::caseBoxes($f);

        return response()->json(['missing_barcode' => LabelService::missingBarcode($f), 'svg' => $svg, 'size' => $layout['size'],
            'label_type' => $f['label_type'], 'full' => $cb['full'], 'remainder' => $cb['remainder'], 'partial_svg' => $this->partialSvg($f, $edit), 'layout' => $label->toLayout(), 'edited' => (bool) $edit,
            'fits' => $layout['fits'], 'overlaps_barcode' => $layout['overlaps_barcode'], 'warnings' => $this->svc->warnings($f, $batch, $layout), 'errors' => array_values(array_unique($errors))]);
    }

    public function print(Request $r)
    {
        [$batch, $product] = $this->source($r);
        $in = (array) $r->input('f', []);
        // Case box: the "Print partial case label" button prints the remainder label with its own quantity.
        $partial = ($in['label_type'] ?? '') === 'case' && $r->input('which') === 'partial';
        $in['partial'] = $partial;
        $f = $this->svc->applyBarcode($this->svc->withCases($this->svc->clean($in)), $batch, $product);
        $copies = (int) $r->input($partial ? 'partial_copies' : 'copies', 1);
        [$lp, $pdf] = $this->svc->print($f, $copies, $batch, $product, $r->user(), $this->layoutInput($r));
        $name = (LabelService::isCaseBox($f) ? ($partial ? 'CaseBoxPartial_' : 'CaseBox_') : 'MasterBox_').preg_replace('/[^A-Za-z0-9\-]+/', '', $f['batch_number'] ?: 'nobatch').'_x'.$copies.'.pdf';

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$name.'"',
            'X-Label-Print' => $lp->public_id, 'Cache-Control' => 'no-store']);
    }

    /** "Save as template for this product" (admin / supervisor only). */
    public function saveTemplate(Request $r)
    {
        abort_unless($r->user()?->hasRole(\App\Models\User::ROLE_ADMIN, \App\Models\User::ROLE_SUPERVISOR), 403, 'Only an admin or a supervisor can save a label template.');
        [$batch, $product] = $this->source($r);
        $product ??= $batch?->product;
        abort_unless($product, 422, 'Pick a batch or product first.');
        $layout = $this->layoutInput($r);
        abort_unless($layout, 422, 'Nothing to save.');
        $type = ($r->input('label_type') === 'case') ? 'case' : 'master';
        $t = $this->svc->saveTemplate($product, $type, $layout, $r->user());

        return response()->json(['ok' => true, 'id' => $t->id, 'message' => 'Saved as the '.($type === 'case' ? 'Case box' : 'Master box').' template for '.$product->name.'.']);
    }
}
