<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaundryQualityCheck extends Model
{
    public const CHECKS = ['label_matched' => 'Label kantong cocok', 'clean' => 'Kebersihan sesuai', 'condition_ok' => 'Kondisi barang sesuai', 'complete' => 'Kelengkapan sesuai', 'packed' => 'Kemasan siap diserahkan'];

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['passed' => 'boolean', 'items' => 'array', 'revision' => 'integer', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
