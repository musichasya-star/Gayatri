<?php

namespace App\Console\Commands;

use App\Models\KnowledgeBase;
use App\Services\AI\KnowledgeBaseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeedAiKnowledgeDemo extends Command
{
    protected $signature = 'ai:seed-knowledge-demo';

    protected $description = 'Create isolated local FAQ examples for simulator testing, never for live customer replies.';

    public function handle(KnowledgeBaseService $chunks): int
    {
        if (! app()->environment('local', 'baileys-local', 'testing')) {
            $this->error('Data demo hanya diperbolehkan di lingkungan lokal/pengujian.');

            return self::FAILURE;
        }
        $topics = [
            'spa' => [
                ['Persiapan pijat dan spa', 'persiapan, perlengkapan', 'Siapkan kebutuhan pribadi dan daftar pertanyaan. Konfirmasikan persiapan khusus treatment dengan admin.'],
                ['Memilih treatment pijat spa', 'treatment, memilih', 'Pilih treatment pada katalog Pijat & Spa. Untuk menentukan layanan sesuai kebutuhan, mintalah bantuan admin; chat tidak menentukan kondisi kesehatan.'],
                ['Pendamping saat spa', 'pendamping, orang tua', 'Tanyakan ketentuan pendamping kepada admin ketika memilih treatment. Jangan menyimpulkan batas usia atau jumlah pendamping sebelum ada ketentuan outlet.'],
                ['Keterlambatan kunjungan spa', 'terlambat, keterlambatan', 'Hubungi admin bila terlambat agar jadwal dapat diperiksa. Slot pengganti tidak otomatis dijamin tersedia.'],
                ['Alamat home service spa', 'alamat, lokasi', 'Alamat lengkap dan titik lokasi home service diisi melalui formulir web. Kelayakan treatment, radius dan ongkos diperiksa melalui pengaturan layanan.'],
                ['Memilih tambahan treatment', 'tambahan, addon', 'Tambahan treatment dipilih pada formulir sesuai pilihan layanan yang tersedia. Periksa rincian akhir sebelum mengirim permintaan.'],
            ],
            'lactation' => [
                ['Persiapan konsultasi laktasi', 'persiapan, konsultasi', 'Siapkan daftar pertanyaan dan informasi yang ingin dibahas saat konsultasi. Persiapan khusus dikonfirmasi dengan tenaga layanan/admin.'],
                ['Memilih layanan laktasi', 'memilih, konseling', 'Laktasi & Konsultasi ASI mempunyai kelompok layanan tersendiri. Gunakan katalog serta formulir kelompok tersebut agar tidak tercampur dengan Pijat & Spa.'],
                ['Pertanyaan umum menyusui', 'menyusui, pertanyaan', 'Asisten membantu informasi layanan dan alur konsultasi. Penilaian kondisi pribadi dan rencana penanganan dibahas langsung dengan tenaga kesehatan yang berwenang.'],
                ['Pendamping konsultasi ASI', 'pendamping, keluarga', 'Konfirmasikan kebutuhan pendamping keluarga kepada admin sebelum kunjungan. Ikuti ketentuan tenaga layanan dan lokasi konsultasi.'],
                ['Konsultasi laktasi ke rumah', 'kunjungan, rumah', 'Kunjungan rumah mengikuti treatment yang memenuhi syarat dan pengaturan home service. Konfirmasikan opsi tersebut saat memilih layanan.'],
                ['Catatan untuk konsultasi', 'catatan, pertanyaan', 'Tuliskan pertanyaan yang ingin dibahas pada formulir atau saat bertemu tenaga layanan. Hindari mengirim dokumen kesehatan pribadi ke percakapan yang tidak diperlukan.'],
            ],
            'rental' => [
                ['Kelengkapan alat rental', 'kelengkapan, komponen', 'Periksa daftar kelengkapan alat dan cocokkan bersama petugas saat serah terima. Catat ketidaksesuaian sebelum alat digunakan.'],
                ['Kebersihan alat rental', 'kebersihan, perawatan', 'Riwayat pemeriksaan dan perawatan dikelola pada unit rental. Tanyakan prosedur kebersihan yang berlaku kepada admin sebelum pemakaian.'],
                ['Memilih beberapa alat rental', 'keranjang, beberapa', 'Gunakan keranjang untuk memilih beberapa alat, jumlah dan periode sewa. Ketersediaan setiap unit diperiksa ketika pesanan diajukan.'],
                ['Perpanjangan rental', 'perpanjangan, periode', 'Ajukan perpanjangan melalui portal pribadi transaksi. Permintaan diperiksa terhadap ketersediaan unit dan memerlukan persetujuan; tanggal tidak berubah hanya karena chat.'],
                ['Pengembalian alat rental', 'pengembalian, pemeriksaan', 'Pengembalian melibatkan pemeriksaan kondisi dan kelengkapan. Hasil pemeriksaan serta tagihan yang berlaku ditinjau melalui transaksi.'],
                ['Deposit alat rental', 'deposit, jaminan', 'Deposit dan ketentuannya dilihat pada katalog/ringkasan pesanan. Pengembalian deposit diproses setelah pemeriksaan serta penyelesaian kewajiban sesuai ketentuan transaksi.'],
            ],
            'laundry' => [
                ['Persiapan laundry ibu bayi', 'persiapan, kantong', 'Siapkan cucian dan jelaskan catatan penanganan yang diperlukan kepada outlet. Ikuti petunjuk pengelompokan serta penerimaan yang dikonfirmasi admin.'],
                ['Pewangi laundry', 'pewangi, parfum', 'Pilihan pewangi dan bahan pembersih dikonfirmasi dengan admin sesuai ketentuan outlet. Asisten tidak menjamin hasil atau kecocokan produk terhadap kondisi kesehatan pribadi.'],
                ['Catatan noda laundry', 'noda, asi', 'Sampaikan catatan noda pada formulir agar outlet dapat meninjau penanganannya. Keberhasilan menghilangkan noda tidak dapat dijamin melalui chat.'],
                ['Konfirmasi hasil timbang laundry', 'timbang, penimbangan', 'Estimasi awal berbeda dari hasil timbang. Periksa hasil timbang dan tagihan final melalui portal pelanggan sebelum memberikan persetujuan.'],
                ['Antar jemput laundry', 'penjemputan, pengantaran', 'Jadwal antar-jemput dan ongkos mengikuti pengaturan outlet serta alamat yang diisi pada formulir. Tunggu konfirmasi jadwal yang tercatat.'],
                ['Pemeriksaan kualitas laundry', 'kualitas, pemeriksaan', 'Pesanan melewati tahap pengerjaan dan pemeriksaan kualitas sebelum serah terima. Jika ada kendala, hubungi admin untuk peninjauan.'],
            ],
            'general' => [
                ['Cara menghubungi admin', 'bantuan, admin', 'Ketik admin atau pilih 0 untuk meminta penanganan manusia. Balasan otomatis berhenti ketika percakapan diserahkan kepada admin.'],
                ['Bukti transfer pembayaran', 'bukti, transfer', 'Unggahan bukti transfer menunggu pemeriksaan admin. Pembayaran baru tercatat setelah diverifikasi melalui jurnal layanan yang sesuai.'],
                ['Akses portal pelanggan', 'portal, akses', 'Portal pribadi dipakai untuk transaksi yang mendukungnya. Jangan membagikan tautan atau token akses; hubungi admin bila akses perlu dipulihkan.'],
                ['Cek status transaksi', 'status, kode', 'Pengecekan memerlukan kode transaksi, nomor pemesanan dan identitas pengirim yang cocok. Data transaksi orang lain tidak ditampilkan hanya karena kodenya diketahui.'],
                ['Cara memesan layanan Gayatri', 'pemesanan, formulir', 'Pilih kelompok layanan dan isi formulir web yang sesuai. Periksa rincian sebelum mengajukan; chat membantu informasi dan tidak membuat transaksi otomatis.'],
                ['Privasi informasi pelanggan', 'privasi, informasi', 'Berikan informasi minimum yang diperlukan. Alamat lengkap, dokumen kesehatan, bukti pembayaran dan catatan internal tidak ditampilkan pada ringkasan status WhatsApp.'],
            ],
        ];
        $count = 0;
        DB::transaction(function () use ($topics, $chunks, &$count) {
            foreach ($topics as $domain => $rows) {
                foreach ($rows as [$title, $keywords, $content]) {
                    $knowledge = KnowledgeBase::firstOrCreate(['slug' => 'demo-ai-c-'.$domain.'-'.Str::slug($title)], [
                        'title' => '[DEMO] '.$title, 'type' => 'faq', 'content' => $content, 'status' => 'active',
                        'publication_status' => 'published', 'audience' => 'customer_public', 'is_demo' => true,
                        'meta' => ['origin' => 'local_simulator_example', 'keywords' => explode(', ', $keywords)],
                    ]);
                    if ($knowledge->wasRecentlyCreated) {
                        $knowledge->domains()->create(['domain' => $domain]);
                        $chunks->syncChunks($knowledge);
                        $count++;
                    }
                }
            }
        });
        $this->info($count.' FAQ demo dibuat. Aktif hanya bila demo diaktifkan pada simulator lokal.');

        return self::SUCCESS;
    }
}
