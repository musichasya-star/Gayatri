<?php

namespace App\Services\CRM;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogService
{
    public function log(?User $user, string $action, ?Model $model, Request $request, array $oldValues = [], array $newValues = [], ?string $description = null): void
    {
        AuditLog::create([
            'user_id' => $user?->id,
            'auditable_type' => $model ? $model::class : null,
            'auditable_id' => $model?->getKey(),
            'action' => $action,
            'description' => $description,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }
}
