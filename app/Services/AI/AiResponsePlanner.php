<?php

namespace App\Services\AI;

class AiResponsePlanner
{
    /** A small, explicit read-only plan. Identity/status/handoff always remain outside this planner. */
    public function plan(string $message, array $routing): array
    {
        $primary = $routing['intent'];
        if (! in_array($primary, ['faq', 'terms', 'pricing', 'catalog', 'availability', 'payment_help', 'home_service', 'web_order'], true)
            || ! in_array($routing['domain'], ['spa', 'lactation', 'rental', 'laundry'], true)) {
            return [$primary];
        }
        $understanding = app(AiMessageUnderstanding::class);
        $text = $understanding->normalize($message);
        $intents = [$primary];
        $patterns = [
            'pricing' => '/\b(harga|harganya|tarif|tarifnya|biaya|biayanya)\b/u',
            'home_service' => '/\b(ongkir|antar jemput|antar-jemput|home service|ke rumah)\b/u',
            'availability' => '/\b(stok|slot|tersedia|ketersediaan|masih ada|kosong)\b/u',
            'payment_help' => '/\b(pembayaran|bayar|transfer|bukti|rekening|dp|pelunasan)\b/u',
            'web_order' => '/\b(cara (?:booking|pesan|memesan|daftar)|mau (?:booking|pesan)|ingin (?:booking|pesan))\b/u',
            'terms' => '/\b(ketentuan|syarat)\b/u',
        ];
        foreach ($patterns as $intent => $pattern) {
            if (preg_match($pattern, $text) && ! in_array($intent, $intents, true)) {
                $intents[] = $intent;
            }
        }
        // Deposit is already shown by the rental catalog; other FAQ topics need their own answer.
        if (array_diff($understanding->topics($message), $primary === 'pricing' && $routing['domain'] === 'rental' ? ['deposit'] : [])) {
            $intents[] = 'faq';
        }
        if (in_array('catalog', $intents, true) && in_array('pricing', $intents, true)) {
            $intents = array_values(array_diff($intents, ['catalog']));
        }

        return array_values(array_unique($intents));
    }
}
