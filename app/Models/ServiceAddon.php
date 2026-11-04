<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class ServiceAddon extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id',
        'addon_service_id',
        'addon_name',
        'duration_minutes',
        'price_adjustment',
        'is_active',
        'requires_confirmation',
    ];

    protected function casts(): array
    {
        return [
            'price_adjustment' => 'decimal:2',
            'is_active' => 'boolean',
            'requires_confirmation' => 'boolean',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function scopeWithinServiceGroup(Builder $query): Builder
    {
        return $query->where(function ($query) {
            $query->whereNull('addon_service_id')->orWhereExists(
                DB::table('services as main_service')
                    ->join('services as extra_service', 'extra_service.id', '=', 'service_addons.addon_service_id')
                    ->whereColumn('main_service.id', 'service_addons.service_id')
                    ->whereColumn('main_service.service_group', 'extra_service.service_group')
                    ->where('extra_service.is_active', true)
                    ->selectRaw('1')
            );
        });
    }

    public function addonService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'addon_service_id');
    }

    public function displayName(): string
    {
        return $this->addonService?->name ?: (string) $this->addon_name;
    }
}
