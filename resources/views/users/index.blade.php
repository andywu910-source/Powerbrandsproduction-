@extends('layouts.app')
@section('title', 'Users')
@section('page_title', 'Users')
@section('parent_title', 'Administration')
@push('styles')<link rel="stylesheet" href="{{ asset('css/forms.css') }}?v={{ @filemtime(public_path('css/forms.css')) }}">@endpush

@section('content')
@if (session('temp_password'))
    <div class="pb-alert pb-alert-warn" role="status">
        Temporary password (shown once, copy it now and share it privately):
        <code class="pb-temp">{{ session('temp_password') }}</code>
    </div>
@endif

<section class="card">
    <div class="card-head"><h3>Team members</h3><span class="pill">{{ $users->count() }}</span></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last sign-in</th><th class="r">Actions</th></tr>
            </thead>
            <tbody>
            @foreach ($users as $u)
                <tr>
                    <td>{{ $u->name }}@if ($u->is(auth()->user())) <small class="pb-muted">(you)</small>@endif</td>
                    <td class="mono">{{ $u->email }}</td>
                    <td><span class="pb-role pb-role-{{ $u->role }}">{{ $u->roleLabel() }}</span></td>
                    <td>
                        @if (! $u->is_active)<span class="badge cancelled">Disabled</span>
                        @elseif ($u->must_change_password)<span class="badge pending">Must change password</span>
                        @else<span class="badge completed">Active</span>@endif
                    </td>
                    <td>{{ $u->last_login_at ? $u->last_login_at->timezone('America/New_York')->format('M j, Y g:i A') : 'Never' }}</td>
                    <td class="r"><a class="pb-btn pb-btn-ghost" href="{{ route('users.edit', $u) }}">Edit</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>

<section class="card pb-form-card">
    <h3>Add a user</h3>
    <p class="pb-muted">Leave the password blank to generate a temporary one. New users must change it at first sign-in.</p>
    <form method="POST" action="{{ route('users.store') }}" class="pb-form pb-grid">
        @csrf
        <div class="f-field">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" value="{{ old('name') }}" required maxlength="255">
        </div>
        <div class="f-field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required maxlength="255">
        </div>
        <div class="f-field">
            <label for="role">Role</label>
            <select id="role" name="role" class="pb-select" required>
                @foreach ($roles as $value => $label)
                    <option value="{{ $value }}" @selected(old('role', 'operator') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="f-field">
            <label for="new_password">Temporary password (optional)</label>
            <input type="password" id="new_password" name="password" autocomplete="new-password" minlength="10">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn-save">Create user</button>
        </div>
    </form>
</section>
@endsection
