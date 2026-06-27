<?php

namespace App\Services\CRM;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;

class BranchService
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function create(array $data, ?User $actor, Request $request): Branch
    {
        $branch = Branch::create($data);

        $this->auditLogService->log(
            $actor,
            'branch.created',
            $branch,
            $request,
            [],
            $branch->fresh()->toArray(),
            'Membuat data cabang.'
        );

        return $branch;
    }

    public function update(Branch $branch, array $data, ?User $actor, Request $request): Branch
    {
        $oldValues = $branch->getOriginal();
        $branch->update($data);

        $this->auditLogService->log(
            $actor,
            'branch.updated',
            $branch,
            $request,
            $oldValues,
            $branch->fresh()->toArray(),
            'Memperbarui data cabang.'
        );

        return $branch;
    }

    public function archive(Branch $branch, ?User $actor, Request $request): Branch
    {
        $oldValues = $branch->getOriginal();
        $branch->update(['status' => 'inactive']);

        $this->auditLogService->log(
            $actor,
            'branch.archived',
            $branch,
            $request,
            $oldValues,
            $branch->fresh()->toArray(),
            'Mengarsipkan data cabang.'
        );

        return $branch;
    }
}
