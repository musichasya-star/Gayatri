<?php

namespace App\Services\AI;

use App\Models\KnowledgeBase;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class KnowledgeRetrievalService
{
    public function retrieve(string $query, int $limit = 5): Collection
    {
        $terms = collect(preg_split('/\s+/', Str::lower($query), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $term) => trim($term, " .,!?;:\"'()[]{}"))
            ->filter(fn (string $term) => Str::length($term) >= 3)
            ->unique()
            ->values();

        $knowledge = KnowledgeBase::query()
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('valid_until')->orWhere('valid_until', '>=', now());
            })
            ->with('chunks')
            ->latest()
            ->get();

        return $knowledge
            ->map(function (KnowledgeBase $item) use ($terms) {
                $title = Str::lower($item->title.' '.$item->slug);
                $body = Str::lower((string) $item->content.' '.$item->chunks->pluck('content')->implode(' '));
                $score = $terms->sum(function (string $term) use ($title, $body) {
                    return $this->termScore($title, $term, 3) + $this->termScore($body, $term);
                });

                $item->setAttribute('relevance_score', $score);

                return $item;
            })
            ->filter(fn (KnowledgeBase $item) => $item->relevance_score > 0 || $terms->isEmpty())
            ->sortByDesc('relevance_score')
            ->take($limit)
            ->values();
    }

    private function termScore(string $haystack, string $term, int $weight = 1): int
    {
        if (preg_match('/(?<![\pL\pN])'.preg_quote($term, '/').'(?![\pL\pN])/u', $haystack) === 1) {
            return $weight;
        }

        return 0;
    }
}
