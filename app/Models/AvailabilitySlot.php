<?php

namespace App\Models;

use App\Support\AvailabilitySlotStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AvailabilitySlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'service_id',
        'therapist_id',
        'slot_date',
        'start_time',
        'end_time',
        'capacity',
        'booked_count',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'slot_date' => 'date',
            'capacity' => 'integer',
            'booked_count' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function therapist(): BelongsTo
    {
        return $this->belongsTo(Therapist::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function isBookable(): bool
    {
        return $this->status === AvailabilitySlotStatus::AVAILABLE
            && $this->booked_count < $this->capacity;
    }
}
