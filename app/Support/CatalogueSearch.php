<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class CatalogueSearch
{
    public static function apply(Builder $query, ?string $term, array $columns): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        return $query->where(function ($sub) use ($pattern, $columns) {
            foreach ($columns as $column) {
                $sub->orWhereRaw($column." LIKE ? ESCAPE '!'", [$pattern]);
            }
        });
    }
}
