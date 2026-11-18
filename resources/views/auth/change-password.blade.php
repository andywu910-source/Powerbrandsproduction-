@extends('layouts.app')
@section('title', 'Change password')
@section('page_title', 'Change password')
@push('styles')<link rel="stylesheet" href="{{ asset('css/forms.css') }}?v={{ @filemtime(public_path('css/forms.css')) }}">@endpush

@section('content')
<section class="card pb-form-card">
    <h3>{{ $forced ? 'Choose a new password' : 'Change your password' }}</h3>
    @if ($forced)
        <p class="pb-muted">For security, you need to replace your temporary password before using the site.</p>
    @endif

    <form method="POST" action="{{ route('password.update') }}" class="pb-form">
        @csrf
        @method('PUT')
        <div class="f-field">
            <label for="current_password">Current password</label>
            <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
        </div>
        <div class="f-field">
            <label for="password">New password</label>
            <input type="password" id="password" name="password" autocomplete="new-password" required minlength="10">
            <p class="hint">At least 10 characters, with upper and lower case letters and a number.</p>
        </div>
        <div class="f-field">
            <label for="password_confirmation">Confirm new password</label>
            <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn-save">Save password</button>
        </div>
    </form>
</section>
@endsection
