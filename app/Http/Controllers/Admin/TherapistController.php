<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TherapistRequest;
use App\Models\Branch;
use App\Models\Therapist;
use App\Models\User;
use App\Services\CRM\TherapistService;
use App\Support\UserRole;
use App\Support\UserStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TherapistController extends Controller
{
    public function __construct(private readonly TherapistService $therapistService) {}

    public function index(Request $request): View
    {
        $therapists = Therapist::query()
            ->with(['branch', 'user'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');

                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('specialization', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.therapists.index', [
            'therapists' => $therapists,
            'statuses' => UserStatus::all(),
        ]);
    }

    public function create(): View
    {
        return view('admin.therapists.form', [
            'therapist' => new Therapist(['status' => UserStatus::ACTIVE]),
            'branches' => Branch::orderBy('name')->get(),
            'users' => $this->therapistUsers(),
            'statuses' => UserStatus::all(),
        ]);
    }

    public function store(TherapistRequest $request): RedirectResponse
    {
        $this->therapistService->create($request->validated(), $request->user(), $request);

        return redirect()->route('admin.therapists.index')->with('status', 'Terapis berhasil dibuat.');
    }

    public function edit(Therapist $therapist): View
    {
        return view('admin.therapists.form', [
            'therapist' => $therapist,
            'branches' => Branch::orderBy('name')->get(),
            'users' => $this->therapistUsers($therapist),
            'statuses' => UserStatus::all(),
        ]);
    }

    public function update(TherapistRequest $request, Therapist $therapist): RedirectResponse
    {
        $this->therapistService->update($therapist, $request->validated(), $request->user(), $request);

        return redirect()->route('admin.therapists.index')->with('status', 'Terapis berhasil diperbarui.');
    }

    public function archive(Request $request, Therapist $therapist): RedirectResponse
    {
        $this->therapistService->archive($therapist, $request->user(), $request);

        return redirect()->route('admin.therapists.index')->with('status', 'Terapis berhasil diarsipkan.');
    }

    private function therapistUsers(?Therapist $therapist = null)
    {
        return User::query()
            ->where('role', UserRole::THERAPIST)
            ->where(function ($query) use ($therapist) {
                $query->whereDoesntHave('therapistProfile');

                if ($therapist?->user_id) {
                    $query->orWhereKey($therapist->user_id);
                }
            })
            ->orderBy('name')
            ->get();
    }
}
