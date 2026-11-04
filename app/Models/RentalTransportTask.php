<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalTransportTask extends Model
{
    public const TYPES = ['delivery' => 'Pengantaran', 'collection' => 'Penjemputan'];

    public const STATUSES = ['scheduled' => 'Terjadwal', 'in_progress' => 'Dalam perjalanan', 'completed' => 'Selesai', 'cancelled' => 'Dibatalkan'];

    public const ACTIVE_STATUSES = ['scheduled', 'in_progress'];

    public const STAFF_ROLES = ['owner', 'manager', 'admin'];

    public const TIMEZONE = 'Asia/Jakarta';

    protected $fillable = [
        'rental_order_id', 'type', 'status', 'assigned_user_id', 'created_by', 'updated_by',
        'scheduled_start', 'scheduled_end', 'schedule_version', 'version', 'notes',
        'recipient_name', 'completion_notes', 'proof_photo_path', 'started_at', 'completed_at',
        'cancelled_at', 'cancellation_notes', 'mutation_receipts',
    ];

    protected $hidden = ['proof_photo_path', 'mutation_receipts'];

    protected function casts(): array
    {
        return [
            'scheduled_start' => 'immutable_datetime', 'scheduled_end' => 'immutable_datetime',
            'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime', 'schedule_version' => 'integer',
            'version' => 'integer', 'mutation_receipts' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(RentalOrder::class, 'rental_order_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function hasStaleSchedule(?RentalOrder $order = null): bool
    {
        return $this->type === 'collection'
            && $this->schedule_version !== (int) ($order ?? $this->order)->schedule_version;
    }

    public function isOverdue(): bool
    {
        return $this->isActive() && $this->scheduled_end->isPast();
    }
}
