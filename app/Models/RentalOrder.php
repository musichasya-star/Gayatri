<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RentalOrder extends Model
{
    public const STATUSES = ['pending' => 'Menunggu konfirmasi', 'reserved' => 'Dipesan', 'rented' => 'Sedang disewa', 'inspection' => 'Pemeriksaan pengembalian', 'completed' => 'Selesai', 'cancelled' => 'Dibatalkan'];

    public const FULFILMENTS = ['self' => 'Ambil & kembalikan sendiri', 'delivery' => 'Diantar saja', 'collection' => 'Dijemput saat selesai', 'both' => 'Diantar & dijemput'];

    public const PERIODS = ['day' => 'Harian', 'week' => 'Mingguan (7 hari)', 'fortnight' => 'Dua Minggu (14 hari)', 'month' => 'Bulanan (30 hari)'];

    protected $fillable = ['code', 'quote_id', 'request_session_hash', 'customer_id', 'created_by', 'source', 'status', 'start_date', 'end_date', 'period', 'period_count', 'hold_expires_at', 'fulfilment', 'address', 'landmark', 'latitude', 'longitude', 'rental_subtotal', 'deposit_total', 'delivery_fee', 'collection_fee', 'pricing_snapshot', 'notes', 'dispatch_notes', 'dispatched_at', 'completed_at', 'schedule_version', 'portal_token_hash', 'portal_expires_at', 'portal_version', 'customer_snapshot', 'payment_terms', 'dp_due_at', 'balance_due_at', 'terms_snapshot', 'terms_accepted_at', 'terms_acceptance_source', 'cancellation_fee', 'cancellation_snapshot', 'is_demo', 'portal_token_secret', 'notification_phone', 'notification_verified_at', 'notification_verified_by', 'notification_contact_version'];

    protected $hidden = ['request_session_hash', 'portal_token_hash', 'portal_token_secret'];

    protected function casts(): array
    {
        return ['is_demo' => 'boolean', 'portal_token_secret' => 'encrypted', 'notification_verified_at' => 'datetime', 'notification_contact_version' => 'integer', 'terms_snapshot' => 'array', 'terms_accepted_at' => 'datetime', 'cancellation_fee' => 'decimal:2', 'cancellation_snapshot' => 'array', 'portal_expires_at' => 'datetime', 'portal_version' => 'integer', 'customer_snapshot' => 'array', 'payment_terms' => 'array', 'dp_due_at' => 'datetime', 'balance_due_at' => 'datetime', 'start_date' => 'date', 'end_date' => 'date', 'hold_expires_at' => 'datetime', 'dispatched_at' => 'datetime', 'completed_at' => 'datetime', 'pricing_snapshot' => 'array', 'rental_subtotal' => 'decimal:2', 'deposit_total' => 'decimal:2', 'delivery_fee' => 'decimal:2', 'collection_fee' => 'decimal:2'];
    }

    public function scopeForReport($query, bool $includeDemo = false)
    {
        return $query->when(! $includeDemo, fn ($q) => $q->where('is_demo', false));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RentalItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(RentalPayment::class);
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(RentalExtension::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(RentalNotification::class);
    }

    public function transportTasks(): HasMany
    {
        return $this->hasMany(RentalTransportTask::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(RentalDocument::class);
    }

    public function paymentProofs(): HasMany
    {
        return $this->hasMany(RentalPaymentProof::class);
    }

    public function extensionRequests(): HasMany
    {
        return $this->hasMany(RentalExtensionRequest::class);
    }

    public function customerRequests(): HasMany
    {
        return $this->hasMany(RentalCustomerRequest::class);
    }

    public function isExpired(): bool
    {
        return $this->status === 'pending' && $this->hold_expires_at?->isPast();
    }

    public function isOverdue(): bool
    {
        return $this->status === 'rented' && $this->end_date->lt(today()) && $this->items->contains(fn ($item) => ! $item->returned_at);
    }

    public function charges(): float
    {
        return $this->status === 'cancelled' ? (float) $this->cancellation_fee : (float) $this->rental_subtotal + (float) $this->delivery_fee + (float) $this->collection_fee + $this->items->sum(fn ($item) => (float) $item->late_fee + (float) $item->damage_fee);
    }

    public function originalTotal(): ?float
    {
        // Only the original snapshot can establish the amount at booking time.
        // Current balances may include extensions, penalties or released deposits.
        $snapshot = $this->pricing_snapshot ?? [];
        if (isset($snapshot['total']) && is_numeric($snapshot['total'])) {
            return (float) $snapshot['total'];
        }

        $components = ['rental_subtotal', 'deposit_total', 'delivery_fee', 'collection_fee'];
        foreach ($components as $key) {
            if (! isset($snapshot[$key]) || ! is_numeric($snapshot[$key])) {
                return null;
            }
        }

        return array_sum(array_map(fn ($key) => (float) $snapshot[$key], $components));
    }

    public function totalDue(): float
    {
        return $this->charges() + (in_array($this->status, ['completed', 'cancelled'], true) ? 0 : (float) $this->deposit_total);
    }

    public function received(): float
    {
        return (float) $this->payments->where('kind', 'payment')->sum('amount') - (float) $this->payments->where('kind', 'refund')->sum('amount');
    }

    public function balance(): float
    {
        return max(0, $this->totalDue() - $this->received());
    }

    public function refundable(): float
    {
        return in_array($this->status, ['completed', 'cancelled'], true) ? max(0, $this->received() - $this->charges()) : 0;
    }

    public function navigationUrl(): ?string
    {
        return $this->latitude !== null ? 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($this->latitude.','.$this->longitude) : null;
    }
}
