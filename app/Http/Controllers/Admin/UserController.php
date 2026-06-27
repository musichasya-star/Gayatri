<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\UserRole;
use App\Support\UserStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $users = User::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');

                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('role'), fn ($query) => $query->where('role', $request->string('role')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'roles' => UserRole::all(),
            'statuses' => UserStatus::all(),
        ]);
    }

    public function create(): View
    {
        return view('admin.users.form', [
            'user' => new User(['role' => UserRole::ADMIN, 'status' => UserStatus::ACTIVE]),
            'roles' => UserRole::all(),
            'statuses' => UserStatus::all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedData($request);
        $data['password'] = (string) $request->string('password');

        User::create($data);

        return redirect()->route('admin.users.index')->with('status', 'User berhasil dibuat.');
    }

    public function edit(User $user): View
    {
        return view('admin.users.form', [
            'user' => $user,
            'roles' => UserRole::all(),
            'statuses' => UserStatus::all(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validatedData($request, $user);

        if ($request->filled('password')) {
            $data['password'] = (string) $request->string('password');
        }

        $user->update($data);

        return redirect()->route('admin.users.index')->with('status', 'User berhasil diperbarui.');
    }

    public function activate(User $user): RedirectResponse
    {
        $user->update(['status' => UserStatus::ACTIVE]);

        return back()->with('status', 'User berhasil diaktifkan.');
    }

    public function deactivate(User $user): RedirectResponse
    {
        if ($user->is(auth()->user())) {
            return back()->withErrors(['user' => 'Anda tidak bisa menonaktifkan akun sendiri.']);
        }

        $user->update(['status' => UserStatus::INACTIVE]);

        return back()->with('status', 'User berhasil dinonaktifkan.');
    }

    private function validatedData(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in(UserRole::all())],
            'status' => ['required', Rule::in(UserStatus::all())],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
        ]);
    }
}
