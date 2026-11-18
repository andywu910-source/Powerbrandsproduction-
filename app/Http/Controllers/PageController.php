<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\ChecklistInstance;
use App\Models\Deviation;
use App\Models\DriveFiling;
use App\Models\MaterialLot;
use App\Models\Product;
use App\Services\ChecklistService;

class PageController extends Controller
{
    public function dashboard(ChecklistService $checklists)
    {
        $useChecklists = \App\Support\Modules::enabled('checklists');
        if ($useChecklists) {
            $checklists->generate();
        }
        $open = ! $useChecklists ? collect() : ChecklistInstance::with('template')->whereIn('status', ['due', 'in_progress', 'overdue', 'awaiting_verification'])->whereNull('batch_id')->orderBy('due_at')->get();

        return view('pages.dashboard', [
            'overdue' => $open->filter(fn ($i) => $i->displayStatus() === 'overdue')->values(),
            'due' => $open->filter(fn ($i) => $i->displayStatus() !== 'overdue')->values(),
            'batches' => Batch::with('product')->whereIn('status', ['in_process', 'in_qc'])->orderBy('id')->get(),
            'quarantine' => MaterialLot::with('ingredient')->where('status', 'quarantine')->whereNull('voided_at')->orderBy('received_at')->get(),
            'deviations' => Deviation::whereIn('status', ['open', 'approved_to_continue'])->whereNull('voided_at')->orderBy('id')->get(),
            'unfiled' => DriveFiling::where('status', '!=', 'filed')->count(),
            'review' => Product::where('needs_review', true)->where('active', true)->count(),
            // Scale register: overdue, missing and due-within-N-days Weights & Measures inspections.
            'scaleBlock' => \App\Services\ScaleBlock::on() ? null : \App\Services\ScaleBlock::progress() + ['line' => \App\Services\ScaleBlock::statusLine()],
            'scaleAlerts' => \App\Models\Equipment::scales()->where('active', true)->orderBy('inspection_due_on')->get()
                ->filter(fn ($s) => in_array($s->inspectionStatus(), ['overdue', 'missing', 'due_soon'], true))->values(),
        ]);
    }

    /** Sidebar tabs that come later. */
    public function comingSoon(string $section)
    {
        $sections = [
            'labels' => ['Labels', '3×2 case / carton labels filled in from each batch, with a live preview and a print log. Switched off on this site.'],
            'production' => ['Production', 'The 9-step batch record. Being switched on next.'],
            'receiving' => ['Receiving', 'Incoming materials, quarantine and QC release. Being switched on soon.'],
            'checklists' => ['Checklists', 'Cleaning, equipment and security checklists with due dates. Being switched on soon.'],
            'batchlog' => ['Batch Log', 'Search finished batches and print the batch record. Being switched on soon.'],
            'logs' => ['Logs', 'Visitor log, waste disposal (two witnesses) and security incidents. Coming in Phase 2.'],
            'quality' => ['Quality', 'Complaints, recalls and retained samples. Coming in Phase 2.'],
        ];
        abort_unless(isset($sections[$section]), 404);
        [$title, $blurb] = $sections[$section];

        return view('pages.coming-soon', compact('title', 'blurb', 'section'));
    }
}
