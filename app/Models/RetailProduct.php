<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class RetailProduct extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['price' => 'integer', 'compare_at_price' => 'integer', 'is_active' => 'boolean', 'catalog_metadata' => 'array'];
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}
