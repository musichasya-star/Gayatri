<?php

namespace App\Services\AI;

use App\Models\KnowledgeBase;
use App\Support\AiDomain;

class AiKnowledgeReadiness
{
    public function report(): array
    {
        $required = [
            'spa' => ['persiapan', 'pendamping', 'home service', 'keterlambatan'],
            'lactation' => ['persiapan', 'pendamping', 'kunjungan', 'konsultasi'],
            'rental' => ['kelengkapan', 'kebersihan', 'pengembalian', 'perpanjangan', 'deposit'],
            'laundry' => ['pewangi', 'noda', 'timbang', 'kualitas', 'antar'],
            'general' => ['privasi', 'portal', 'pembayaran', 'pemesanan'],
        ];
        $items = KnowledgeBase::with('domains')->get();
        $report = [];
        foreach ($required as $domain => $topics) {
            $scoped = $items->filter(fn ($kb) => $kb->domains->contains('domain', $domain));
            $usable = $scoped->filter(fn ($kb) => ! $kb->is_demo && $kb->status === 'active' && $kb->publication_status === 'published'
                && $kb->audience === 'customer_public' && ! $kb->valid_from?->isFuture() && ! $kb->valid_until?->isPast()
                && ! app(AiPublicContent::class)->unsafe($kb->title."\n".$kb->content));
            $text = $usable->map(fn ($kb) => mb_strtolower($kb->title.' '.$kb->content.' '.implode(' ', (array) data_get($kb->meta, 'keywords', []))))->implode(' ');
            $report[$domain] = ['label' => AiDomain::LABELS[$domain], 'published_usable' => $usable->count(),
                'demo' => $scoped->where('is_demo', true)->count(),
                'review_required' => $scoped->where('is_demo', false)->whereIn('publication_status', ['draft', 'needs_review'])->count(),
                'topic_gaps' => array_values(array_filter($topics, fn ($topic) => ! str_contains($text, $topic)))];
        }

        return ['domains' => $report, 'ready_for_live_faq' => collect($report)->every(fn ($row) => $row['published_usable'] > 0 && ! $row['topic_gaps']),
            'note' => 'Cakupan kata kunci membantu tinjauan; kelengkapan dan kebenaran kebijakan tetap memerlukan pemeriksaan admin. Demo tidak dihitung sebagai materi live.'];
    }
}
