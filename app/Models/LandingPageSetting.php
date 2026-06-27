<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LandingPageSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_name',
        'slug',
        'is_published',
        'published_at',
        'page_settings',
        'seo_settings',
        'theme_settings',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'page_settings' => 'array',
            'seo_settings' => 'array',
            'theme_settings' => 'array',
        ];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(LandingSection::class)->orderBy('sort_order');
    }

    public function media(): HasMany
    {
        return $this->hasMany(LandingMedia::class);
    }
}
