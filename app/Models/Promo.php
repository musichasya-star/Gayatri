<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promo extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'code',
        'type',
        'value',
        'quota',
        'used_count',
        'description',
        'start_at',
        'end_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'quota' => 'integer',
            'used_count' => 'integer',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected function type(): Attribute
    {
        // Read legacy spelling consistently without rewriting promos or booking snapshots.
        return Attribute::make(get: fn ($value) => $value === 'percentage' ? 'percent' : $value);
    }

    public function valueLabel(): string
    {
        return $this->type === 'percent'
            ? rtrim(rtrim(number_format((float) $this->value, 2, ',', '.'), '0'), ',').'%'
            : 'Rp '.number_format((float) $this->value, 0, ',', '.');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function isUsable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->start_at && $this->start_at->isFuture()) {
            return false;
        }

        if ($this->end_at && $this->end_at->isPast()) {
            return false;
        }

        return $this->quota === null || $this->used_count < $this->quota;
    }
}
