<?php

namespace App\Services\AI;

use Illuminate\Support\Str;

class AiGuardrailService
{
    private const MEDICAL_TERMS = [
        'demam', 'obat', 'diagnosis', 'dokter', 'sakit', 'diare', 'muntah', 'kejang', 'ruam', 'alergi', 'infeksi',
    ];

    private const SENSITIVE_TERMS = [
        'refund', 'komplain', 'marah', 'kecewa', 'diskon khusus', 'harga khusus', 'murah banget',
    ];

    public function check(string $message, bool $hasKnowledge = true): array
    {
        $text = Str::lower($message);

        if ($this->containsAny($text, self::MEDICAL_TERMS)) {
            return [
                'allowed' => false,
                'reason' => 'medical',
                'reply' => 'Bunda, untuk keamanan si kecil, kami belum bisa memberi arahan medis lewat chat. Jika bayi sedang demam, sakit, atau ada keluhan kesehatan, sebaiknya konsultasi dulu dengan dokter atau tenaga medis. Untuk layanan relaksasi dan perawatan di Gayatri, kami siap bantu saat kondisi si kecil sudah nyaman ya.',
                'confidence' => 0.0,
            ];
        }

        if ($this->containsAny($text, self::SENSITIVE_TERMS)) {
            return [
                'allowed' => false,
                'reason' => 'sensitive',
                'reply' => 'Terima kasih sudah cerita, Bunda. Supaya kami bisa bantu dengan tepat, boleh kirim detail singkatnya seperti nama, tanggal kunjungan atau booking, dan kendala yang Bunda alami ya.',
                'confidence' => 0.25,
            ];
        }

        if (! $hasKnowledge) {
            return [
                'allowed' => false,
                'reason' => 'no_knowledge',
                'reply' => 'Mohon maaf Bunda, untuk pertanyaan tersebut saya belum bisa memastikan jawabannya. Saya bantu teruskan ke admin Gayatri agar Bunda mendapatkan informasi yang tepat ya.',
                'confidence' => 0.35,
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'reply' => null,
            'confidence' => 0.85,
        ];
    }

    private function containsAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if (Str::contains($text, $term)) {
                return true;
            }
        }

        return false;
    }
}
