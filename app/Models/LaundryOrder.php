<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LaundryOrder extends Model
{
    public const STATUSES = ['received' => 'Diterima / belum ditimbang', 'awaiting_approval' => 'Menunggu persetujuan nilai jasa', 'approved' => 'Nilai jasa disetujui', 'cancelled' => 'Dibatalkan'];

    public const MODES = ['walkin' => 'Diterima di outlet', 'collection' => 'Diterima melalui penjemputan'];

    public const WORKFLOW = ['pending' => 'Belum masuk antrean', 'queued' => 'Antrean pengerjaan', 'washing' => 'Dicuci', 'drying' => 'Dikeringkan', 'finishing' => 'Disetrika / dilipat', 'packing' => 'Dikemas', 'quality_check' => 'Pemeriksaan kualitas', 'ready' => 'Siap diambil', 'handed_over' => 'Sudah diserahkan', 'cancelled' => 'Dibatalkan'];

    public const STEPS = ['washing' => 'Cuci', 'drying' => 'Keringkan', 'finishing' => 'Setrika / lipat', 'packing' => 'Kemas'];

    public const EVENT_LABELS = ['proof_uploaded' => 'Bukti pelanggan diunggah', 'proof_approved' => 'Bukti pembayaran disetujui', 'proof_rejected' => 'Bukti pembayaran ditolak', 'received' => 'Penerimaan dicatat', 'weighed' => 'Hasil timbang dicatat', 'approved' => 'Persetujuan nilai jasa dicatat', 'cancelled' => 'Penerimaan dibatalkan', 'work_started' => 'Masuk antrean pengerjaan', 'stage_changed' => 'Tahap pengerjaan diperbarui', 'quality_passed' => 'Pemeriksaan kualitas lulus', 'quality_rework' => 'Perbaikan / cuci ulang diminta', 'handed_over' => 'Barang diserahkan', 'payment_recorded' => 'Pembayaran manual dicatat', 'payment_voided' => 'Pencatatan pembayaran dikoreksi', 'invoice_issued' => 'Invoice diterbitkan', 'document_voided' => 'Invoice dibatalkan', 'transport_fee' => 'Kesepakatan ongkir laundry'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['customer_snapshot' => 'array', 'outlet_snapshot' => 'array', 'received_at' => 'datetime', 'estimated_ready_at' => 'datetime', 'approved_at' => 'datetime', 'estimated_total' => 'integer', 'actual_total' => 'integer', 'revision' => 'integer', 'is_demo' => 'boolean', 'pickup_fee' => 'integer', 'delivery_fee' => 'integer', 'transport_pricing' => 'array', 'process_steps' => 'array', 'processing_started_at' => 'datetime', 'ready_at' => 'datetime', 'handed_over_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(LaundryOrderItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(LaundryOrderEvent::class);
    }

    public function qualityChecks(): HasMany
    {
        return $this->hasMany(LaundryQualityCheck::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(LaundryPayment::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(LaundryDocument::class);
    }

    public function transportTasks(): HasMany
    {
        return $this->hasMany(LaundryTransportTask::class);
    }

    public function publicRequest(): HasOne
    {
        return $this->hasOne(LaundryPublicRequest::class);
    }

    public function paymentProofs(): HasMany
    {
        return $this->hasMany(LaundryPaymentProof::class);
    }

    public function pricingFingerprint(): string
    {
        return hash('sha256', json_encode(['service_total' => $this->actual_total, 'pickup_fee' => (int) $this->pickup_fee, 'delivery_fee' => (int) $this->delivery_fee, 'delivery_required' => $this->publicRequest()->value('delivery_required'),
            'items' => $this->items()->orderBy('id')->get()->map(fn ($item) => $item->only(['id', 'bag_code', 'actual_pieces', 'actual_milli', 'actual_billable_milli', 'actual_amount']))->all()]));
    }

    public function totalDue(): ?int
    {
        return $this->actual_total === null ? null : $this->actual_total + (int) $this->pickup_fee + (int) $this->delivery_fee;
    }

    public function paidAmount(): int
    {
        return (int) ($this->relationLoaded('payments') ? $this->payments->whereNull('voided_at')->sum('amount') : $this->payments()->whereNull('voided_at')->sum('amount'));
    }

    public function balance(): ?int
    {
        if ($this->status === 'cancelled') {
            return 0;
        }

        return $this->totalDue() === null ? null : $this->totalDue() - $this->paidAmount();
    }

    public function canReweigh(): bool
    {
        return $this->status !== 'cancelled' && $this->workflow_status === 'pending' && ! $this->payments()->whereNull('voided_at')->exists() && ! $this->documents()->whereNull('voided_at')->exists() && ! $this->paymentProofs()->where('status', 'pending')->exists();
    }

    public function deadlineWarning(): ?string
    {
        if ($this->status === 'cancelled' || in_array($this->workflow_status, ['ready', 'handed_over'], true)) {
            return null;
        }
        if ($this->estimated_ready_at->isPast()) {
            return 'Melewati estimasi selesai';
        }

        return $this->estimated_ready_at->lte(now()->addHours(24)) ? 'Estimasi selesai dalam 24 jam' : null;
    }
}
