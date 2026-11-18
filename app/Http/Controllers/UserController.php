<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Admin-only user management. */
class UserController extends Controller
{
    public function index()
    {
        return view('users.index', [
            'users' => User::orderByRaw("CASE role WHEN 'admin' THEN 0 WHEN 'supervisor' THEN 1 ELSE 2 END")
                ->orderBy('name')->get(),
            'roles' => config('pbp.roles'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(User::ROLES)],
            'password' => ['nullable', 'string', Password::min(10)->mixedCase()->numbers()],
        ]);

        [$password, $generated] = $this->passwordOrGenerate($data['password'] ?? null);

        $user = User::create([
            'name' => $data['name'],
            'email' => Str::lower($data['email']),
            'role' => $data['role'],
            'password' => Hash::make($password),
            'must_change_password' => true,
            'is_active' => true,
        ]);

        return $this->backWithPassword("User {$user->email} created. They must change the password at first sign-in.", $generated ? $password : null);
    }

    public function edit(User $user)
    {
        return view('users.edit', ['user' => $user, 'roles' => config('pbp.roles')]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(User::ROLES)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $isActive = $request->boolean('is_active');

        if ($user->is($request->user()) && ($data['role'] !== User::ROLE_ADMIN || ! $isActive)) {
            return back()->withErrors(['role' => 'You cannot remove your own admin role or disable your own account.'])->withInput();
        }

        if ($user->isAdmin() && ($data['role'] !== User::ROLE_ADMIN || ! $isActive)
            && User::where('role', User::ROLE_ADMIN)->where('is_active', true)->count() <= 1) {
            return back()->withErrors(['role' => 'There must be at least one active admin.'])->withInput();
        }

        $user->update([
            'name' => $data['name'],
            'email' => Str::lower($data['email']),
            'role' => $data['role'],
            'is_active' => $isActive,
        ]);

        return redirect()->route('users.index')->with('status', "User {$user->email} updated.");
    }

    public function resetPassword(Request $request, User $user)
    {
        $data = $request->validate([
            'password' => ['nullable', 'string', Password::min(10)->mixedCase()->numbers()],
        ]);

        [$password, $generated] = $this->passwordOrGenerate($data['password'] ?? null);

        $user->forceFill([
            'password' => Hash::make($password),
            'must_change_password' => true,
            'remember_token' => Str::random(60),
        ])->save();

        // Sign the user out everywhere (database session driver).
        if (config('session.driver') === 'database' && ! $user->is($request->user())) {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        return $this->backWithPassword("Password reset for {$user->email}. They must change it at next sign-in.", $generated ? $password : null);
    }

    private function passwordOrGenerate(?string $password): array
    {
        if ($password) {
            return [$password, false];
        }

        // 16 chars, guaranteed upper/lower/digit, no ambiguous characters.
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789'];
        $all = implode('', $sets);
        $chars = [];
        foreach ($sets as $set) {
            $chars[] = $set[random_int(0, strlen($set) - 1)];
        }
        while (count($chars) < 16) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        shuffle($chars);

        return [implode('', $chars), true];
    }

    /** Shows a generated temporary password once (flash only, never logged). */
    private function backWithPassword(string $message, ?string $tempPassword)
    {
        $redirect = redirect()->route('users.index')->with('status', $message);

        return $tempPassword ? $redirect->with('temp_password', $tempPassword) : $redirect;
    }
}
