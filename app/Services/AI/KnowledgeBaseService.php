<?php

namespace App\Services\AI;

use App\Models\KnowledgeBase;
use Illuminate\Support\Str;

class KnowledgeBaseService
{
    public function syncChunks(KnowledgeBase $knowledgeBase): void
    {
        $knowledgeBase->chunks()->delete();

        $content = trim((string) $knowledgeBase->content);

        if ($content === '') {
            return;
        }

        $chunks = collect(str_split($content, 900));

        $chunks->each(function (string $chunk, int $index) use ($knowledgeBase) {
            $knowledgeBase->chunks()->create([
                'chunk_index' => $index,
                'content' => trim($chunk),
                'token_count' => str_word_count(strip_tags($chunk)),
                'meta' => ['generated_by' => self::class, 'checksum' => sha1($chunk)],
            ]);
        });
    }

    public function makeSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'knowledge';
        $slug = $base;
        $counter = 2;

        while (KnowledgeBase::where('slug', $slug)->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }
}
