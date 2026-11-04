<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalItem extends Model
{
    protected $fillable = ['rental_order_id', 'rental_unit_id', 'product_name', 'unit_code', 'components', 'checkout_condition', 'rental_price', 'deposit', 'reserved_until', 'rental_end_date', 'returned_at', 'return_notes', 'return_photo_path', 'inspected_at', 'inspection_notes', 'late_fee', 'damage_fee', 'inspection_checklist', 'checkout_checklist', 'checkout_inspected_at', 'checkout_inspected_by', 'checkout_photo_path', 'checkout_state_hash', 'late_fee_calculation'];

    protected function casts(): array
    {
        return ['late_fee_calculation' => 'array', 'inspection_checklist' => 'array', 'checkout_checklist' => 'array', 'checkout_inspected_at' => 'datetime', 'reserved_until' => 'date', 'rental_end_date' => 'date', 'returned_at' => 'datetime', 'inspected_at' => 'datetime', 'rental_price' => 'decimal:2', 'deposit' => 'decimal:2', 'late_fee' => 'decimal:2', 'damage_fee' => 'decimal:2'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(RentalOrder::class, 'rental_order_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(RentalUnit::class, 'rental_unit_id');
    }

    public function checklistLabels(): array
    {
        return $this->inspection_checklist ?? $this->unit->product->inspection_checklist ?? [];
    }
}
