<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\BatchLogController;
use App\Http\Controllers\ChecklistController;
use App\Http\Controllers\ChecklistTemplateController;
use App\Http\Controllers\DeviationController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PlansController;
use App\Http\Controllers\ProcessTemplateController;
use App\Http\Controllers\ProductionController;
use App\Http\Controllers\ReceivingController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\ScaleController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Guests. No public registration.
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])
        ->middleware('throttle:login')->name('login.submit');
});

Route::middleware(['auth', 'active', 'writable'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('/', fn () => redirect()->route('dashboard'));
    Route::get('/dashboard', [PageController::class, 'dashboard'])->name('dashboard');
    Route::get('/soon/{section}', [PageController::class, 'comingSoon'])->name('soon');

    // ---------------- Production (batch number is the URL key)
    Route::middleware('module:production')->prefix('production')->name('production.')->group(function () {
        Route::post('/incoming', [ProductionController::class, 'storeIncoming'])->name('incoming');
        Route::get('/', [ProductionController::class, 'index'])->name('index');
        Route::post('/', [ProductionController::class, 'store'])->name('store');
        Route::get('/batch-number-check', [ProductionController::class, 'checkNumber'])->name('check-number');
        Route::get('/{batch}', [ProductionController::class, 'show'])->name('show');
        Route::post('/{batch}/start-incoming', [ProductionController::class, 'startIncoming'])->name('start-incoming');
        Route::post('/{batch}/steps/{step}/complete', [ProductionController::class, 'complete'])->whereNumber('step')->name('complete');
        Route::post('/{batch}/cleaning', [ProductionController::class, 'startCleaning'])->name('cleaning');
        Route::post('/{batch}/equipment', [ProductionController::class, 'addEquipment'])->name('equipment');
        Route::post('/{batch}/people', [ProductionController::class, 'addPerson'])->name('people');
        Route::post('/{batch}/calculate', [ProductionController::class, 'calculate'])->name('calculate');
        Route::post('/{batch}/calculate/confirm', [ProductionController::class, 'confirmCalc'])->name('calc.confirm');
        Route::post('/{batch}/pick', [ProductionController::class, 'pick'])->name('pick');
        Route::post('/{batch}/lines/{line}/weigh', [ProductionController::class, 'weigh'])->whereNumber('line')->name('weigh');
        Route::post('/{batch}/lines/{line}/verify', [ProductionController::class, 'verifyLine'])->whereNumber('line')->name('verify-line');
        Route::post('/{batch}/lines/{line}/reweigh', [ProductionController::class, 'reweigh'])->whereNumber('line')->name('reweigh');
        Route::post('/{batch}/lines/{line}/void', [ProductionController::class, 'voidPick'])->whereNumber('line')->name('void-pick');
        Route::post('/{batch}/lines/{line}/deviation', [ProductionController::class, 'lineDeviation'])->whereNumber('line')->name('line-deviation');
        Route::post('/{batch}/mmp/{mmpStep}', [ProductionController::class, 'recordMmpStep'])->name('mmp.record');
        Route::post('/{batch}/mmp-records/{rec}/verify', [ProductionController::class, 'verifyMmpStep'])->whereNumber('rec')->name('mmp.verify');
        Route::post('/{batch}/mmp-records/{rec}/deviation', [ProductionController::class, 'mmpDeviation'])->whereNumber('rec')->name('mmp.deviation');
        Route::post('/{batch}/upload', [ProductionController::class, 'upload'])->name('upload');
        Route::post('/{batch}/coa', [ProductionController::class, 'coa'])->name('coa');
        Route::post('/{batch}/qc', [ProductionController::class, 'qc'])->name('qc');
        Route::post('/{batch}/void', [ProductionController::class, 'void'])->middleware('role:admin,supervisor')->name('void');
    });

    // ---------------- Receiving
    Route::middleware('module:receiving')->prefix('receiving')->name('receiving.')->group(function () {
        Route::get('/', [ReceivingController::class, 'index'])->name('index');
        Route::get('/new', [ReceivingController::class, 'create'])->name('create');
        Route::post('/', [ReceivingController::class, 'store'])->name('store');
        Route::get('/{lot}', [ReceivingController::class, 'show'])->name('show');
        Route::post('/{lot}/verify', [ReceivingController::class, 'verify'])->name('verify');
        Route::post('/{lot}/paperwork', [ReceivingController::class, 'paperwork'])->name('paperwork');
        Route::post('/{lot}/upload', [ReceivingController::class, 'upload'])->name('upload');
        Route::post('/{lot}/release', [ReceivingController::class, 'release'])->name('release');
        Route::post('/{lot}/reject', [ReceivingController::class, 'reject'])->name('reject');
        Route::post('/{lot}/adjust', [ReceivingController::class, 'adjust'])->middleware('role:admin,supervisor')->name('adjust');
    });

    // ---------------- Checklists. Filling in one checklist is always on (Production step 2 uses it).
    Route::get('/checklists/{checklist}', [ChecklistController::class, 'show'])->whereNumber('checklist')->name('checklists.show');
    Route::post('/checklists/{checklist}/answers', [ChecklistController::class, 'answers'])->whereNumber('checklist')->name('checklists.answers');
    Route::post('/checklists/{checklist}/complete', [ChecklistController::class, 'complete'])->whereNumber('checklist')->name('checklists.complete');
    Route::post('/checklists/{checklist}/verify', [ChecklistController::class, 'verify'])->whereNumber('checklist')->name('checklists.verify');
    Route::post('/checklists/{checklist}/items/{item}/deviation', [ChecklistController::class, 'deviation'])->whereNumber('checklist')->whereNumber('item')->name('checklists.deviation');
    Route::middleware('module:checklists')->prefix('checklists')->name('checklists.')->group(function () {
        Route::get('/', [ChecklistController::class, 'index'])->name('index');
        Route::post('/start/{template}', [ChecklistController::class, 'start'])->name('start');
        Route::middleware('role:admin,supervisor')->group(function () {
            Route::get('/templates/new', [ChecklistTemplateController::class, 'create'])->name('templates.create');
            Route::post('/templates', [ChecklistTemplateController::class, 'store'])->name('templates.store');
            Route::get('/templates/{template}', [ChecklistTemplateController::class, 'edit'])->name('templates.edit');
            Route::put('/templates/{template}', [ChecklistTemplateController::class, 'update'])->name('templates.update');
        });
    });

    // ---------------- Batch Log
    Route::middleware('module:batchlog')->prefix('batch-log')->name('batch-log.')->group(function () {
        Route::get('/', [BatchLogController::class, 'index'])->name('index');
        Route::get('/{batch}', [BatchLogController::class, 'show'])->name('show');
        Route::get('/{batch}/print', [BatchLogController::class, 'print'])->name('print');
        Route::get('/{batch}/pdf', [BatchLogController::class, 'pdf'])->name('pdf');
        Route::post('/{batch}/barcode', [BatchLogController::class, 'barcode'])->name('barcode');
        Route::post('/{batch}/status', [BatchLogController::class, 'logStatus'])->name('status');
        Route::post('/{batch}/expiration', [BatchLogController::class, 'expiration'])->name('expiration');
    });

    // ---------------- Compliance Checklists (weekly / monthly; internal, not official OCM documents)
    Route::middleware('module:compliance')->prefix('compliance')->name('compliance.')->controller(\App\Http\Controllers\ComplianceController::class)->group(function () {
        Route::get('/', 'home')->name('home');
        Route::get('/history', 'history')->name('history');
        Route::get('/setup', 'setup')->name('setup');
        Route::post('/setup/items', 'addItem')->name('item.add');
        Route::post('/setup/items/{item}', 'saveItem')->name('item.save');
        Route::get('/{freq}', 'period')->whereIn('freq', ['weekly', 'monthly', 'quarterly', 'annual'])->name('period');
        Route::post('/{freq}/{period}/items/{item}', 'save')->whereIn('freq', ['weekly', 'monthly', 'quarterly', 'annual'])->where('period', '\d{4}-\d{2}-\d{2}')->name('save');
    });

    // ---------------- Weekly Schedule (preview first)
    Route::middleware('module:schedule')->prefix('schedule')->name('schedule.')->controller(\App\Http\Controllers\ScheduleController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/today', 'today')->name('today');
        Route::get('/people', 'people')->name('people');
        Route::post('/people', 'savePerson')->name('people.store');
        Route::post('/people/{employee}', 'savePerson')->name('people.update');
        Route::post('/setup', 'saveSetup')->name('setup');
        Route::get('/copy', 'copyForm')->name('copy');
        Route::post('/start', 'start')->name('start');
        Route::get('/w/{week}/p/{employee}/{day}', 'cell')->where('day', '\\d{4}-\\d{2}-\\d{2}')->name('cell');
        Route::post('/w/{week}/p/{employee}/{day}', 'saveCell')->where('day', '\\d{4}-\\d{2}-\\d{2}')->name('cell.save');
        Route::post('/w/{week}/p/{employee}/{day}/jobs', 'addJob')->where('day', '\\d{4}-\\d{2}-\\d{2}')->name('job.add');
        Route::post('/jobs/{job}', 'saveJob')->name('job.save');
        Route::post('/jobs/{job}/remove', 'removeJob')->name('job.remove');
        Route::post('/w/{week}/people', 'addPerson')->name('roster.add');
        Route::post('/roster/{roster}', 'saveRoster')->name('roster.save');
        Route::post('/w/{week}/notes', 'addNote')->name('note.add');
        Route::post('/notes/{note}', 'saveNote')->name('note.save');
    });

    // ---------------- Labels (case / carton labels, 3 x 2 in)
    Route::middleware('module:labels')->prefix('labels')->name('labels.')->group(function () {
        Route::get('/', [LabelController::class, 'index'])->name('index');
        Route::get('/new', [LabelController::class, 'create'])->name('create');
        Route::get('/batches', [LabelController::class, 'batches'])->name('batches');
        Route::post('/preview', [LabelController::class, 'preview'])->name('preview');
        Route::post('/print', [LabelController::class, 'print'])->name('print');
        Route::post('/template', [LabelController::class, 'saveTemplate'])->name('template');
    });

    // ---------------- Deviations (used by Production, Receiving and Checklists)
    Route::prefix('deviations')->name('deviations.')->group(function () {
        Route::get('/', [DeviationController::class, 'index'])->name('index');
        Route::post('/', [DeviationController::class, 'store'])->name('store');
        Route::get('/{deviation}', [DeviationController::class, 'show'])->name('show');
        Route::post('/{deviation}/approve', [DeviationController::class, 'approve'])->name('approve');
        Route::post('/{deviation}/close', [DeviationController::class, 'close'])->name('close');
    });

    // ---------------- Records: void / correct / files
    Route::post('/records/{type}/{id}/void', [RecordController::class, 'void'])->whereNumber('id')->name('records.void');
    Route::post('/records/{type}/{id}/correct', [RecordController::class, 'correct'])->whereNumber('id')->name('records.correct');
    Route::get('/files/{attachment}', [RecordController::class, 'download'])->name('files.download');

    // ---------------- Setup: master data, recipes (MMP) and quality plans (PQP)
    Route::get('/setup', [SetupController::class, 'home'])->name('setup.home');
    Route::get('/setup/plans', [PlansController::class, 'index'])->name('plans.index');
    Route::get('/setup/plans/pqp/{pqp}', [PlansController::class, 'showPqp'])->name('plans.pqp');
    Route::get('/setup/plans/mmp/{mmp}', [PlansController::class, 'showMmp'])->name('plans.mmp');
    Route::middleware('role:admin,supervisor')->group(function () {
        Route::post('/setup/plans/pqp', [PlansController::class, 'storePqp'])->name('plans.pqp.store');
        Route::put('/setup/plans/pqp/{pqp}', [PlansController::class, 'updatePqp'])->name('plans.pqp.update');
        Route::post('/setup/plans/pqp/{pqp}/approve', [PlansController::class, 'approvePqp'])->name('plans.pqp.approve');
        Route::post('/setup/plans/mmp', [PlansController::class, 'storeMmp'])->name('plans.mmp.store');
        Route::put('/setup/plans/mmp/{mmp}', [PlansController::class, 'updateMmp'])->name('plans.mmp.update');
        Route::post('/setup/plans/mmp/{mmp}/approve', [PlansController::class, 'approveMmp'])->name('plans.mmp.approve');
        Route::post('/setup/plans/mmp/{mmp}/version', [PlansController::class, 'newVersion'])->name('plans.mmp.version');
    });
    Route::middleware('role:admin')->group(function () {
        Route::get('/setup/process', [ProcessTemplateController::class, 'index'])->name('process.index');
        Route::put('/setup/process/{template}', [ProcessTemplateController::class, 'update'])->name('process.update');
        Route::get('/setup/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/setup/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('/setup/settings/preview', [SettingsController::class, 'preview'])->name('settings.preview');
    });
    // ---------------- Scale register (before the generic /setup/{res} routes)
    Route::get('/setup/scales', [ScaleController::class, 'index'])->name('scales.index');
    Route::post('/setup/scales', [ScaleController::class, 'store'])->name('scales.store');
    Route::get('/setup/scales/{scale}', [ScaleController::class, 'show'])->whereNumber('scale')->name('scales.show');
    Route::put('/setup/scales/{scale}', [ScaleController::class, 'update'])->whereNumber('scale')->name('scales.update');
    Route::post('/setup/scales/{scale}/inspections', [ScaleController::class, 'inspect'])->whereNumber('scale')->name('scales.inspect');
    Route::post('/setup/scale-inspections/{inspection}/void', [ScaleController::class, 'voidInspection'])->whereNumber('inspection')->name('scales.inspection.void');

    Route::get('/setup/{res}', [SetupController::class, 'index'])->name('setup.index');
    Route::post('/setup/{res}', [SetupController::class, 'store'])->name('setup.store');
    Route::get('/setup/{res}/{id}', [SetupController::class, 'edit'])->whereNumber('id')->name('setup.edit');
    Route::put('/setup/{res}/{id}', [SetupController::class, 'update'])->whereNumber('id')->name('setup.update');

    // Weekly Bookkeeping (admin-only, switched with PBP_MODULES=bookkeeping).
    // Kept in its own file so a hand-merge only adds this line here.
    require __DIR__.'/bookkeeping.php';

    Route::middleware('role:admin')->prefix('users')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::get('/{user}/edit', [UserController::class, 'edit'])->name('edit');
        Route::put('/{user}', [UserController::class, 'update'])->name('update');
        Route::post('/{user}/reset-password', [UserController::class, 'resetPassword'])->name('reset-password');
    });
});

// Preview site only (never on live): one-time guest login links.
if (\App\Support\Preview::guestLoginEnabled()) {
    require __DIR__.'/preview.php';
}
