<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BranchRequest;
use App\Models\Branch;
use App\Services\CRM\BranchService;
use App\Support\UserStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function __construct(private readonly BranchService $branchService) {}

    public function index(Request $request): View
    {
        $branches = Branch::query()
            ->withCount(['customers', 'services', 'therapists'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');

                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.branches.index', [
            'branches' => $branches,
            'statuses' => UserStatus::all(),
        ]);
    }

    public function create(): View
    {
        return view('admin.branches.form', [
            'branch' => new Branch(['status' => UserStatus::ACTIVE, 'timezone' => 'Asia/Jakarta']),
            'statuses' => UserStatus::all(),
        ]);
    }

    public function store(BranchRequest $request): RedirectResponse
    {
        $this->branchService->create($request->validated(), $request->user(), $request);

        return redirect()->route('admin.branches.index')->with('status', 'Cabang berhasil dibuat.');
    }

    public function edit(Branch $branch): View
    {
        return view('admin.branches.form', [
            'branch' => $branch,
            'statuses' => UserStatus::all(),
        ]);
    }

    public function update(BranchRequest $request, Branch $branch): RedirectResponse
    {
        $this->branchService->update($branch, $request->validated(), $request->user(), $request);

        return redirect()->route('admin.branches.index')->with('status', 'Cabang berhasil diperbarui.');
    }

    public function archive(Request $request, Branch $branch): RedirectResponse
    {
        $this->branchService->archive($branch, $request->user(), $request);

        return redirect()->route('admin.branches.index')->with('status', 'Cabang berhasil diarsipkan.');
    }
}
