<?php

namespace App\Models;

use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

/** Uploaded files are never replaced; a new upload is a new row. */
class Attachment extends Model
{
    use NoDelete;

    protected $guarded = ['id'];

    public const KINDS = [
        'coa' => 'Certificate of analysis (COA)',
        'attestation' => 'Supplier ingredient attestation (Appendix 6-D)',
        'source_records' => 'Terpene source records',
        'coc' => 'Lab sampling chain of custody (signed)',
        'sop' => 'SOP / plan document',
        'wm_certificate' => 'Weights & Measures inspection certificate',
        'other' => 'Other',
    ];

    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }
}
