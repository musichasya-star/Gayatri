<?php

namespace App\Services\AI;

use App\Models\LaundryTariff;
use App\Models\RentalProduct;
use App\Models\RetailProduct;
use App\Models\Service;
use App\Support\AiDomain;
use Illuminate\Support\Str;

class AiDomainRouter
{
    public function route(string $message, array $context = []): array
    {
        $text = trim(app(AiMessageUnderstanding::class)->normalize($message), " \t\n\r\0\x0B.!?,");
        $result = ['domain' => null, 'intent' => 'faq', 'reason_code' => 'domain_clarification', 'candidates' => []];
        $topicCorrection = false;
        if (preg_match('/^(0|admin|manusia|operator|stop|berhenti)$/u', $text)
            || Str::contains($text, ['bicara dengan admin', 'hubungkan admin', 'minta admin', 'jangan balas otomatis', 'dibantu admin', 'bicara sama admin'])
            || preg_match('/^(?:admin|operator)(?:\s+(?:dong|ya|kak|min|tolong))*[!.,?\s]*$/u', $text)) {
            return array_replace($result, ['intent' => 'human_handoff', 'reason_code' => 'requested_human']);
        }
        if (preg_match('/^(menu|menu utama|menu layanan|layanan apa saja|ada layanan apa|pilihan layanan|kembali|batal|cancel|9|bantuan|help)$/u', $text)) {
            return array_replace($result, ['intent' => 'main_menu', 'reason_code' => 'menu_requested']);
        }
        if (in_array($context['menu'] ?? null, ['offer_catalog', 'offer_admin'], true) && in_array($context['domain'] ?? null, array_values(AiDomain::MENU), true)) {
            if (preg_match('/^(ya|iya|iyaa|boleh|boleh dong|mau|oke|ok|lanjut|lihat)[!.,\s]*$/u', $text)) {
                return array_replace($result, ['domain' => $context['domain'], 'intent' => $context['menu'] === 'offer_admin' ? 'human_handoff' : 'catalog', 'reason_code' => $context['menu'] === 'offer_admin' ? 'requested_human' : 'accepted_catalog_offer']);
            }
            if (preg_match('/^(tidak|nggak|gak|enggak|nanti|nanti dulu|belum)[!.,\s]*$/u', $text)) {
                return array_replace($result, ['domain' => $context['domain'], 'intent' => 'thanks', 'reason_code' => 'offer_declined']);
            }
        }
        if (preg_match('/^[1-5]$/D', $text)) {
            if (in_array($context['menu'] ?? null, ['main', 'clarify'], true)) {
                if ($text === '5') {
                    return array_replace($result, ['intent' => 'status_lookup', 'reason_code' => 'status_requested']);
                }

                return array_replace($result, ['domain' => AiDomain::MENU[(int) $text], 'intent' => 'domain_menu', 'reason_code' => 'menu_choice']);
            }
            if (($context['menu'] ?? null) === 'domain' && isset(AiDomain::LABELS[$context['domain'] ?? ''])) {
                return array_replace($result, ['domain' => $context['domain'], 'intent' => [
                    '1' => 'catalog', '2' => 'terms', '3' => 'web_order', '4' => 'status_lookup', '5' => 'main_menu',
                ][$text], 'reason_code' => 'submenu_choice']);
            }

            return $result;
        }

        $patterns = [
            'laundry' => '/\b(laundry|cuci|cucian|setrika|penimbangan|timbang|pewangi|noda)\b/u',
            'rental' => '/\b(sewa|rental|disewakan|persewaan|stroller|car\s?seat|high\s?chair|bouncer|box bayi)\b/u',
            'spa' => '/\b(pijat|spa|massage|treatment|terapis|therapist)\b/u',
            'lactation' => '/\b(laktasi|lactation|asi|menyusui|breastfeeding|konselor)\b/u',
        ];
        foreach ($patterns as $domain => $pattern) {
            if (preg_match($pattern, $text)) {
                $result['candidates'][] = $domain;
            }
        }
        if (in_array('laundry', $result['candidates'], true)
            && ! preg_match('/\b(laktasi|konsultasi|menyusui|konselor)\b/u', $text)) {
            $result['candidates'] = array_values(array_diff($result['candidates'], ['lactation']));
        }
        if (in_array('rental', $result['candidates'], true) && in_array('lactation', $result['candidates'], true)
            && preg_match('/\b(pompa|breast pump)\b/u', $text) && ! preg_match('/\b(konsultasi|konselor|menyusui)\b/u', $text)) {
            $result['candidates'] = array_values(array_diff($result['candidates'], ['lactation']));
        }
        if (preg_match('/\bpijat laktasi\b/u', $text) && ! preg_match('/\b(?:dan|serta)\b/u', $text)
            && Service::where('is_active', true)->where('service_group', 'lactation')->whereRaw('LOWER(name) LIKE ?', ['%pijat laktasi%'])->exists()) {
            $result['candidates'] = ['lactation'];
        }
        // An explicit correction changes the information topic, never an existing transaction.
        if (count($result['candidates']) > 1 && preg_match('/\b(?:bukan|tidak jadi|batal tanya)\s+(?:mau\s+)?(rental|sewa|laundry|pijat|spa|laktasi|asi)\b/u', $text, $negated)) {
            $excluded = ['sewa' => 'rental', 'pijat' => 'spa', 'asi' => 'lactation', 'laktasi' => 'lactation'][$negated[1]] ?? $negated[1];
            $result['candidates'] = array_values(array_diff($result['candidates'], [$excluded]));
            $topicCorrection = true;
        }
        if (! $result['candidates']) {
            foreach (Service::where('is_active', true)->whereIn('service_group', ['spa', 'lactation'])->get(['name', 'catalog_family', 'service_group']) as $service) {
                $family = $service->catalog_family ?: $service->name;
                if (Str::length($family) >= 4 && Str::contains($text, Str::lower($family))) {
                    $result['candidates'][] = $service->service_group;
                }
            }
            foreach (['rental' => RentalProduct::class, 'laundry' => LaundryTariff::class] as $domain => $model) {
                if ($model::where('is_active', true)->pluck('name')->contains(fn ($name) => Str::length($name) >= 4 && Str::contains($text, Str::lower($name)))) {
                    $result['candidates'][] = $domain;
                }
            }
            $result['candidates'] = array_values(array_unique($result['candidates']));
        }
        if (preg_match('/\b(diagnosis|diagnosa|dosis|kejang|sesak|demam|obat)\b/u', $text)
            && ($result['candidates'] !== ['laundry'] || preg_match('/\b(bayi|anak|ibu)\b/u', $text))) {
            return array_replace($result, ['domain' => count($result['candidates']) === 1 ? $result['candidates'][0] : null,
                'intent' => 'medical', 'reason_code' => 'medical']);
        }
        if (preg_match('/\b(komplain|kecewa|tidak puas|marah)\b/u', $text)) {
            return array_replace($result, ['intent' => 'human_handoff', 'reason_code' => 'sensitive_request']);
        }
        if (count($result['candidates']) > 1) {
            return array_replace($result, ['intent' => 'clarify', 'reason_code' => 'multiple_domains']);
        }
        $result['domain'] = $result['candidates'][0] ?? ($context['domain'] ?? null);
        if (! $result['domain'] && preg_match('/\b(pembayaran|bayar|transfer|bukti|rekening|dp|pelunasan|alamat|lokasi|jam buka|jam operasional|privasi)\b/u', $text)) {
            $result['domain'] = 'general';
        }
        if (preg_match('/\b(cek status|status pesanan|status booking|kode booking|kode pesanan|sudah sampai mana|status refund)\b/u', $text)
            || preg_match('/\b(?:BK|RENT|RNT|LND)-[0-9A-Z]+(?:-[0-9A-Z]+)*\b/i', $message)
            || preg_match('/\b[A-F0-9]{8}(?:-[A-F0-9]{4}){3}-[A-F0-9]{12}\b/i', $message)) {
            return array_replace($result, ['intent' => 'status_lookup', 'reason_code' => 'status_requested']);
        }
        if (preg_match('/\b(batalkan|pembatalan|refund|reschedule|ubah jadwal|ganti jadwal|pindah jadwal|perpanjang|perpanjangan)\b/u', $text)) {
            return array_replace($result, ['intent' => 'change_request', 'reason_code' => 'web_change_direction']);
        }
        if (preg_match('/\b(komplain|kecewa|tidak puas|marah)\b/u', $text)) {
            return array_replace($result, ['intent' => 'human_handoff', 'reason_code' => 'sensitive_request']);
        }
        if (preg_match('/^(?:(?:halo|hai|hi|pagi|siang|sore|malam|assalamualaikum)(?:\s+(?:min|admin|kak|mbak))?|(?:min|kak|mbak)|(?:(?:halo|hai)\s+(?:min|admin|kak|mbak)\s+)?(?:boleh|mau|ingin)\s+tanya(?:\s+dulu)?)[!?,.\s]*$/u', $text)) {
            return array_replace($result, ['domain' => null, 'intent' => 'main_menu', 'reason_code' => 'greeting']);
        }
        if (preg_match('/^(terima kasih|makasih|makasi|thanks|oke|ok)(?:\s+(?:ya|min|kak|mbak|banyak))*[!.,\s]*$/u', $text)) {
            return array_replace($result, ['intent' => 'thanks', 'reason_code' => 'thanks']);
        }
        if (! $result['candidates'] && (preg_match('/\b(kidos|tiwa|larasmara|produk pendukung)\b/u', $text)
            || RetailProduct::where('is_active', true)->pluck('name')->contains(fn ($name) => Str::contains($text, Str::lower($name))))) {
            return array_replace($result, ['domain' => 'general', 'intent' => 'retail_catalog', 'reason_code' => 'domain_resolved', 'candidates' => ['general']]);
        }
        if (preg_match('/\b(harga|harganya|tarif|tarifnya|biaya|biayanya|ongkir|price)\b/u', $text)
            || (preg_match('/\bberapa\b/u', $text) && ! preg_match('/\bberapa (?:lama|hari|jam|menit|kilo|kg)\b/u', $text))) {
            $result['intent'] = 'pricing';
        } elseif (preg_match('/\b(durasi|berapa lama|katalog|daftar|layanan apa|alat apa|paket apa)\b/u', $text)) {
            $result['intent'] = 'catalog';
        } elseif (preg_match('/\b(tersedia|ketersediaan|stok|jadwal|slot|kosong|masih ada)\b/u', $text)) {
            $result['intent'] = 'availability';
        } elseif (preg_match('/\b(pembayaran|bayar|transfer|bukti|rekening|dp|pelunasan)\b/u', $text)) {
            $result['intent'] = 'payment_help';
        } elseif (preg_match('/\b(home service|ke rumah|datang ke rumah|ongkos perjalanan)\b/u', $text)) {
            $result['domain'] ??= 'spa';
            $result['intent'] = 'home_service';
        } elseif (preg_match('/\b(booking|reservasi|pesan|mau sewa|ingin sewa|mau laundry|mau konsultasi|ingin konsultasi|mau pijat|ingin pijat|cara memesan|cara daftar)\b/u', $text)) {
            $result['intent'] = 'web_order';
        } elseif ($topicCorrection || preg_match('/^(?:(?:saya )?(?:pilih|tanya tentang|tentang)\s+)?(pijat|spa|rental|laundry|laktasi|asi|sewa)(?:\s+(?:saja|dulu|ya))*[!?.\s]*$/u', $text)) {
            $result['intent'] = 'domain_menu';
        }
        $result['reason_code'] = $result['domain'] ? 'domain_resolved' : 'domain_clarification';

        return $result;
    }
}
