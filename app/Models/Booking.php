<?php

namespace App\Models;

use App\Support\CustomerPhone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Booking $booking) {
            $booking->captureContactSnapshot();
        });
        static::updating(function (Booking $booking) {
            if ($booking->isDirty('customer_id')) {
                $booking->captureContactSnapshot();
            }
        });
    }

    public function captureContactSnapshot(): bool
    {
        try {
            $phone = CustomerPhone::normalize((string) Customer::find($this->customer_id)?->whatsapp_number);
            $this->contact_snapshot = ['version' => 1, 'customer_id' => (int) $this->customer_id,
                'whatsapp_number' => $phone, 'captured_at' => now()->toIso8601String()];

            return true;
        } catch (\Throwable) {
            $this->contact_snapshot = null;

            return false;
        }
    }

    protected $fillable = [
        'customer_id',
        'branch_id',
        'service_id',
        'therapist_id',
        'conversation_id',
        'created_by',
        'promo_id',
        'availability_slot_id',
        'booking_code',
        'booking_date',
        'start_time',
        'end_time',
        'status',
        'payment_status',
        'source',
        'promo_discount',
        'notes',
        'public_request_data', 'contact_verified_at', 'contact_verified_by', 'contact_verification_reference', 'finance_managed', 'finance_revision',
        'legacy_credit_cents', 'legacy_payment_status', 'finance_opened_at',
        'service_mode', 'visit_address', 'visit_landmark', 'visit_latitude', 'visit_longitude',
        'travel_fee', 'home_service_quote', 'service_price_snapshot', 'busy_from', 'busy_until',
    ];

    protected function casts(): array
    {
        return [
            'booking_date' => 'date',
            'contact_snapshot' => 'array', 'is_demo' => 'boolean',
            'public_request_data' => 'array', 'contact_verified_at' => 'datetime', 'finance_managed' => 'boolean', 'finance_revision' => 'integer',
            'legacy_credit_cents' => 'integer', 'finance_opened_at' => 'datetime',
            'promo_discount' => 'decimal:2',
            'home_service_quote' => 'array',
            'travel_fee' => 'decimal:2',
            'service_price_snapshot' => 'decimal:2',
            'busy_from' => 'datetime',
            'busy_until' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BookingPayment::class);
    }

    public function therapistFee(): HasOne
    {
        return $this->hasOne(TherapistFee::class);
    }

    public function totalPrice(): float
    {
        return max(0, (float) ($this->service_price_snapshot ?? $this->service?->price ?? 0) + (float) $this->addOns->sum('price') - (float) $this->promo_discount) + (float) $this->travel_fee;
    }

    public function navigationUrl(): ?string
    {
        return $this->service_mode === 'home' ? 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($this->visit_latitude.','.$this->visit_longitude) : null;
    }

    public function homeVisitRevision(): string
    {
        return hash('sha256', json_encode([
            $this->id, $this->service_mode, $this->status, (int) $this->therapist_id, (int) $this->service_id,
            $this->booking_date?->toDateString(), substr($this->start_time, 0, 5), substr($this->end_time, 0, 5),
            $this->visit_address, $this->visit_landmark, (float) $this->visit_latitude, (float) $this->visit_longitude,
            (float) $this->travel_fee, $this->busy_from?->toDateTimeString(), $this->busy_until?->toDateTimeString(),
        ]));
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

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class);
    }

    public function availabilitySlot(): BelongsTo
    {
        return $this->belongsTo(AvailabilitySlot::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    public function addOns(): HasMany
    {
        return $this->hasMany(BookingAddon::class);
    }
}
