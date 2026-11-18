<div class="pb-kv">
    <div><span>Batch number</span><b class="pb-bn">{{ $batch->batch_number }}</b></div>
    <div><span>Recipe approved by</span><b>{{ $batch->mmp->approved_by ? \App\Models\User::find($batch->mmp->approved_by)?->name : '—' }}</b></div>
    <div><span>Quality plan</span><b>{{ $batch->mmp->pqp?->public_id ?? '—' }}</b></div>
</div>
