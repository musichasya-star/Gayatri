<?php

namespace App\Models;

use App\Support\ServiceGroup;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Service extends Model
{
    use HasFactory;

    public const KINDS = ['treatment' => 'Treatment utama', 'package' => 'Paket', 'class' => 'Kelas', 'addon' => 'Tambahan'];

    public const READINESS = ['ready' => 'Siap dipesan', 'admin' => 'Konfirmasi admin', 'incomplete' => 'Perlu dilengkapi'];

    protected $attributes = ['service_group' => ServiceGroup::SPA];

    protected $fillable = [
        'branch_id',
        'name',
        'category',
        'service_group',
        'duration_minutes',
        'price',
        'is_active',
        'description',
        'catalog_code', 'catalog_family', 'variant_label', 'booking_mode', 'catalog_metadata', 'image_path',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'catalog_metadata' => 'array',
            'duration_minutes' => 'integer',
        ];
    }

    public function groupLabel(): string
    {
        return ServiceGroup::LABELS[$this->service_group ?? ServiceGroup::SPA] ?? ServiceGroup::LABELS[ServiceGroup::SPA];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function addOns(): HasMany
    {
        return $this->hasMany(ServiceAddon::class);
    }

    public function activeAddOns(): HasMany
    {
        return $this->addOns()->where('is_active', true)->where('requires_confirmation', false)->withinServiceGroup();
    }

    public function requiresManualBooking(): bool
    {
        return $this->booking_mode === 'admin' || $this->duration_minutes === null || $this->price === null
            || (int) data_get($this->catalog_metadata, 'package_quantity', 1) > 1
            || in_array(data_get($this->catalog_metadata, 'role'), ['addon', 'bundle'], true)
            || in_array(data_get($this->catalog_metadata, 'kind'), ['addon', 'package'], true);
    }

    public function scopeBookable($query)
    {
        return $query->where('booking_mode', 'online')->whereNotNull('duration_minutes')->whereNotNull('price')
            ->where(fn ($q) => $q->whereNull('catalog_metadata->package_quantity')->orWhere('catalog_metadata->package_quantity', '<=', 1))
            ->where(fn ($q) => $q->whereNull('catalog_metadata->role')->orWhereNotIn('catalog_metadata->role', ['addon', 'bundle']))
            ->where(fn ($q) => $q->whereNull('catalog_metadata->kind')->orWhereNotIn('catalog_metadata->kind', ['addon', 'package']));
    }

    public function familyName(): string
    {
        return trim((string) $this->catalog_family) ?: (string) $this->name;
    }

    public function catalogueKind(): string
    {
        $role = data_get($this->catalog_metadata, 'role');
        if ($role === 'addon') {
            return 'addon';
        }
        if ($role === 'bundle' || (int) data_get($this->catalog_metadata, 'package_quantity', 1) > 1) {
            return 'package';
        }
        $kind = data_get($this->catalog_metadata, 'kind');
        if (isset(self::KINDS[$kind])) {
            return $kind;
        }

        return str_contains(mb_strtolower($this->category.' '.$this->familyName()), 'kelas') ? 'class' : 'treatment';
    }

    public function readiness(): string
    {
        return $this->price === null || $this->duration_minutes === null ? 'incomplete' : ($this->requiresManualBooking() ? 'admin' : 'ready');
    }

    public function readinessReason(): string
    {
        $missing = [];
        if ($this->price === null) {
            $missing[] = 'harga';
        }
        if ($this->duration_minutes === null) {
            $missing[] = 'durasi';
        }
        if ($missing) {
            return 'Lengkapi '.implode(' dan ', $missing).'.';
        }
        if (in_array($this->catalogueKind(), ['package', 'addon'], true)) {
            return 'Paket dan tambahan diatur bersama admin, bukan satu booking biasa.';
        }

        return $this->requiresManualBooking() ? 'Alur konfirmasi admin dipilih.' : 'Harga dan durasi tersedia; pemesanan mengikuti jadwal yang dibuka.';
    }

    public function durationLabel(): string
    {
        return $this->duration_minutes === null ? 'Durasi dikonfirmasi admin' : $this->duration_minutes.' menit'.((int) data_get($this->catalog_metadata, 'package_quantity', 1) > 1 ? ' per sesi' : '');
    }

    public function priceLabel(): string
    {
        return $this->price === null ? 'Harga dikonfirmasi admin' : 'Rp '.number_format((float) $this->price, 0, ',', '.');
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}
