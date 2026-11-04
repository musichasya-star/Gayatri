<?php

namespace App\Console\Commands;

use App\Models\KnowledgeBase;
use App\Services\AI\KnowledgeBaseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepareAiKnowledgeReview extends Command
{
    protected $signature = 'ai:prepare-knowledge-review';

    protected $description = 'Prepare local review drafts of implemented workflows; never publish or replace business policies.';

    public function handle(KnowledgeBaseService $chunks): int
    {
        if (! app()->environment('local', 'baileys-local', 'testing')) {
            $this->error('Persiapan materi hanya untuk lokal/pengujian.');

            return self::FAILURE;
        }
        $rows = [
            ['spa', 'pembayaran-booking', 'DP dan pelunasan booking', 'Untuk DP atau pelunasan booking, hubungi admin agar tagihan dan bukti transfer dicocokkan dengan transaksi. Bukti pembayaran disimpan privat. Unggahan atau pesan transfer belum berarti pembayaran telah disetujui.', ['pembayaran', 'dp', 'pelunasan']],
            ['spa', 'home-service', 'Alamat dan ongkir home service', 'Pilih opsi home service pada formulir bila tersedia untuk treatment tersebut. Isi alamat lengkap dan titik lokasi. Kelayakan area serta biaya perjalanan diperiksa sesuai pengaturan layanan sebelum pengajuan.', ['home service', 'alamat', 'ongkir']],
            ['lactation', 'pemesanan', 'Formulir konsultasi ASI', 'Pilih layanan pada formulir khusus Laktasi & Konsultasi ASI, lalu isi jadwal dan informasi pemesanan. Periksa ringkasan sebelum mengirim. Permintaan menunggu konfirmasi; informasi di chat tidak menggantikan penilaian tenaga kesehatan.', ['konsultasi', 'pemesanan']],
            ['lactation', 'pembayaran', 'Pembayaran konsultasi ASI', 'Pembayaran konsultasi ASI dicatat pada transaksi booking yang sesuai. Hubungi admin untuk DP, pelunasan, atau pemeriksaan bukti transfer. Chat tidak menyetujui pembayaran maupun mengubah jadwal.', ['pembayaran', 'dp', 'pelunasan']],
            ['rental', 'keranjang', 'Memesan beberapa alat', 'Pilih beberapa alat melalui keranjang, isi jumlah dan periode sewa. Periksa ringkasan tarif, deposit, ongkir, dan ketentuan. Ketersediaan unit diperiksa ketika permintaan diajukan. Pesanan tetap menunggu konfirmasi admin.', ['keranjang', 'pemesanan']],
            ['rental', 'perpanjangan', 'Permintaan perpanjangan rental', 'Ajukan perpanjangan melalui portal pribadi transaksi. Admin memeriksa ketersediaan dan tambahan tagihan. Periode sewa baru berlaku setelah permintaan disetujui; menyampaikan keinginan lewat chat tidak mengubah tanggal sewa.', ['perpanjangan', 'periode']],
            ['rental', 'pengembalian', 'Pengembalian dan deposit rental', 'Pengembalian melibatkan pemeriksaan kondisi dan kelengkapan unit. Periksa tagihan serta ketentuan deposit pada transaksi. Permintaan pembatalan atau refund ditinjau admin melalui alur transaksi; chat tidak mencairkan dana.', ['pengembalian', 'kelengkapan', 'deposit']],
            ['rental', 'bukti-pembayaran', 'Bukti pembayaran rental', 'Sesudah pesanan dikonfirmasi, gunakan portal pribadi untuk melihat tagihan dan mengunggah bukti pembayaran. Bukti menunggu verifikasi admin dan belum mengurangi saldo tagihan sebelum disetujui.', ['pembayaran', 'portal', 'bukti']],
            ['laundry', 'hasil-timbang', 'Persetujuan hasil timbang laundry', 'Permintaan awal menggunakan estimasi. Setelah penerimaan dan penimbangan, periksa berat aktual, rincian layanan, ongkir, dan tagihan melalui portal. Persetujuan mengacu pada rincian terbaru; penimbangan ulang memerlukan pemeriksaan kembali.', ['timbang', 'penimbangan']],
            ['laundry', 'bukti-pembayaran', 'Pembayaran manual laundry', 'Unggah bukti transfer lewat portal pelanggan untuk diperiksa admin sesuai tagihan. Bukti yang masih menunggu pemeriksaan belum dihitung sebagai pembayaran. Kuitansi mengikuti pembayaran yang sudah dicatat pada transaksi.', ['pembayaran', 'portal', 'bukti']],
            ['laundry', 'antar-jemput', 'Jadwal antar jemput laundry', 'Isi kebutuhan antar-jemput dan alamat pada formulir. Jadwal dan ongkir mengikuti pengaturan outlet serta konfirmasi operasional. Periksa biaya pada rincian tagihan; permintaan jadwal belum berarti petugas sudah ditugaskan.', ['antar', 'jemput', 'ongkir']],
            ['general', 'privasi-status', 'Privasi pengecekan status dan portal', 'Pengecekan status memerlukan kode transaksi, nomor pemesanan, serta kecocokan identitas WhatsApp pengirim. Kode saja tidak memberi akses ke transaksi orang lain. Jangan membagikan tautan portal pribadi. Ketik admin bila kontak pemesanan atau akses perlu diperiksa.', ['privasi', 'portal', 'status']],
        ];
        $created = 0;
        DB::transaction(function () use ($rows, $chunks, &$created) {
            foreach ($rows as [$domain, $key, $title, $content, $keywords]) {
                $record = KnowledgeBase::firstOrCreate(['slug' => 'review-ai-e-'.$domain.'-'.$key], [
                    'title' => $title, 'type' => 'faq', 'content' => $content, 'status' => 'active', 'publication_status' => 'draft',
                    'audience' => 'customer_public', 'is_demo' => false,
                    'meta' => ['origin' => 'implemented_workflow_review', 'requires_business_review' => true, 'keywords' => $keywords],
                ]);
                if ($record->wasRecentlyCreated) {
                    $record->domains()->create(['domain' => $domain]);
                    $chunks->syncChunks($record);
                    $created++;
                }
            }
        });
        $this->info($created.' draft alur layanan dibuat. Tinjau, lengkapi kebijakan bisnis, lalu terbitkan melalui admin. Materi lama tidak ditimpa.');

        return self::SUCCESS;
    }
}
