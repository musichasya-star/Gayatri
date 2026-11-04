<?php

namespace App\Support;

class ServiceGroup
{
    public const SPA = 'spa';

    public const LACTATION = 'lactation';

    public const LABELS = [
        self::SPA => 'Pijat & Spa',
        self::LACTATION => 'Laktasi & Konsultasi ASI',
    ];

    public static function infer(string $name, ?string $category = null): string
    {
        return preg_match('/\b(laktasi|lactation|asi|menyusui|breastfeeding)\b/iu', $name.' '.$category)
            ? self::LACTATION : self::SPA;
    }
}
