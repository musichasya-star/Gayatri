<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class RentalProduct extends Model
{
    protected $fillable = ['sku', 'name', 'category', 'description', 'components', 'terms', 'image_path', 'daily_rate', 'weekly_rate', 'fortnightly_rate', 'monthly_rate', 'deposit', 'turnaround_days', 'is_active', 'inspection_checklist', 'catalog_metadata'];

    protected function casts(): array
    {
        return ['inspection_checklist' => 'array', 'is_active' => 'boolean', 'daily_rate' => 'decimal:2', 'weekly_rate' => 'decimal:2', 'fortnightly_rate' => 'decimal:2', 'monthly_rate' => 'decimal:2', 'deposit' => 'decimal:2', 'turnaround_days' => 'integer', 'catalog_metadata' => 'array'];
    }

    public function rateOptions(): array
    {
        return array_filter(['day' => $this->daily_rate, 'week' => $this->weekly_rate, 'fortnight' => $this->fortnightly_rate, 'month' => $this->monthly_rate], fn ($rate) => $rate !== null && (float) $rate > 0);
    }

    public function startingRateLabel(): string
    {
        $rates = $this->rateOptions();
        $period = array_key_first($rates);

        return $period ? 'Rp '.number_format((float) $rates[$period], 0, ',', '.').'/'.['day' => 'hari', 'week' => 'minggu', 'fortnight' => '2 minggu', 'month' => 'bulan'][$period] : 'Tarif dikonfirmasi admin';
    }

    public function depositLabel(): string
    {
        return $this->deposit === null ? 'Deposit dikonfirmasi admin' : 'Deposit Rp '.number_format((float) $this->deposit, 0, ',', '.');
    }

    public function requiresConfirmation(): bool
    {
        return $this->deposit === null || (bool) data_get($this->catalog_metadata, 'turnaround_unconfirmed', false);
    }

    public function catalogueReadiness(): string
    {
        if (! $this->rateOptions() || $this->requiresConfirmation()) {
            return 'incomplete';
        }
        if (! (int) ($this->units_count ?? $this->units()->count())) {
            return 'empty';
        }

        return (int) ($this->ready_units_count ?? $this->units()->where('status', 'ready')->count()) > 0 ? 'ready' : 'busy';
    }

    public function catalogueMissing(): string
    {
        $missing = [];
        if (! $this->rateOptions()) {
            $missing[] = 'tarif';
        }
        if ($this->deposit === null) {
            $missing[] = 'deposit';
        }
        if (data_get($this->catalog_metadata, 'turnaround_unconfirmed')) {
            $missing[] = 'jeda pembersihan';
        }

        return $missing ? 'Lengkapi '.implode(', ', $missing).'.' : 'Ketersediaan akhir mengikuti tanggal sewa.';
    }

    public function units(): HasMany
    {
        return $this->hasMany(RentalUnit::class);
    }

    public function imageUrl(): string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : asset('images/rental-placeholder.svg');
    }
}
