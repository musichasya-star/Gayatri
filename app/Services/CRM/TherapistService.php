<?php

namespace App\Services\CRM;

use App\Models\Therapist;
use App\Models\User;
use Illuminate\Http\Request;

class TherapistService
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function create(array $data, ?User $actor, Request $request): Therapist
    {
        $therapist = Therapist::create($data);

        $this->auditLogService->log(
            $actor,
            'therapist.created',
            $therapist,
            $request,
            [],
            $therapist->fresh()->toArray(),
            'Membuat data terapis.'
        );

        return $therapist;
    }

    public function update(Therapist $therapist, array $data, ?User $actor, Request $request): Therapist
    {
        $oldValues = $therapist->getOriginal();
        $therapist->update($data);

        $this->auditLogService->log(
            $actor,
            'therapist.updated',
            $therapist,
            $request,
            $oldValues,
            $therapist->fresh()->toArray(),
            'Memperbarui data terapis.'
        );

        return $therapist;
    }

    public function archive(Therapist $therapist, ?User $actor, Request $request): Therapist
    {
        $oldValues = $therapist->getOriginal();
        $therapist->update(['status' => 'inactive']);

        $this->auditLogService->log(
            $actor,
            'therapist.archived',
            $therapist,
            $request,
            $oldValues,
            $therapist->fresh()->toArray(),
            'Mengarsipkan data terapis.'
        );

        return $therapist;
    }
}
