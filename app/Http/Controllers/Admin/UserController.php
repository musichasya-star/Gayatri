<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AdminReviewContext;
use App\Support\UserRole;
use App\Support\UserStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $request->validate(['search' => ['nullable', 'string', 'max:150'], 'page' => ['nullable', 'integer', 'min:1'], 'role' => ['nullable', Rule::in(UserRole::all())], 'status' => ['nullable', Rule::in(UserStatus::all())]]);
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

        return redirect(AdminReviewContext::back($request, 'users'))->with('status', 'User berhasil dibuat.');
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

        if ($request->filled('password') || $user->role !== $data['role'] || $user->email !== $data['email'] || $user->status !== $data['status']) {
            $data['auth_version'] = $user->auth_version + 1;
            $data['remember_token'] = Str::random(60);
        }

        $user->forceFill($data)->save();

        return redirect(AdminReviewContext::back($request, 'users'))->with('status', 'User berhasil diperbarui.');
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

        $user->forceFill(['status' => UserStatus::INACTIVE, 'auth_version' => $user->auth_version + 1, 'remember_token' => Str::random(60)])->save();

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
