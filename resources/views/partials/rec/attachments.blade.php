{{-- $files: attachments; optional $upload (url) and $kinds [value => label] --}}
<div class="pb-files">
    @forelse ($files ?? [] as $f)
        <div class="pb-file"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
            <a href="{{ route('files.download', $f) }}">{{ $f->filename }}</a>
            <span class="pill">{{ \App\Models\Attachment::KINDS[$f->kind] ?? $f->kind }}</span>
            <span class="pb-muted">{{ \App\Support\Fmt::dt($f->created_at) }}</span></div>
    @empty
        <p class="pb-muted">No files yet.</p>
    @endforelse
    @if (! empty($upload) && ! auth()->user()->isViewer())
        <form method="POST" action="{{ $upload }}" enctype="multipart/form-data" class="pb-inline-form">
            @csrf
            <select name="kind" class="pb-select pb-sm">@foreach ($kinds as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
            <input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.heic,.webp">
            <button class="pb-btn pb-btn-ghost" type="submit">Upload</button>
        </form>
    @endif
</div>
