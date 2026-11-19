<?php

namespace App\Services\Bookkeeping;

use App\Models\BookkeepingStatement;
use App\Models\BookkeepingWeek;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StatementStore
{
    public function store(BookkeepingWeek $week, string $account, UploadedFile $file, User $by): BookkeepingStatement
    {
        $accounts = config('bookkeeping.accounts', []);
        if (! in_array($account, $accounts, true)) {
            throw ValidationException::withMessages(['account' => 'Choose account 8455, 9529, or 9511.']);
        }

        $inspected = $this->inspect($file);
        $bytes = $inspected['bytes'];
        $name = (string) Str::uuid().'.'.$inspected['ext'];
        $path = 'bookkeeping/statements/'.$week->id.'/'.$name;

        if (! Storage::disk('local')->put($path, $bytes)) {
            throw ValidationException::withMessages(['statement' => 'The statement could not be saved.']);
        }

        return BookkeepingStatement::create([
            'bookkeeping_week_id' => $week->id,
            'account' => $account,
            'original_name' => $this->originalName($file, $inspected['ext']),
            'path' => $path,
            'mime' => $inspected['mime'],
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'uploaded_by' => $by->id,
        ]);
    }

    /** @return array{ext: string, mime: string, bytes: string} */
    private function inspect(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['csv', 'pdf'], true)) {
            throw ValidationException::withMessages(['statement' => 'Upload a CSV or PDF bank statement.']);
        }

        $real = $file->getRealPath();
        $bytes = $real ? file_get_contents($real) : false;
        if (! is_string($bytes) || $bytes === '') {
            throw ValidationException::withMessages(['statement' => 'That file is empty.']);
        }
        if (strlen($bytes) > 10 * 1024 * 1024) {
            throw ValidationException::withMessages(['statement' => 'Each statement must be 10 MB or smaller.']);
        }
        if (str_contains($bytes, "\0")) {
            throw ValidationException::withMessages(['statement' => 'That file is not a plain CSV or PDF.']);
        }

        $isPdf = str_starts_with($bytes, '%PDF-');
        if ($ext === 'pdf') {
            if (! $isPdf) {
                throw ValidationException::withMessages(['statement' => 'That PDF could not be read. Upload the original bank PDF.']);
            }

            return ['ext' => 'pdf', 'mime' => 'application/pdf', 'bytes' => $bytes];
        }

        if ($isPdf || str_starts_with($bytes, 'MZ') || str_starts_with($bytes, "\x7fELF") || str_starts_with($bytes, 'PK')) {
            throw ValidationException::withMessages(['statement' => 'That file is not a CSV bank statement.']);
        }
        if (preg_match('/<\?(php|=)|<script\b/i', $bytes)) {
            throw ValidationException::withMessages(['statement' => 'That file is not a CSV bank statement.']);
        }

        return ['ext' => 'csv', 'mime' => 'text/csv', 'bytes' => $bytes];
    }

    private function originalName(UploadedFile $file, string $ext): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = preg_replace('/[^\w.\- ()]+/u', '_', $name) ?: '';
        $name = trim((string) $name, '. ');
        if ($name === '' || ! str_ends_with(strtolower($name), '.'.$ext)) {
            $name = 'statement.'.$ext;
        }

        return mb_substr($name, 0, 180);
    }
}
