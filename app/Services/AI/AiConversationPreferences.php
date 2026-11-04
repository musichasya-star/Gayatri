<?php

namespace App\Services\AI;

use App\Services\AppSettingService;
use App\Support\AiDomain;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AiConversationPreferences
{
    public const KEY = 'ai.information.preferences';

    public function defaults(): array
    {
        return ['assistant_name' => 'Admin Gayatri', 'business_name' => 'Gayatri', 'salutation' => 'Bunda', 'tone' => 'warm',
            'use_emoji' => false, 'offer_next_step' => true, 'max_clarifications' => 2,
            'greeting' => 'Halo {sapaan}, selamat datang di {nama_bisnis}. Mau cari layanan apa hari ini?',
            'thanks' => 'Sama-sama, {sapaan}. Kalau ada yang ingin ditanyakan lagi, langsung tulis di sini, ya.',
            'handoff' => 'Baik, saya teruskan percakapan ini ke admin. Balasan otomatis saya hentikan dulu.',
            'fallback' => 'Informasi ini belum tersedia di panduan kami. Boleh jelaskan bagian yang ingin ditanyakan, atau ketik admin untuk bantuan?',
            'domain_prompts' => ['spa' => 'Mau lihat pilihan treatment, persiapan kunjungan, atau cara booking?',
                'lactation' => 'Mau tahu pilihan konsultasi ASI, persiapan, atau cara mendaftar?',
                'rental' => 'Sedang mencari alat apa? Bisa cek katalog, ketentuan sewa, atau status pesanan di sini.',
                'laundry' => 'Mau tanya tarif, penanganan cucian, atau mengecek proses pesanan?'],
            'admin_hours_enabled' => false, 'admin_start' => '08:00', 'admin_end' => '17:00', 'admin_days' => [1, 2, 3, 4, 5, 6],
            'after_hours' => 'Saat ini di luar jam layanan admin. Pesan tetap tercatat dan menunggu ditangani saat admin kembali tersedia.'];
    }

    public function settings(): array
    {
        $stored = json_decode((string) app(AppSettingService::class)->get(self::KEY, ''), true);
        if (! is_array($stored)) {
            return $this->defaults();
        }
        $merged = array_replace($this->defaults(), $stored);
        if (isset($stored['domain_prompts']) && ! is_array($stored['domain_prompts'])) {
            return $this->defaults();
        }
        $merged['domain_prompts'] = array_replace($this->defaults()['domain_prompts'], $stored['domain_prompts'] ?? []);
        try {
            return $this->validated($merged);
        } catch (ValidationException) {
            return $this->defaults();
        }
    }

    public function validated(array $data): array
    {
        $fields = ['assistant_name', 'business_name', 'salutation', 'tone', 'use_emoji', 'offer_next_step', 'max_clarifications', 'greeting', 'thanks', 'handoff', 'fallback', 'domain_prompts', 'admin_hours_enabled', 'admin_start', 'admin_end', 'admin_days', 'after_hours'];
        $data = array_intersect_key($data, array_flip($fields));
        $data = Validator::make($data, [
            'assistant_name' => 'required|string|max:60', 'business_name' => 'required|string|max:60', 'salutation' => 'required|string|max:25',
            'tone' => ['required', Rule::in(array_keys(AiChatCustomization::TONES))], 'use_emoji' => 'required|boolean', 'offer_next_step' => 'required|boolean',
            'max_clarifications' => 'required|integer|min:1|max:3',
            'greeting' => 'required|string|max:400', 'thanks' => 'required|string|max:350', 'handoff' => 'required|string|max:400',
            'fallback' => 'required|string|max:450', 'after_hours' => 'required|string|max:400',
            'domain_prompts' => 'required|array:spa,lactation,rental,laundry', 'domain_prompts.*' => 'required|string|max:250',
            'admin_hours_enabled' => 'required|boolean', 'admin_start' => 'required|date_format:H:i', 'admin_end' => 'required|date_format:H:i|after:admin_start',
            'admin_days' => 'required|array|min:1|max:7', 'admin_days.*' => 'required|integer|between:1,7|distinct',
        ])->validate();
        foreach (array_values(AiDomain::MENU) as $domain) {
            if (! isset($data['domain_prompts'][$domain])) {
                throw ValidationException::withMessages(['preferences.domain_prompts' => 'Isi panduan singkat untuk keempat layanan.']);
            }
        }
        foreach (['assistant_name', 'business_name', 'salutation', 'greeting', 'thanks', 'handoff', 'fallback', 'after_hours'] as $key) {
            $data[$key] = $this->safe($data[$key], $key);
        }
        foreach ($data['domain_prompts'] as $key => $text) {
            $data['domain_prompts'][$key] = $this->safe($text, 'domain_prompts.'.$key);
        }
        $data['use_emoji'] = (bool) $data['use_emoji'];
        $data['admin_hours_enabled'] = (bool) $data['admin_hours_enabled'];
        $data['offer_next_step'] = (bool) $data['offer_next_step'];
        $data['max_clarifications'] = (int) $data['max_clarifications'];
        $data['admin_days'] = array_map('intval', $data['admin_days']);

        return $data;
    }

    private function safe(string $text, string $field): string
    {
        $text = trim($text);
        if (in_array($field, ['assistant_name', 'business_name', 'salutation'], true) && preg_match('/[{}]/', $text)) {
            throw ValidationException::withMessages(['preferences.'.$field => 'Identitas dan sapaan harus berupa teks biasa.']);
        }
        $residue = str_replace(['{sapaan}', '{nama_asisten}', '{nama_bisnis}'], '', $text);
        if (strip_tags($text) !== $text || preg_match('/[{}]|https?:|www\.|[a-z0-9-]+\.(?:com|id|net)\b|(?:\+62|08)\d{7,}|\b(?:booking|pesanan|pembayaran|transaksi)\b.{0,45}\b(?:buat|otomatis|confirmed|dikonfirmasi|lunas|berhasil|hapus)\b|\b(?:buat|membuat|ubah|mengubah|hapus|konfirmasi|melunasi|setujui)\b.{0,35}\b(?:booking|pesanan|transaksi|pembayaran)\b|\b(?:abaikan|bypass|api[_ -]?key|password|token|secret)\b/iu', $residue)
            || app(AiPublicContent::class)->unsafe($text)) {
            throw ValidationException::withMessages(['preferences.'.$field => 'Gunakan teks layanan biasa tanpa HTML, tautan, kredensial, harga, atau instruksi/status transaksi. Variabel: {sapaan}, {nama_asisten}, {nama_bisnis}.']);
        }

        return $text;
    }

    public function render(string $text, array $settings): string
    {
        return strtr($text, ['{sapaan}' => $settings['salutation'], '{nama_asisten}' => $settings['assistant_name'], '{nama_bisnis}' => $settings['business_name']]);
    }

    public function adminOpen(array $settings): bool
    {
        if (! $settings['admin_hours_enabled']) {
            return true;
        }
        $now = now('Asia/Jakarta');

        return in_array($now->isoWeekday(), $settings['admin_days'], true) && $now->format('H:i') >= $settings['admin_start'] && $now->format('H:i') < $settings['admin_end'];
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->settings(), JSON_THROW_ON_ERROR));
    }
}
