<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TherapistFee extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['service_date' => 'date', 'snapshot' => 'array', 'is_demo' => 'boolean', 'base_cents' => 'integer', 'rate_bps' => 'integer', 'fee_cents' => 'integer'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function therapist(): BelongsTo
    {
        return $this->belongsTo(Therapist::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
