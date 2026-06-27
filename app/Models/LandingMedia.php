<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingMedia extends Model
{
    use HasFactory;

    protected $table = 'landing_media';

    protected $fillable = [
        'landing_page_setting_id',
        'name',
        'type',
        'url',
        'alt_text',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(LandingPageSetting::class, 'landing_page_setting_id');
    }
}
