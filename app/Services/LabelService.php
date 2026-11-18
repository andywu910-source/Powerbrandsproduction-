<?php

namespace App\Services;

use App\Exceptions\GateFailed;
use App\Models\Batch;
use App\Models\LabelPrint;
use App\Models\Product;
use App\Models\User;
use App\Support\Labels\CaseLabel;
use Illuminate\Support\Facades\DB;

/**
 * Case / carton labels: fills the fields from the batch record, turns fields into label
 * lines, and logs every print (label_prints + audit log) with the exact values printed.
 */
class LabelService
{
    public const FIELDS = ['label_type', 'partial', 'title', 'type_text', 'batch_label', 'batch_number', 'total', 'count_label', 'cases', 'case_label',
        'per_case', 'per_case_label', 'barcode_on', 'barcode_value', 'barcode_required'];

    /** Label types: Master box = the whole count (the original layout, default); Case box = one small box inside it. */
    public const TYPES = ['master' => 'Master box', 'case' => 'Case box'];

    private const SINGULAR = ['Prerolls' => 'Preroll', 'Vapes' => 'Vape'];

    /** Fields filled in from a batch (or just a product). Every one can be changed before printing. */
    public function defaults(?Batch $batch, ?Product $product = null): array
    {
        $product ??= $batch?->product;
        $brand = $product?->brand;
        $d = CaseLabel::cfg('defaults');
        $title = self::productLine($product);
        $type = '';   // the product type is part of the product line now (one line, wraps to two)
        // Sae types only the total count; it starts empty (the batch's packed units are shown as a hint).
        $total = null;
        $per = $product?->case_pack_qty ?: null;

        return [
            'label_type' => 'master',
            'partial' => false,
            'title' => $title,
            'type_text' => $type,
            'batch_label' => $d['batch_label'],
            'batch_number' => (string) ($batch?->batch_number ?? ''),
            'total' => $total ?: null,
            'count_label' => $d['count_label'],
            'cases' => null,
            'case_label' => $d['case_label'],
            'per_case' => $per,
            'per_case_label' => trim((string) ($brand?->label_per_case_text ?? '')) ?: $d['per_case_label'],
            'barcode_on' => trim((string) ($batch?->barcode_number ?? '')) !== '',
            'barcode_value' => (string) ($batch?->barcode_number ?? ''),
            'barcode_required' => (bool) ($brand?->requires_barcode ?? false),
        ];
    }

    /** Product type wording for the label: the product's label wording, else type + family. */
    public static function productType(?Product $product): string
    {
        $type = trim((string) $product?->label_type_text);
        if ($type === '' && $product) {
            $fam = $product->family ? (self::SINGULAR[Product::FAMILIES[$product->family] ?? ''] ?? (Product::FAMILIES[$product->family] ?? '')) : '';
            $pt = ucwords(trim((string) $product->product_type));
            $type = trim($pt.($fam !== '' && stripos($pt, rtrim($fam, 's')) === false ? " $fam" : ''));
        }

        return $type;
    }

    /**
     * The product line: Brand + strain + size in grams + product type,
     * e.g. "Bodega Boyz Watermelon Zkittlez 0.7g Shortiez Preroll". Parts already in the product
     * name are not repeated; the size comes from the product's net quantity when the name has none.
     */
    public static function productLine(?Product $product): string
    {
        if (! $product) {
            return '';
        }
        $name = trim(preg_replace('/\s+/u', ' ', (string) $product->name));
        $brand = trim((string) $product->brand?->name);
        $line = $brand !== '' && ! str_starts_with(mb_strtolower($name), mb_strtolower($brand)) ? "$brand $name" : $name;
        $hasSize = (bool) preg_match('/\d(?:[.,]\d+)?\s?(?:g|mg|kg|ml|oz)\b/iu', $name);
        if (! $hasSize && $product->unit_net_qty && in_array($product->unit_net_uom, ['g', 'ml'], true)) {
            $line .= ' '.rtrim(rtrim(number_format((float) $product->unit_net_qty, 3, '.', ''), '0'), '.').$product->unit_net_uom;
        }
        $type = self::productType($product);
        if ($type !== '' && mb_stripos($line, $type) === false) {
            $line .= " $type";
        }

        return trim($line);
    }

    /**
     * The barcode is never typed on the label form: brands that require one always print the
     * batch's barcode number from the Batch Log, and brands that don't never show one.
     */
    public function applyBarcode(array $f, ?Batch $batch, ?Product $product = null): array
    {
        // A barcode number is ALWAYS printed when there is one: the number typed on the label form
        // for this print, else the batch's barcode number from the Batch Log. The brand setting
        // "labels need a barcode" only makes it mandatory (printing is blocked without one).
        $brand = ($product ?? $batch?->product)?->brand;
        $norm = fn ($v) => strtoupper(preg_replace('/\s+/', '', (string) $v));
        $typed = $norm($f['barcode_value'] ?? '');
        $f['barcode_value'] = $typed !== '' ? $typed : $norm($batch?->barcode_number ?? '');
        $f['barcode_on'] = $f['barcode_value'] !== '';
        $f['barcode_required'] = (bool) ($brand?->requires_barcode ?? false);
        $f['barcode_source'] = $f['barcode_value'] === '' ? null : ($typed !== '' && $typed !== $norm($batch?->barcode_number ?? '') ? 'typed' : 'batch');

        return $f;
    }

    /** Brand needs a barcode but the batch (or no batch) has none: printing is blocked. */
    public static function missingBarcode(array $f): bool
    {
        return ! empty($f['barcode_required']) && ($f['barcode_value'] ?? '') === '';
    }

    /**
     * Case math: cases = ceil(total / per case).
     * @return array{cases:?int, full:?int, remainder:?int, text:?string}
     */
    public static function caseMath(?int $total, ?int $perCase): array
    {
        if (! $total || ! $perCase || $total < 0 || $perCase < 1) {
            return ['cases' => null, 'full' => null, 'remainder' => null, 'text' => null];
        }
        $full = intdiv($total, $perCase);
        $rem = $total % $perCase;
        $cases = $full + ($rem > 0 ? 1 : 0);
        $text = $rem > 0
            ? ($full > 0 ? "$full full case".($full === 1 ? '' : 's')." + 1 case of $rem" : "1 case of $rem (less than a full case)")
            : null;

        return ['cases' => $cases, 'full' => $full, 'remainder' => $rem, 'text' => $text];
    }

    /** Fill in the number of cases from the total when it was left empty. */
    public function withCases(array $f): array
    {
        if ($f['label_type'] === 'master' && $f['cases'] === null && is_int($f['total']) && is_int($f['per_case'])) {
            $f['cases'] = self::caseMath($f['total'], $f['per_case'])['cases'];
        }

        return $f;
    }

    /** Clean the submitted fields (strings trimmed, numbers as whole numbers or null). */
    public function clean(array $in): array
    {
        $f = [];
        foreach (self::FIELDS as $k) {
            $v = $in[$k] ?? null;
            $f[$k] = match ($k) {
                'barcode_on', 'partial', 'barcode_required' => filter_var($v, FILTER_VALIDATE_BOOLEAN),
                'label_type' => array_key_exists((string) $v, self::TYPES) ? (string) $v : 'master',
                'total', 'cases', 'per_case' => ($v === null || trim((string) $v) === '') ? null : (is_numeric($v) ? (int) $v : (string) $v),
                'barcode_value' => strtoupper(preg_replace('/\s+/', '', (string) $v)),
                default => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $v)), 0, 120),
            };
        }

        return $f;
    }

    public static function isCaseBox(array $f): bool
    {
        return ($f['label_type'] ?? 'master') === 'case';
    }

    /**
     * Case box quantities: full = floor(total / per case) labels, plus one "partial" label for the
     * remainder. Without a total there is no default (Sae types how many).
     * @return array{full:?int, remainder:?int}
     */
    public static function caseBoxes(array $f): array
    {
        if (! is_int($f['total'] ?? null) || ! is_int($f['per_case'] ?? null) || $f['total'] < 0 || $f['per_case'] < 1) {
            return ['full' => null, 'remainder' => null];
        }

        return ['full' => intdiv($f['total'], $f['per_case']), 'remainder' => $f['total'] % $f['per_case']];
    }

    /** The count printed on a Case box label (the remainder on the partial one). */
    public static function caseBoxCount(array $f): ?int
    {
        if (! empty($f['partial'])) {
            return self::caseBoxes($f)['remainder'] ?: null;
        }

        return is_int($f['per_case']) ? $f['per_case'] : null;
    }

    /** @return string[] label lines top to bottom (before the product line wraps) */
    public function lines(array $f): array
    {
        return array_map(fn ($b) => $b['text'], $this->blocks($f));
    }

    /** Label lines with their role (product / type / batch / total / cases / per_case). */
    public function blocks(array $f): array
    {
        $join = fn ($a, $b) => trim(trim((string) $a).' '.trim((string) $b));
        $out = [['text' => $f['title'], 'role' => 'product', 'wrap' => true]];
        if (trim((string) $f['type_text']) !== '' && ! self::isCaseBox($f)) {   // separate type line: only older master-box prints
            $out[] = ['text' => $f['type_text'], 'role' => 'type'];
        }
        $out[] = ['text' => '', 'role' => 'custom'];
        $out[] = ['text' => $join($f['batch_label'], $f['batch_number']), 'role' => 'batch'];
        if (self::isCaseBox($f)) {
            $out[] = ['text' => $join(self::caseBoxCount($f), $f['per_case_label']), 'role' => 'per_case'];

            return $out;
        }
        $caseLine = $join($f['cases'], $f['case_label']);
        $m = is_int($f['total']) && is_int($f['per_case']) ? self::caseMath($f['total'], $f['per_case']) : null;
        if ($caseLine !== '' && $m && $m['remainder']) {
            $caseLine .= str_replace('{remainder}', (string) $m['remainder'], (string) CaseLabel::cfg('defaults.partial_suffix', ' (1 partial; {remainder} count)'));
        }

        $out[] = ['text' => $join($f['total'], $f['count_label']), 'role' => 'total'];
        $out[] = ['text' => $caseLine, 'role' => 'cases'];
        $out[] = ['text' => $join($f['per_case'], $f['per_case_label']), 'role' => 'per_case'];

        return $out;
    }

    /** Problems that stop printing. */
    public function errors(array $f, int $copies): array
    {
        $e = [];
        foreach (['total' => 'Total count', 'cases' => 'Number of cases', 'per_case' => 'Count per case'] as $k => $l) {
            if ($f[$k] !== null && (! is_int($f[$k]) || $f[$k] < 0 || $f[$k] > 10000000)) {
                $e[] = "$l must be a whole number.";
            }
        }
        $max = (int) CaseLabel::cfg('barcode.max_length', 48);
        if (! empty($f['barcode_required']) && $f['barcode_value'] === '') {
            $e[] = $f['batch_number'] !== ''
                ? "This brand's labels need a barcode, and batch {$f['batch_number']} has no barcode number yet. Type it in the Barcode box (or add it in the Batch Log), then print."
                : "This brand's labels need a barcode. Type the barcode number in the Barcode box, or pick a batch that has one in the Batch Log.";
        }
        if ($f['barcode_value'] !== '') {
            if (! preg_match('/^[\x21-\x7E]+$/', $f['barcode_value']) || strlen($f['barcode_value']) > $max) {
                $e[] = "The barcode number can only use letters, numbers and symbols (no spaces), up to $max characters.";
            }
        }
        if ($f['title'] === '') {
            $e[] = 'The first line (brand, strain and size) is empty.';
        }
        if (self::isCaseBox($f)) {
            if (! is_int($f['per_case']) || $f['per_case'] < 1) {
                $e[] = 'Type the count per case box.';
            } elseif (! empty($f['partial']) && ! self::caseBoxes($f)['remainder']) {
                $e[] = is_int($f['total'])
                    ? "{$f['total']} divides evenly by {$f['per_case']}: there is no partial case box."
                    : 'Type the total count to print the partial case label.';
            }
        }
        $max = (int) CaseLabel::cfg('max_copies', 1000);
        if ($copies < 1 || $copies > $max) {
            $e[] = "How many labels: 1 to $max.";
        }

        return $e;
    }

    /** Things to double-check (printing is still allowed). */
    public function warnings(array $f, ?Batch $batch, array $layout): array
    {
        $w = [];
        if ($f['batch_number'] === '') {
            $w[] = 'No batch number on the label.';
        }
        if (self::isCaseBox($f)) {
            $cb = self::caseBoxes($f);
            if ($cb['remainder'] && empty($f['partial'])) {
                $w[] = "{$f['total']} ÷ {$f['per_case']} = {$cb['full']} full case box".($cb['full'] === 1 ? '' : 'es')." + 1 partial case box of {$cb['remainder']}: print the partial case label too.";
            }
        } elseif ($f['total'] === null) {
            $w[] = 'Type the total count.';
        }
        $m = ! self::isCaseBox($f) && is_int($f['total']) && is_int($f['per_case']) ? self::caseMath($f['total'], $f['per_case']) : null;
        if ($m && $m['text']) {
            $w[] = "{$f['total']} does not divide evenly by {$f['per_case']}: {$m['text']}.";
        }
        if ($m && $m['cases'] !== null && is_int($f['cases']) && $f['cases'] !== $m['cases']) {
            $w[] = "Cases is set to {$f['cases']}, but {$f['total']} ÷ {$f['per_case']} per case needs {$m['cases']}.";
        }
        if ($batch && config('pbp.qc_release_step') && ! in_array($batch->status, ['released', 'imported'], true)) {
            $w[] = 'Batch '.$batch->batch_number.' is not QC-released yet ('.(Batch::STATUSES[$batch->status] ?? $batch->status).').';
        }
        if (! $layout['fits'] && ! empty($layout['explicit'])) {
            $w[] = 'A line or the barcode runs off the edge of the label. Move it or make it smaller.';
        } elseif (! $layout['fits']) {
            $w[] = 'A line is too long to fit even at the smallest font size. Shorten it.';
        } elseif (empty($layout['explicit']) && $layout['size'] < (float) CaseLabel::cfg((self::isCaseBox($f) ? 'casebox.' : 'text.').($f['barcode_on'] ? 'size_with_barcode' : 'size_without_barcode'))) {
            $w[] = 'The text was made smaller ('.$layout['size'].' pt) so the longest line fits.';
        }
        if ($layout['overlaps_barcode']) {
            $w[] = ! empty($layout['explicit']) ? 'A line overlaps the barcode. Move it so the barcode stays scannable.' : 'The text runs into the barcode. Shorten or remove a line.';
        }

        return $w;
    }

    /** The label: the standard layout, or an edited / template layout (lines merged with the current fields). */
    public function label(array $f, ?array $layout = null): CaseLabel
    {
        $bc = $f['barcode_on'] && $f['barcode_value'] !== '' ? $f['barcode_value'] : null;
        $auto = new CaseLabel($this->blocks($f), $bc, self::isCaseBox($f) ? (array) CaseLabel::cfg('casebox') : []);
        if (! $layout || empty($layout['lines'])) {
            return $auto;
        }

        return CaseLabel::fromLayout($this->mergeLayout($layout, $auto->toLayout()), $bc);
    }

    /**
     * Edited layout + current fields: lines whose text was not edited take the current text for
     * their role (so a new total or batch number still shows); positions and sizes stay as edited.
     */
    public function mergeLayout(array $edited, array $auto): array
    {
        $byRole = [];
        foreach ($auto['lines'] as $l) {
            $byRole[$l['role']][] = $l;
        }
        $used = [];
        $lines = [];
        $editedRoles = [];
        foreach ((array) ($edited['lines'] ?? []) as $l) {
            if (! is_array($l)) {
                continue;
            }
            $role = (string) ($l['role'] ?? 'custom');
            $editedRoles[$role] = true;
            $edit = filter_var($l['edited'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (! $edit && $role !== 'custom') {
                $k = $used[$role] = ($used[$role] ?? -1) + 1;
                $l['text'] = $byRole[$role][$k]['text'] ?? '';
            }
            $lines[] = $l;
        }
        foreach ($byRole as $role => $ls) {   // a role the edited layout does not have yet (e.g. barcode brand change)
            if (empty($editedRoles[$role])) {
                foreach ($ls as $l) {
                    $lines[] = $l;
                }
            }
        }

        return ['lines' => $lines, 'barcode' => $edited['barcode'] ?? $auto['barcode']];
    }

    /** Newest template for this product and label type, as a layout. */
    public function productTemplate(?Product $product, string $type): ?array
    {
        return \App\Models\LabelTemplate::latestFor($product?->id, $type)?->layout;
    }

    /** Save the layout as this product's template (admin / supervisor). Data lines keep only their place and size. */
    public function saveTemplate(Product $product, string $type, array $layout, User $by): \App\Models\LabelTemplate
    {
        if (! $by->hasRole(User::ROLE_ADMIN, User::ROLE_SUPERVISOR)) {
            throw GateFailed::because('Only an admin or a supervisor can save a label template.');
        }
        $clean = CaseLabel::sanitize($layout, ! empty($layout['barcode']));
        foreach ($clean['lines'] as &$l) {
            if (in_array($l['role'], CaseLabel::DATA_ROLES, true) && ! $l['edited']) {
                $l['text'] = '';
            }
        }
        unset($l);
        $t = \App\Models\LabelTemplate::create(['product_id' => $product->id, 'label_type' => array_key_exists($type, self::TYPES) ? $type : 'master',
            'layout' => $clean, 'saved_by' => $by->id]);
        Audit::log('label_template_saved', $t, ['product_id' => $product->id, 'label_type' => $t->label_type, 'layout' => $clean]);

        return $t;
    }

    public static function template(array $f): string
    {
        return (string) CaseLabel::cfg(self::isCaseBox($f) ? 'casebox.template' : 'template');
    }

    /** Make the PDF and log the print. @return array{0: LabelPrint, 1: string} */
    public function print(array $f, int $copies, ?Batch $batch, ?Product $product, User $by, ?array $layout = null): array
    {
        if ($by->isViewer()) {
            throw GateFailed::because('Read-only accounts cannot print labels.');
        }
        if ($e = $this->errors($f, $copies)) {
            throw new GateFailed($e);
        }
        $label = $this->label($f, $layout);
        $edited = $layout !== null && ! empty($layout['lines']);
        $L = $label->layout();
        if ($L['overlaps_barcode'] || ! $L['fits']) {
            throw new GateFailed($this->warnings($f, $batch, $L));
        }
        $printed = $label->toLayout();
        $tpl = self::template($f);
        $kind = self::isCaseBox($f) ? (empty($f['partial']) ? 'Case box label ' : 'Partial case box label ') : 'Master box label ';
        $pdf = $label->pdf($copies, ['title' => $kind.($f['batch_number'] ?: ''), 'subject' => $tpl]);

        $lp = DB::transaction(function () use ($f, $copies, $batch, $product, $by, $pdf, $tpl, $printed, $edited) {
            $lp = LabelPrint::create([
                'public_id' => PublicId::next('LBL'), 'batch_id' => $batch?->id, 'product_id' => ($product ?? $batch?->product)?->id,
                'template' => $tpl, 'label_type' => $f['label_type'], 'partial' => (bool) $f['partial'], 'quantity' => $copies, 'fields' => $f, 'layout' => $printed, 'layout_edited' => $edited, 'pdf_sha256' => hash('sha256', $pdf), 'printed_by' => $by->id,
            ]);
            Audit::log('print', $lp, ['batch' => $f['batch_number'] ?: null, 'batch_id' => $batch?->id, 'quantity' => $copies,
                'label_type' => $f['label_type'], 'partial' => (bool) $f['partial'], 'template' => $tpl, 'layout_edited' => $edited, 'layout' => $printed, 'fields' => $f, 'pdf_sha256' => $lp->pdf_sha256]);

            return $lp;
        });

        return [$lp, $pdf];
    }
}
