<?php

namespace App\Services\AI;

use App\Models\KnowledgeBase;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class KnowledgeRetrievalService
{
    public function retrieve(string $query, int $limit = 5, ?string $domain = null, bool $includeDemo = false): Collection
    {
        if ($domain === null && ! app(AiInteractionPolicy::class)->allowsLegacyAutomation()) {
            return new Collection;
        }
        $query = $domain === null ? $query : app(AiMessageUnderstanding::class)->query($query);
        $terms = collect(preg_split('/\s+/', Str::lower($query), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $term) => trim($term, " .,!?;:\"'()[]{}"))
            ->filter(fn (string $term) => Str::length($term) >= 3)
            ->unique()
            ->values();
        if ($domain !== null) {
            $terms = $terms->reject(fn ($term) => in_array($term, [
                'apa', 'apakah', 'bagaimana', 'cara', 'bisa', 'untuk', 'dengan', 'yang', 'dan', 'saya', 'bunda',
                'ibu', 'bayi', 'berapa', 'layanan', 'laundry', 'rental', 'spa', 'pijat', 'asi', 'laktasi', 'mau', 'ingin',
                'halo', 'hai', 'min', 'admin', 'kak', 'dong', 'tolong', 'sih', 'nih', 'juga', 'kalau', 'tersebut', 'tadi', 'itu',
            ], true));
            if ($terms->contains('website')) {
                // "Alamat web" asks for a URL; an office address is a separate topic.
                $asksPhysicalAddress = preg_match('/\b(?:kantor|outlet|cabang|fisik|lokasi|rumah|peta|maps|alamat(?: gayatri)? (?:dan|serta)|(?:dan|serta) alamat)\b/u', $query);
                $terms = $terms->reject(fn ($term) => $term === 'gayatri' || ($domain === 'general' && ! $asksPhysicalAddress && $term === 'alamat'))
                    ->merge(['web', 'situs', 'url'])->unique();
            }
            if ($terms->isEmpty()) {
                return new Collection;
            }
        }

        $includeDemo = $includeDemo && app()->environment('local', 'baileys-local', 'testing');
        $knowledge = KnowledgeBase::query()
            ->when(! $includeDemo, fn ($q) => $q->where('is_demo', false))
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('valid_until')->orWhere('valid_until', '>=', now());
            })
            ->when($domain !== null, fn ($q) => $q->where('publication_status', 'published')
                ->where('audience', 'customer_public')
                ->whereHas('domains', fn ($d) => $d->whereIn('domain', array_unique([$domain, 'general']))))
            ->with($domain !== null ? ['chunks', 'domains'] : ['chunks'])
            ->latest()
            ->get();

        return $knowledge
            ->map(function (KnowledgeBase $item) use ($terms) {
                $title = Str::lower($item->title.' '.$item->slug);
                $keywords = Str::lower(implode(' ', (array) data_get($item->meta, 'keywords', [])));
                $body = Str::lower((string) $item->content.' '.$item->chunks->pluck('content')->implode(' '));
                $score = $terms->sum(function (string $term) use ($title, $body, $keywords) {
                    return $this->termScore($title, $term, 3) + $this->termScore($body, $term) + $this->termScore($keywords, $term, 4);
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
