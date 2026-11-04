<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LaundryCourier extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
