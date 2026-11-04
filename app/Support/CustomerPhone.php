<?php

namespace App\Support;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class CustomerPhone
{
    public static function matching(string $phone): Builder
    {
        $phone = self::normalize($phone);
        // Match older CRM entries without rewriting or merging historical profiles.
        $column = 'whatsapp_number';
        foreach (['@c.us', '@s.whatsapp.net', ' ', '+', '-', '.', '(', ')'] as $separator) {
            $column = "REPLACE($column, '$separator', '')";
        }

        return Customer::query()->whereRaw("$column IN (?, ?)", [$phone, '0'.substr($phone, 2)]);
    }

    public static function normalize(string $input): string
    {
        $phone = preg_replace('/\D+/', '', $input);
        if (str_starts_with($phone, '0')) {
            $phone = '62'.substr($phone, 1);
        }
        if (! preg_match('/^62[1-9][0-9]{7,12}$/D', $phone)) {
            throw new InvalidArgumentException('Nomor WhatsApp harus nomor Indonesia yang valid, diawali 08 atau 62.');
        }

        return $phone;
    }
}
