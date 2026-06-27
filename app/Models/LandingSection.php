<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'landing_page_setting_id',
        'type',
        'title',
        'subtitle',
        'icon',
        'sort_order',
        'is_active',
        'content',
        'items',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'content' => 'array',
            'items' => 'array',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(LandingPageSetting::class, 'landing_page_setting_id');
    }
}
