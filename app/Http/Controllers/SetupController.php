<?php

namespace App\Http\Controllers;

use App\Exceptions\GateFailed;
use App\Models\Brand;
use App\Models\Equipment;
use App\Models\Ingredient;
use App\Models\Person;
use App\Models\Product;
use App\Models\Room;
use App\Models\Supplier;
use App\Models\TrainingRecord;
use App\Models\User;
use App\Support\CodeRules;
use App\Support\Units;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Master data (Setup). One generic screen per list. Nothing is deleted: rows are
 * switched off with "Active". Every change is audited.
 */
class SetupController extends Controller
{
    public static function resources(): array
    {
        $yes = ['type' => 'bool'];

        return [
            'brands' => ['Brands', Brand::class, 'admin', [
                'name' => ['label' => 'Brand name', 'type' => 'text', 'required' => true],
                'short_code' => ['label' => 'Brand code (batch numbers)', 'type' => 'code', 'code' => 'brand'],
                'requires_barcode' => ['label' => 'Case labels need a barcode (the batch\'s barcode number from the Batch Log)'] + $yes,
                'label_per_case_text' => ['label' => 'Case labels: wording after the count per case (e.g. Count per case box)', 'type' => 'text'],
                'needs_review' => ['label' => 'Needs review'] + $yes, 'active' => ['label' => 'Active'] + $yes,
            ], ['name', 'short_code', 'needs_review', 'active']],
            'products' => ['Products', Product::class, 'admin', [
                'name' => ['label' => 'Product name', 'type' => 'text', 'required' => true],
                'brand_id' => ['label' => 'Brand', 'type' => 'model', 'model' => Brand::class],
                'short_code' => ['label' => 'Product code (batch numbers)', 'type' => 'code', 'code' => 'product'],
                'family' => ['label' => 'Product family (sets the manufacturing steps)', 'type' => 'select', 'options' => Product::FAMILIES],
                'product_type' => ['label' => 'Type (e.g. shorties, AIO, cartridge, tincture)', 'type' => 'text'],
                'product_line' => ['label' => 'Product line for Start a batch (e.g. 0.7g Shortiez Preroll; empty = from the name)', 'type' => 'text'],
                'flavor' => ['label' => 'Flavor / strain for Start a batch (empty = from the name; used only with a product line)', 'type' => 'text'],
                'unit_net_qty' => ['label' => 'Net amount per unit', 'type' => 'number'],
                'unit_net_uom' => ['label' => 'Unit', 'type' => 'select', 'options' => Units::BASE],
                'hardware_sku' => ['label' => 'Hardware SKU', 'type' => 'text'],
                'case_pack_qty' => ['label' => 'Count per case (case labels)', 'type' => 'int', 'required' => true],
                'label_type_text' => ['label' => 'Product type on labels (e.g. Shortiez Preroll)', 'type' => 'text'],
                'serving_type' => ['label' => 'Serving type (OCM sampling Table 1 column; multi-serving only for edibles and capsules)', 'type' => 'select', 'options' => \App\Support\OcmSampling::SERVING_TYPES],
                'needs_review' => ['label' => 'Needs review'] + $yes, 'review_note' => ['label' => 'Review note', 'type' => 'textarea'],
                'active' => ['label' => 'Active'] + $yes,
            ], ['name', 'brand_id', 'short_code', 'family', 'needs_review', 'active']],
            'ingredients' => ['Ingredients & materials', Ingredient::class, 'supervisor', [
                'name' => ['label' => 'Name', 'type' => 'text', 'required' => true],
                'kind' => ['label' => 'Kind', 'type' => 'select', 'options' => Ingredient::KINDS, 'required' => true],
                'unit' => ['label' => 'Tracked in', 'type' => 'select', 'options' => Units::BASE, 'required' => true],
                'grade' => ['label' => 'Grade', 'type' => 'text'],
                'is_cannabis' => ['label' => 'Cannabis input (needs Metrc IDs)'] + $yes, 'requires_coa' => ['label' => 'Needs a COA'] + $yes,
                'requires_attestation' => ['label' => 'Needs supplier attestation (terpenes/flavors)'] + $yes,
                'inhaled_allowed' => ['label' => 'On the inhaled allowed list'] + $yes, 'active' => ['label' => 'Active'] + $yes,
            ], ['name', 'kind', 'unit', 'is_cannabis', 'requires_coa', 'active']],
            'suppliers' => ['Suppliers', Supplier::class, 'supervisor', [
                'name' => ['label' => 'Name', 'type' => 'text', 'required' => true], 'address' => ['label' => 'Address', 'type' => 'textarea'],
                'license_no' => ['label' => 'License / registration #', 'type' => 'text'], 'contact' => ['label' => 'Contact', 'type' => 'text'],
                'approved' => ['label' => 'Approved supplier'] + $yes, 'active' => ['label' => 'Active'] + $yes,
            ], ['name', 'license_no', 'approved', 'active']],
            'rooms' => ['Rooms', Room::class, 'supervisor', [
                'room_no' => ['label' => 'Room #', 'type' => 'text', 'required' => true], 'name' => ['label' => 'Name', 'type' => 'text', 'required' => true],
                'use' => ['label' => 'Use', 'type' => 'text'], 'active' => ['label' => 'Active'] + $yes,
            ], ['room_no', 'name', 'use', 'active']],
            'equipment' => ['Equipment register', Equipment::class, 'supervisor', [
                'equip_id' => ['label' => 'Equipment ID', 'type' => 'text', 'required' => true], 'name' => ['label' => 'Name', 'type' => 'text', 'required' => true],
                'room_id' => ['label' => 'Room', 'type' => 'model', 'model' => Room::class], 'type' => ['label' => 'Type', 'type' => 'text'],
                'serial' => ['label' => 'Serial #', 'type' => 'text'], 'is_scale' => ['label' => 'This is a scale'] + $yes,
                'wm_seal_ref' => ['label' => 'Weights & Measures approval / seal ref', 'type' => 'text'],
                'calibration_frequency_days' => ['label' => 'Calibrate every (days)', 'type' => 'number'],
                'last_calibrated_on' => ['label' => 'Last calibrated', 'type' => 'date'], 'calibration_due_on' => ['label' => 'Calibration due', 'type' => 'date'],
                'maintenance_due_on' => ['label' => 'Maintenance due', 'type' => 'date'], 'active' => ['label' => 'Active'] + $yes,
            ], ['equip_id', 'name', 'is_scale', 'calibration_due_on', 'active']],
            'people' => ['People', Person::class, 'admin', [
                'full_name' => ['label' => 'Full name', 'type' => 'text', 'required' => true], 'initials' => ['label' => 'Initials', 'type' => 'text', 'required' => true],
                'job_title' => ['label' => 'Job title', 'type' => 'text'], 'user_id' => ['label' => 'Login (if they have one)', 'type' => 'model', 'model' => User::class],
                'no_documentation' => ['label' => 'Never assigned paperwork or signatures (e.g. Jack)'] + $yes, 'active' => ['label' => 'Active'] + $yes,
            ], ['full_name', 'initials', 'job_title', 'user_id', 'no_documentation', 'active']],
            'training' => ['Training records', TrainingRecord::class, 'supervisor', [
                'person_id' => ['label' => 'Person', 'type' => 'model', 'model' => Person::class, 'required' => true],
                'category' => ['label' => 'Category', 'type' => 'select', 'options' => TrainingRecord::CATEGORIES, 'required' => true],
                'topic' => ['label' => 'Topic', 'type' => 'text', 'required' => true], 'completed_on' => ['label' => 'Completed on', 'type' => 'date', 'required' => true],
                'expires_on' => ['label' => 'Expires on', 'type' => 'date'], 'trainer' => ['label' => 'Trainer', 'type' => 'text'], 'notes' => ['label' => 'Notes', 'type' => 'textarea'],
            ], ['person_id', 'category', 'topic', 'completed_on', 'expires_on']],
        ];
    }

    private function def(string $res): array
    {
        $all = self::resources();
        abort_unless(isset($all[$res]), 404);

        return $all[$res];
    }

    private function canEdit(Request $r, string $level): bool
    {
        return $level === 'admin' ? $r->user()->isAdmin() : $r->user()->isSupervisorOrAdmin();
    }

    public function home()
    {
        return view('setup.home', ['resources' => self::resources()]);
    }

    public function index(Request $r, string $res)
    {
        [$title, $class, $level, $fields, $cols] = $this->def($res);
        $q = $class::query()->orderBy(array_key_first($fields));
        if ($t = trim((string) $r->input('q'))) {
            $q->where(array_key_first($fields), 'like', "%$t%");
        }
        if ($res === 'products' && $r->boolean('review')) {
            $q->where('needs_review', true);
        }

        return view('setup.index', ['res' => $res, 'title' => $title, 'fields' => $fields, 'cols' => $cols, 'rows' => $q->paginate(50)->withQueryString(),
            'canEdit' => $this->canEdit($r, $level), 'options' => $this->options($fields), 'item' => new $class]);
    }

    public function edit(Request $r, string $res, int $id)
    {
        [$title, $class, $level, $fields] = $this->def($res);
        $item = $class::findOrFail($id);

        return view('setup.edit', ['res' => $res, 'title' => $title, 'fields' => $fields, 'item' => $item, 'canEdit' => $this->canEdit($r, $level), 'options' => $this->options($fields),
            'aliases' => $item instanceof Product ? $item->aliases()->pluck('alias') : collect()]);
    }

    private function options(array $fields): array
    {
        $o = [];
        foreach ($fields as $k => $f) {
            if (($f['type'] ?? '') === 'model') {
                $o[$k] = $f['model']::query()->get()->mapWithKeys(fn ($m) => [$m->id => method_exists($m, 'label') ? $m->label() : $m->name])->sort()->all();
            }
        }

        return $o;
    }

    private function validated(Request $r, array $fields, string $class, $item): array
    {
        $rules = [];
        foreach ($fields as $k => $f) {
            $req = ! empty($f['required']) ? 'required' : 'nullable';
            $rules[$k] = match ($f['type']) {
                'bool' => ['nullable'],
                'number' => [$req, 'numeric'],
                'int' => [$req, 'integer', 'min:1', 'max:1000000'],
                'date' => [$req, 'date'],
                'select' => [$req, Rule::in(array_keys($f['options']))],
                'model' => [$req, 'integer', Rule::exists((new $f['model'])->getTable(), 'id')],
                'code' => ['nullable', 'string', 'max:10'],
                default => [$req, 'string', 'max:2000'],
            };
        }
        $d = $r->validate($rules);
        foreach ($fields as $k => $f) {
            if ($f['type'] === 'bool') {
                $d[$k] = $r->boolean($k);
            }
            if ($f['type'] === 'code') {
                $d[$k] = ($d[$k] ?? '') === '' ? null : strtoupper(trim($d[$k]));
                if ($d[$k] !== null) {
                    $why = $f['code'] === 'brand' ? CodeRules::brandProblem($d[$k]) : CodeRules::productProblem($d[$k]);
                    if ($why) {
                        throw GateFailed::because($why);
                    }
                    $dupe = $class::where('short_code', $d[$k])->where('id', '!=', $item->id ?? 0)
                        ->when($f['code'] === 'product', fn ($q) => $q->where('brand_id', $d['brand_id'] ?? null))->exists();
                    if ($dupe) {
                        throw GateFailed::because("Code {$d[$k]} is already used".($f['code'] === 'product' ? ' by another product of this brand.' : ' by another brand.'));
                    }
                }
            }
        }
        if ($class === Product::class) {
            $d['serving_type'] = ($d['serving_type'] ?? null) ?: 'single';
        }
        if ($class === Product::class && $d['serving_type'] === 'multi'
            && ! \App\Support\OcmSampling::multiAllowed($d['family'] ?? null, $d['product_type'] ?? null, $d['name'] ?? null)) {
            throw GateFailed::because(\App\Support\OcmSampling::MULTI_NOT_ALLOWED);
        }
        if ($class === Person::class && ! empty($d['no_documentation'])) {
            $d['user_id'] = null;   // Jack never signs, so never linked to a login
        }

        return $d;
    }

    public function store(Request $r, string $res)
    {
        [$title, $class, $level, $fields] = $this->def($res);
        abort_unless($this->canEdit($r, $level), 403);
        $d = $this->validated($r, $fields, $class, null);
        if ($class === Product::class) {
            $d['code'] = \Illuminate\Support\Str::slug($d['name']).'-'.substr(md5(uniqid()), 0, 4);
            $d['source'] = 'manual';
        }
        $class::create($d + ['recorded_by_user_id' => $r->user()->id]);

        return redirect()->route('setup.index', $res)->with('status', 'Added.');
    }

    public function update(Request $r, string $res, int $id)
    {
        [$title, $class, $level, $fields] = $this->def($res);
        abort_unless($this->canEdit($r, $level), 403);
        $item = $class::findOrFail($id);
        $item->update($this->validated($r, $fields, $class, $item));

        return redirect()->route('setup.edit', [$res, $id])->with('status', 'Saved.');
    }
}
