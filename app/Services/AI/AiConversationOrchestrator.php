<?php

namespace App\Services\AI;

use App\Models\Conversation;
use App\Support\AiDomain;

class AiConversationOrchestrator
{
    public function respond(string $message, array $policy, array $input = []): array
    {
        $result = $this->respondSingle($message, $policy, $input);
        if ($result['status'] !== 'success') {
            return $result;
        }
        $plan = app(AiResponsePlanner::class)->plan($message, $result['routing']);
        $result['routing']['answered_intents'] = [$result['routing']['intent']];
        if (count($plan) < 2) {
            return $result;
        }
        $answers = [$result['reply']];
        foreach (array_slice($plan, 1) as $intent) {
            $part = $this->respondSingle($message, $policy, $input + ['public_intent' => $intent]);
            if ($part['status'] !== 'success' || $part['routing']['domain'] !== $result['routing']['domain']) {
                continue;
            }
            $answers[] = $part['reply'];
            $result['sources'] = array_values(array_unique(array_merge($result['sources'], $part['sources'])));
            $result['evidence']['knowledge'] = ($result['evidence']['knowledge'] ?? []) + ($part['evidence']['knowledge'] ?? []);
            $result['routing']['answered_intents'][] = $intent;
        }
        // Keep a public link once, and let each part answer its own question before offering anything else.
        $seen = [];
        $result['reply'] = implode("\n\n", array_filter(array_map(function ($answer) use (&$seen) {
            return implode("\n", array_filter(explode("\n", $answer), function ($line) use (&$seen) {
                if (trim($line) === '') {
                    return true;
                }
                if (isset($seen[$line])) {
                    return false;
                }
                $seen[$line] = true;

                return true;
            }));
        }, array_unique($answers))));
        $result['routing']['reason_code'] = 'multi_intent_answer';

        return $result;
    }

    private function respondSingle(string $message, array $policy, array $input): array
    {
        $base = ['reply' => '', 'status' => 'success', 'fallback_reason' => null, 'sources' => [],
            'routing' => [], 'evidence' => [], 'next_context' => null];
        if (! $policy['enabled']) {
            return array_replace($base, ['status' => 'blocked', 'fallback_reason' => $policy['available'] ? 'ai_disabled' : 'policy_unavailable']);
        }
        $conversation = isset($input['conversation_id']) ? Conversation::find($input['conversation_id']) : null;
        $state = $conversation ? app(AiConversationContext::class)->read($conversation) : ($input['simulation_context'] ?? []);
        if (isset($state['expires_at']) && $state['expires_at'] < now()->timestamp) {
            $state = [];
        }
        $routing = app(AiDomainRouter::class)->route($message, $state);
        if (isset($input['public_intent']) && in_array($routing['intent'], ['faq', 'terms', 'pricing', 'catalog', 'availability', 'payment_help', 'home_service', 'web_order'], true)) {
            $routing['intent'] = $input['public_intent'];
        }
        $domain = $routing['domain'];
        $intent = $routing['intent'];
        $directory = app(AiServiceDirectory::class)->withDomains(($input['mode'] ?? '') === 'preview' ? ($input['chat_customization']['service_domains'] ?? null) : null);
        $facts = $directory->facts($domain ?? 'general');
        $base['routing'] = $routing;
        $base['evidence'] = ['domain' => $domain ?? 'general', 'directory' => $directory->fingerprint($domain ?? 'general'), 'knowledge' => []];
        $base['next_context'] = ['domain' => $domain, 'menu' => $domain && $domain !== 'general' ? 'domain' : 'main',
            'expected_revision' => (int) ($state['revision'] ?? 0)];
        $text = app(AiMessageUnderstanding::class)->normalize($message);
        if ($intent === 'status_lookup' || (isset($state['lookup']) && ! in_array($intent, ['main_menu', 'greeting', 'small_talk', 'human_handoff', 'medical'], true)
            && ! ($routing['candidates'] && ($routing['domain'] !== ($state['domain'] ?? null))))) {
            return app(AiTransactionLookup::class)->respond($message, $state, $input, $base);
        }
        if (in_array($intent, ['human_handoff', 'medical'], true)) {
            $base['status'] = 'escalated';
            $base['fallback_reason'] = $routing['reason_code'];
            $base['next_context']['domain'] = null;
            $base['next_context']['menu'] = null;
            $base['reply'] = match ($intent) {
                'medical' => 'Untuk kondisi kesehatan, diagnosis, atau obat, Bunda perlu berbicara langsung dengan tenaga kesehatan. Saya teruskan ke admin untuk membantu menghubungkan layanan yang sesuai.',
                default => 'Baik Bunda, percakapan saya teruskan agar ditinjau admin Gayatri. Balasan otomatis dihentikan untuk percakapan ini.',
            };

            return $base;
        }
        if (in_array($intent, ['greeting', 'small_talk'], true)) {
            $base['next_context'] = array_replace($base['next_context'], array_intersect_key($state, array_flip(['domain', 'menu', 'catalog_item_id', 'lookup'])));
            $base['reply'] = match (true) {
                str_contains($text, 'alaikum') => 'Waalaikumsalam, Bunda. Ada yang bisa saya bantu?',
                (bool) preg_match('/(?:selamat )?(pagi|siang|sore|malam)/u', $text, $greeting) => 'Selamat '.$greeting[1].' juga, Bunda. Ada yang ingin ditanyakan?',
                $intent === 'small_talk' => 'Saya asisten virtual Gayatri, Bunda. Siap membantu pertanyaan seputar layanan kami.',
                default => 'Halo, Bunda. Boleh, mau tanya apa?',
            };

            return $base;
        }
        if ($intent === 'thanks') {
            $base['reply'] = 'Sama-sama Bunda. Ketik menu untuk memilih layanan atau admin jika membutuhkan bantuan.';

            return $base;
        }
        if (! $domain && preg_match('/^[1-5]$/D', $text)) {
            $base['routing']['intent'] = 'main_menu';
            $base['routing']['reason_code'] = 'menu_context_missing';
            $base['next_context']['menu'] = 'main';
            $base['reply'] = 'Bunda, pilih layanan berikut.'."\n\n".$this->mainMenu($directory);

            return $base;
        }
        if (in_array($intent, ['main_menu', 'clarify'], true)) {
            $base['next_context']['domain'] = null;
            $base['next_context']['menu'] = $intent === 'clarify' ? 'clarify' : 'main';
            $base['reply'] = $intent === 'clarify'
                ? 'Bisa, Bunda. Mau mulai dari '.implode(' atau ', array_map(fn ($candidate) => AiDomain::LABELS[$candidate], $routing['candidates'])).' dulu?'
                : 'Halo Bunda, pilih layanan atau langsung tulis kebutuhan Bunda.'."\n\n".$this->mainMenu($directory);

            return $base;
        }
        if (! $domain) {
            $domain = 'general';
            $base['routing']['domain'] = 'general';
            $base['evidence']['domain'] = 'general';
        }
        if (! $facts['ai_enabled']) {
            $base['reply'] = 'Informasi otomatis '.AiDomain::LABELS[$domain].' belum tersedia. Ketik admin untuk bantuan.';

            return $base;
        }
        if ($intent === 'retail_catalog') {
            $retail = $facts['retail'] ?? [];
            $matched = $directory->matchingItems($retail, $text);
            $items = $matched->isNotEmpty() ? $matched : collect($retail);
            $base['reply'] = $items->isEmpty() ? 'Katalog produk pendukung belum tersedia. Ketik admin untuk bantuan.' : $items->take(9)->map(fn ($item) => '- '.app(AiPublicContent::class)->clean($item['name']).': '.$directory->money($item['price']))->implode("\n")
                ."\nStok dan pemesanan produk dikonfirmasi admin.\n".($directory->url('general') ?: 'Ketik admin untuk tautan katalog resmi.');

            return $base;
        }
        $selection = $directory->selection($domain, $message, $state);
        $base['next_context']['catalog_item_id'] = $selection;
        if ($selection && $intent === 'faq' && ! app(AiMessageUnderstanding::class)->topics($message)) {
            $intent = 'catalog';
            $base['routing']['intent'] = 'catalog';
        }
        if ($intent === 'domain_menu') {
            $base['reply'] = AiDomain::LABELS[$domain]."\n1. Katalog & tarif\n2. Ketentuan layanan\n3. Pesan melalui web\n4. Cek status transaksi\n9. Menu utama\n0. Bicara dengan admin\n\nBunda juga dapat langsung menulis pertanyaan.";

            return $base;
        }
        if ($intent === 'web_order') {
            $product = $selection;
            $base['reply'] = "Bisa, Bunda. Pilih layanan dan isi rinciannya lewat halaman ini:\n"
                .$directory->direction($domain, $product)."\nPeriksa ringkasan sebelum dikirim, ya. Pesanan baru diajukan setelah formulir dikirim dan masih menunggu konfirmasi sesuai layanan. Chat ini tidak membuat booking atau pesanan. Ketik admin jika perlu dibantu.";

            return $base;
        }
        if (in_array($intent, ['catalog', 'pricing'], true) && $domain !== 'general') {
            $base['reply'] = $directory->catalog($domain, $selection, $message);
            if (preg_match('/ongkir|perjalanan|per kilometer|per km/u', $text) && ! preg_match('/\b(harga|harganya|tarif|tarifnya)\b/u', $text)) {
                $base['reply'] = $this->shipping($domain, $facts, $directory)."\n".$directory->direction($domain);
            }

            return $base;
        }
        if ($intent === 'home_service') {
            $base['reply'] = $this->shipping($domain, $facts, $directory)."\n".$directory->direction($domain);

            return $base;
        }
        if ($intent === 'availability') {
            $base['reply'] = 'Ketersediaan bergantung pada '.($domain === 'rental' ? 'alat, jumlah dan periode sewa' : ($domain === 'laundry' ? 'penerimaan dan kapasitas outlet' : 'layanan, tanggal dan slot jadwal'))
                .". Pilih rinciannya di web; ketersediaan diperiksa kembali saat pengajuan.\n".$directory->direction($domain);

            return $base;
        }
        if ($intent === 'payment_help') {
            $base['reply'] = 'Pembayaran masih diperiksa admin secara manual. Mengirim bukti transfer belum berarti pembayaran telah disetujui. '
                .(in_array($domain, ['rental', 'laundry'], true)
                    ? 'Gunakan portal pribadi dari transaksi untuk melihat tagihan dan mengunggah bukti pembayaran; akses portal tetap memerlukan verifikasi. Jika akses tidak tersedia, ketik admin.'
                    : 'Untuk tagihan, DP, pelunasan atau pemeriksaan bukti pembayaran booking, ketik admin agar dibantu melalui pencatatan pembayaran yang benar.');

            return $base;
        }
        if ($intent === 'change_request') {
            $base['reply'] = match ($domain) {
                'rental' => 'Permintaan perpanjangan, pembatalan atau refund rental diajukan melalui portal pribadi dari transaksi. Perpanjangan bergantung pada ketersediaan dan persetujuan; refund ditinjau admin.',
                'laundry' => 'Untuk perubahan, pembatalan atau refund laundry, ketik admin. Portal pribadi dapat digunakan untuk konfirmasi hasil timbang dan bukti pembayaran, sesuai tahap pesanan.',
                default => 'Perubahan booking yang masih menunggu konfirmasi dapat dilakukan melalui halaman pemesanan pada sesi browser yang sama jika tersedia. Untuk booking lain, pembatalan atau refund, ketik admin. Tidak ada transaksi yang diubah melalui chat ini.',
            };

            return $base;
        }
        $includeDemo = ($input['mode'] ?? null) === 'simulator' && ($input['include_demo'] ?? false);
        $knowledge = app(KnowledgeRetrievalService::class)->retrieve($message, 2, $domain, $includeDemo);
        $bestScore = (float) ($knowledge->first()?->relevance_score ?? 0);
        $knowledge = $knowledge->filter(fn ($item) => (float) $item->relevance_score >= max(1, $bestScore * 0.65));
        $safe = app(AiPublicContent::class);
        $answers = [];
        foreach ($knowledge as $item) {
            if ($safe->unsafe($item->title."\n".(string) $item->content)) {
                continue;
            }
            $answers[] = $safe->clean($item->content, 950);
            $base['sources'][] = $item->slug;
            $base['evidence']['knowledge'][$item->id] = app(AiReplyEvidence::class)->knowledgeHash($item);
        }
        if ($answers) {
            $base['reply'] = implode("\n\n", array_unique($answers));
            $base['routing']['reason_code'] = 'scoped_knowledge';
        } elseif ($intent === 'terms' || preg_match('/cara|proses|persiapan|ketentuan|syarat|antar|jemput|kembali|timbang/u', $text)) {
            $base['reply'] = $this->basicTerms($domain)."\n".($domain !== 'general' ? $directory->direction($domain) : 'Ketik menu untuk memilih layanan.');
            $base['routing']['reason_code'] = 'system_procedure';
        } else {
            $base['reply'] = 'Bunda, informasi terverifikasi untuk pertanyaan tersebut belum tersedia di '.AiDomain::LABELS[$domain].". Ketik admin agar dibantu, atau tulis pertanyaan yang lebih spesifik.\nKetik menu untuk kembali memilih layanan.";
            $base['routing']['reason_code'] = 'knowledge_missing';
        }

        return $base;
    }

    private function mainMenu(AiServiceDirectory $directory): string
    {
        $lines = [];
        foreach (AiDomain::MENU as $number => $domain) {
            $facts = $directory->facts($domain);
            $lines[] = $number.'. '.AiDomain::LABELS[$domain].(! $facts['ai_enabled'] ? ' (bantuan admin)' : '');
        }

        return implode("\n", $lines)."\n5. Cek status transaksi\n0. Bicara dengan admin\n\nBalas angka pilihannya, atau langsung tulis pertanyaan. Pemesanan dilakukan melalui formulir web.";
    }

    private function basicTerms(string $domain): string
    {
        return match ($domain) {
            'spa', 'lactation' => 'Pilih kelompok layanan, treatment, tanggal dan jadwal melalui formulir web. Admin memproses permintaan sesuai layanan. Untuk persiapan khusus atau pertanyaan yang belum tercantum, hubungi admin.',
            'rental' => 'Pilih alat, jumlah dan periode sewa melalui katalog/keranjang. Periksa tarif, deposit, antar-jemput dan ketentuan pada ringkasan sebelum mengajukan. Pengembalian melibatkan pemeriksaan unit; perpanjangan diajukan lewat portal dan menunggu persetujuan.',
            'laundry' => 'Permintaan awal laundry berisi estimasi. Hasil timbang dan rincian tagihan final dikonfirmasi melalui portal pelanggan. Pembayaran manual diperiksa admin; jadwal antar-jemput mengikuti pengaturan dan konfirmasi outlet.',
            default => 'Pilih layanan dari menu, lalu gunakan formulir web untuk mengisi serta memeriksa rincian. Ketik admin jika membutuhkan bantuan.',
        };
    }

    private function shipping(string $domain, array $facts, AiServiceDirectory $directory): string
    {
        if (in_array($domain, ['spa', 'lactation'], true)) {
            $home = $facts['home'] ?? [];
            $eligible = array_intersect(array_column($facts['items'], 'id'), array_map('intval', $home['service_ids'] ?? []));
            if (! ($home['ready'] ?? false) || ! $eligible) {
                return 'Home service untuk kelompok layanan ini belum dapat dipastikan melalui chat. Ketik admin untuk memeriksa layanan dan area yang dapat dilayani.';
            }

            return 'Home service tersedia untuk treatment tertentu. Tarif perjalanan '.$directory->money($home['rate_per_km']).'/km; biaya dasar '.$directory->money($home['base_fee']).'; jarak bebas biaya '.$home['free_km'].' km; radius maksimal '.$home['max_distance_km'].' km. Alamat dan titik lokasi diisi pada formulir. Total perjalanan serta kelayakan layanan dihitung kembali di web.';
        }

        return 'Ongkir antar-jemput '.(AiDomain::LABELS[$domain] ?? 'layanan').' bergantung pada pengaturan layanan, alamat dan metode yang dipilih. Periksa perhitungan resmi pada formulir/invoice; ketersediaan antar-jemput perlu dikonfirmasi admin.';
    }
}
