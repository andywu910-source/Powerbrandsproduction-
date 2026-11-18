{{-- Google Drive filing state for a record --}}
@php $fs = $record->filing_status ?? 'none'; @endphp
@if ($fs === 'pending')<span class="pill warn" title="Saved here. It will be copied to the monthly Google Sheet automatically.">Signed – not yet filed</span>
@elseif ($fs === 'failed')<span class="pill bad">Filing to Drive failed – will retry</span>
@elseif ($fs === 'filed')<span class="pill ok">Filed to Drive</span>
@endif
