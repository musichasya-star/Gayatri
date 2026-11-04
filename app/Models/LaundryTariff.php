<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LaundryTariff extends Model
{
    public const AUDIENCES = ['mother' => 'Ibu', 'baby' => 'Bayi', 'both' => 'Ibu & Bayi'];

    public const UNITS = ['kg' => 'Kilogram', 'piece' => 'Satuan'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['price' => 'integer', 'minimum_milli' => 'integer', 'increment_milli' => 'integer', 'duration_hours' => 'integer', 'is_active' => 'boolean', 'requires_confirmation' => 'boolean', 'catalog_metadata' => 'array'];
    }

    public function snapshot(): array
    {
        return $this->only(['id', 'code', 'name', 'audience', 'unit', 'price', 'minimum_milli', 'increment_milli', 'duration_hours', 'description', 'requires_confirmation']);
    }

    public function catalogueReadiness(): string
    {
        if (! $this->minimum_milli || ! $this->increment_milli || ! $this->duration_hours || $this->price <= 0) {
            return 'incomplete';
        }

        return $this->requires_confirmation ? 'admin' : 'ready';
    }

    public function catalogueMissing(): string
    {
        $missing = [];
        if (! $this->minimum_milli) {
            $missing[] = 'minimum';
        }
        if (! $this->increment_milli) {
            $missing[] = 'pembulatan';
        }
        if (! $this->duration_hours) {
            $missing[] = 'estimasi pengerjaan';
        }

        return $missing ? 'Lengkapi '.implode(', ', $missing).'.' : ($this->requires_confirmation ? 'Konfirmasi rincian sebelum penerimaan.' : 'Siap digunakan untuk penerimaan baru.');
    }
}
