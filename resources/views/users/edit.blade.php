@extends('layouts.app')
@section('title', 'Edit user')
@section('page_title', 'Edit user')
@section('parent_title', 'Users')
@section('parent_url', route('users.index'))
@push('styles')<link rel="stylesheet" href="{{ asset('css/forms.css') }}?v={{ @filemtime(public_path('css/forms.css')) }}">@endpush

@section('content')
<section class="card pb-form-card">
    <h3>{{ $user->name }}</h3>
    <form method="POST" action="{{ route('users.update', $user) }}" class="pb-form pb-grid">
        @csrf
        @method('PUT')
        <div class="f-field">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" required maxlength="255">
        </div>
        <div class="f-field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" required maxlength="255">
        </div>
        <div class="f-field">
            <label for="role">Role</label>
            <select id="role" name="role" class="pb-select" required>
                @foreach ($roles as $value => $label)
                    <option value="{{ $value }}" @selected(old('role', $user->role) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="f-field">
            <label class="pb-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active))> Account active</label>
        </div>
        <div class="form-actions">
            <a href="{{ route('users.index') }}" class="pb-btn pb-btn-ghost">Cancel</a>
            <button type="submit" class="btn-save">Save changes</button>
        </div>
    </form>
</section>

<section class="card pb-form-card">
    <h3>Reset password</h3>
    <p class="pb-muted">Leave blank to generate a temporary password. The user is signed out and must choose a new password at next sign-in.</p>
    <form method="POST" action="{{ route('users.reset-password', $user) }}" class="pb-form pb-grid" onsubmit="return confirm('Reset this user\'s password?');">
        @csrf
        <div class="f-field">
            <label for="reset_password">New temporary password (optional)</label>
            <input type="password" id="reset_password" name="password" autocomplete="new-password" minlength="10">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn-save pb-danger">Reset password</button>
        </div>
    </form>
</section>
@endsection
