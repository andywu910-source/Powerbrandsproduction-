<?php

namespace App\Support;

use App\Models\Attachment;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Uploaded files go to storage/app/private (never public). Never replaced. */
class AttachmentStore
{
    public static function store(Model $record, string $kind, UploadedFile $file, User $by): Attachment
    {
        if ($by->isViewer()) {
            throw new \App\Exceptions\GateFailed(['Read-only accounts cannot upload files.']);
        }
        $sha = hash_file('sha256', $file->getRealPath());
        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $path = 'attachments/'.now()->format('Y/m').'/'.$sha.'.'.$ext;
        if (! Storage::disk('local')->exists($path)) {
            Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));
        }
        $a = Attachment::create([
            'record_type' => $record->getMorphClass(), 'record_id' => $record->getKey(), 'kind' => $kind,
            'filename' => mb_substr(preg_replace('/[^\w.\- ()]+/u', '_', $file->getClientOriginalName()), 0, 200),
            'path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'sha256' => $sha, 'uploaded_by' => $by->id,
        ]);
        Audit::log('attach', $record, ['attachment_id' => $a->id, 'kind' => $kind, 'sha256' => $sha]);

        return $a;
    }
}
