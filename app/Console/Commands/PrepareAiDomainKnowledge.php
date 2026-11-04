<?php

namespace App\Console\Commands;

use App\Models\KnowledgeBase;
use App\Services\AI\KnowledgeBaseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepareAiDomainKnowledge extends Command
{
    protected $signature = 'ai:prepare-domain-knowledge';

    protected $description = 'Prepare editable FAQ drafts without publishing old content or changing service settings.';

    public function handle(KnowledgeBaseService $service): int
    {
        $drafts = [
            'spa' => ['Cara memesan Pijat & Spa', 'Pilih treatment, tanggal dan jadwal pada formulir booking Pijat & Spa. Periksa ringkasan sebelum mengirim permintaan. Admin memproses konfirmasi sesuai layanan.'],
            'lactation' => ['Cara memesan Laktasi & Konsultasi ASI', 'Gunakan formulir khusus Laktasi & Konsultasi ASI. Pilih layanan dan jadwal; tim memproses konfirmasi permintaan. Pertanyaan kesehatan pribadi diteruskan ke tenaga kesehatan/admin.'],
            'rental' => ['Pemesanan dan pengembalian rental', 'Pilih alat, jumlah dan periode pada katalog/keranjang. Periksa rincian serta ketentuan sebelum memesan. Pengembalian melibatkan pemeriksaan unit. Perpanjangan dapat diajukan lewat portal dan menunggu persetujuan.'],
            'laundry' => ['Estimasi dan hasil timbang laundry', 'Permintaan awal memakai estimasi. Hasil timbang dan tagihan final perlu dikonfirmasi melalui portal pelanggan sebelum pembayaran diperiksa admin. Antar-jemput mengikuti pengaturan serta konfirmasi outlet.'],
            'general' => ['Bukti pembayaran manual', 'Bukti transfer diperiksa admin. Unggahan tidak otomatis menandai lunas. Gunakan portal pribadi jika tersedia atau hubungi admin untuk bantuan pembayaran.'],
        ];
        $count = 0;
        DB::transaction(function () use ($drafts, $service, &$count) {
            foreach ($drafts as $domain => [$title, $content]) {
                $knowledge = KnowledgeBase::firstOrCreate(['slug' => 'ai-guide-'.$domain], [
                    'title' => $title, 'type' => 'faq', 'content' => $content, 'status' => 'active',
                    'publication_status' => 'draft', 'audience' => 'customer_public',
                    'meta' => ['origin' => 'application_procedure_draft'],
                ]);
                if ($knowledge->wasRecentlyCreated) {
                    $knowledge->domains()->create(['domain' => $domain]);
                    $service->syncChunks($knowledge);
                    $count++;
                }
            }
        });
        $this->info($count.' draft FAQ disiapkan. Owner/manager meninjau sebelum menerbitkan.');

        return self::SUCCESS;
    }
}
