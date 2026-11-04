<?php

namespace App\Services\AI;

use Illuminate\Support\Str;

class AiMessageUnderstanding
{
    /** Used for public routing/retrieval only. Never normalize an identity or authorization proof here. */
    public function normalize(string $message): string
    {
        $text = Str::lower(Str::squish(mb_substr($message, 0, 2000)));
        $words = [
            'brp' => 'berapa', 'hrg' => 'harga', 'hrga' => 'harga', 'hargany' => 'harganya',
            'gmn' => 'bagaimana', 'gimana' => 'bagaimana', 'bgmn' => 'bagaimana', 'gmna' => 'bagaimana',
            'yg' => 'yang', 'utk' => 'untuk', 'dgn' => 'dengan', 'bs' => 'bisa', 'bsa' => 'bisa',
            'bkn' => 'bukan', 'tdk' => 'tidak', 'ga' => 'tidak', 'gak' => 'tidak', 'gk' => 'tidak', 'nggak' => 'tidak',
            'dlu' => 'dulu', 'pgn' => 'ingin', 'pengen' => 'ingin', 'pesen' => 'pesan', 'nyewa' => 'sewa',
            'nyuci' => 'laundry', 'londry' => 'laundry', 'loundry' => 'laundry', 'laundri' => 'laundry',
            'stroler' => 'stroller', 'stoler' => 'stroller', 'boking' => 'booking', 'bokng' => 'booking',
            'konsul' => 'konsultasi', 'trf' => 'transfer', 'byr' => 'bayar', 'ongkirnya' => 'ongkir',
            'dibalikin' => 'dikembalikan', 'balikin' => 'pengembalian', 'kembaliin' => 'pengembalian',
            'bersihnya' => 'kebersihan', 'bersihin' => 'kebersihan', 'dicuci' => 'cuci',
            'kebersihannya' => 'kebersihan', 'bayarnya' => 'bayar', 'syaratnya' => 'syarat', 'caranya' => 'cara',
            'aja' => 'saja', 'layanannya' => 'layanan',
        ];

        return preg_replace_callback('/\b[\pL]+\b/u', fn ($m) => $words[$m[0]] ?? $m[0], $text);
    }

    public function topics(string $message): array
    {
        $text = $this->normalize($message);
        $topics = [
            'preparation' => '/\b(persiapan|siapkan|disiapkan|dibawa|bawa apa|perlengkapan)\b/u',
            'hygiene' => '/\b(kebersihan|bersih|steril|sterilisasi|sanitasi|perawatan)\b/u',
            'completeness' => '/\b(kelengkapan|komponen|lengkap)\b/u',
            'return' => '/\b(pengembalian|dikembalikan|mengembalikan)\b/u',
            'deposit' => '/\b(deposit|jaminan)\b/u',
            'companion' => '/\b(pendamping|ditemani|suami|keluarga|orang tua)\b/u',
            'scent' => '/\b(pewangi|parfum|deterjen)\b/u',
            'stain' => '/\b(noda|bernoda)\b/u',
            'weighing' => '/\b(timbang|penimbangan|berat)\b/u',
            'quality' => '/\b(kualitas|quality|qc)\b/u',
            'privacy' => '/\b(privasi|kerahasiaan)\b/u',
        ];

        return array_keys(array_filter($topics, fn ($pattern) => preg_match($pattern, $text)));
    }

    public function query(string $message): string
    {
        $text = $this->normalize($message);
        $aliases = ['preparation' => 'persiapan', 'hygiene' => 'kebersihan perawatan', 'completeness' => 'kelengkapan',
            'return' => 'pengembalian', 'deposit' => 'deposit', 'companion' => 'pendamping', 'scent' => 'pewangi',
            'stain' => 'noda', 'weighing' => 'timbang', 'quality' => 'kualitas', 'privacy' => 'privasi'];
        foreach ($this->topics($message) as $topic) {
            $text .= ' '.$aliases[$topic];
        }

        return $text;
    }
}
