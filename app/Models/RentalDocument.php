<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use LogicException;

class RentalDocument extends Model
{
    public const TYPE_INVOICE = 'invoice';

    public const TYPE_PAYMENT_RECEIPT = 'payment_receipt';

    public const TYPE_REFUND_RECEIPT = 'refund_receipt';

    public const TYPES = [
        self::TYPE_INVOICE => 'Invoice sewa',
        self::TYPE_PAYMENT_RECEIPT => 'Kuitansi pembayaran',
        self::TYPE_REFUND_RECEIPT => 'Bukti pengembalian dana',
    ];

    public const AUTHORIZED_ROLES = ['owner', 'manager', 'admin'];

    public const INVOICE_STATUSES = ['reserved', 'rented', 'inspection', 'completed', 'cancelled'];

    public $timestamps = false;

    protected $fillable = ['rental_order_id', 'rental_payment_id', 'type', 'number', 'version', 'fingerprint', 'snapshot', 'issued_by', 'issued_at'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'version' => 'integer', 'issued_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(RentalOrder::class, 'rental_order_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(RentalPayment::class, 'rental_payment_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** Issuance time and issuing operator do not create a new invoice version. */
    public static function fingerprintForSnapshot(array $snapshot): string
    {
        unset($snapshot['issuance']);

        $canonicalize = function (array $value) use (&$canonicalize): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => $entry) {
                if (is_array($entry)) {
                    $value[$key] = $canonicalize($entry);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($canonicalize($snapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function save(array $options = [])
    {
        if ($this->exists) {
            throw new LogicException('Dokumen terbit tidak dapat diubah. Terbitkan snapshot invoice baru jika transaksi berubah.');
        }
        $invoice = $this->type === self::TYPE_INVOICE;
        if (! isset(self::TYPES[$this->type]) || ! is_array($this->snapshot)
            || ! hash_equals(self::fingerprintForSnapshot($this->snapshot), (string) $this->fingerprint)
            || ($invoice && ($this->rental_payment_id !== null || (int) $this->version < 1))
            || (! $invoice && ($this->rental_payment_id === null || $this->version !== null))) {
            throw new InvalidArgumentException('Jenis, versi, atau fingerprint dokumen tidak valid.');
        }

        return parent::save($options);
    }

    public function delete()
    {
        throw new LogicException('Dokumen terbit tidak dapat dihapus.');
    }

    /** Also guard ordinary Eloquent bulk writes, which bypass model events. */
    public function newEloquentBuilder($query)
    {
        return new class($query) extends Builder
        {
            public function update(array $values)
            {
                throw new LogicException('Dokumen terbit tidak dapat diubah.');
            }

            public function upsert(array $values, $uniqueBy, $update = null)
            {
                throw new LogicException('Dokumen terbit tidak dapat ditimpa.');
            }

            public function increment($column, $amount = 1, array $extra = [])
            {
                throw new LogicException('Dokumen terbit tidak dapat diubah.');
            }

            public function decrement($column, $amount = 1, array $extra = [])
            {
                throw new LogicException('Dokumen terbit tidak dapat diubah.');
            }

            public function delete()
            {
                throw new LogicException('Dokumen terbit tidak dapat dihapus.');
            }

            public function forceDelete()
            {
                throw new LogicException('Dokumen terbit tidak dapat dihapus.');
            }
        };
    }
}
