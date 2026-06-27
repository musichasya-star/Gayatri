<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_page_settings', function (Blueprint $table) {
            $table->id();
            $table->string('site_name')->default('Gayatri Mom & Baby SPA');
            $table->string('slug')->default('home')->unique();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->json('page_settings')->nullable();
            $table->json('seo_settings')->nullable();
            $table->json('theme_settings')->nullable();
            $table->timestamps();
        });

        Schema::create('landing_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landing_page_setting_id')->constrained()->cascadeOnDelete();
            $table->string('type')->index();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('icon')->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->json('content')->nullable();
            $table->json('items')->nullable();
            $table->timestamps();
        });

        Schema::create('landing_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landing_page_setting_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('image');
            $table->string('url');
            $table->string('alt_text')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        $pageId = DB::table('landing_page_settings')->insertGetId([
            'site_name' => 'Gayatri Mom & Baby SPA',
            'slug' => 'home',
            'is_published' => true,
            'published_at' => $now,
            'page_settings' => json_encode([
                'announcement' => 'Soft opening package tersedia minggu ini',
                'whatsapp' => '6281234567890',
                'booking_button' => 'Booking Sekarang',
            ]),
            'seo_settings' => json_encode([
                'meta_title' => 'Gayatri Mom & Baby SPA - Baby Spa & Mom Care Premium',
                'meta_description' => 'Perawatan baby spa, pijat bayi, dan mom postnatal care dengan suasana soft gold brown yang nyaman dan profesional.',
                'keywords' => 'baby spa, mom care, postnatal massage, spa bayi, Gayatri',
            ]),
            'theme_settings' => json_encode([
                'primary_color' => '#7a4e2d',
                'accent_color' => '#c9a227',
                'background_color' => '#f8f3ea',
                'card_radius' => '24px',
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($this->sections($pageId, $now) as $section) {
            DB::table('landing_sections')->insert($section);
        }

        foreach ([
            ['Treatment room', 'image', 'https://images.unsplash.com/photo-1519823551278-64ac92734fb1?auto=format&fit=crop&w=1200&q=80', 'Ruang treatment Gayatri'],
            ['Baby spa pool', 'image', 'https://images.unsplash.com/photo-1544776193-352d25ca82cd?auto=format&fit=crop&w=1200&q=80', 'Area baby spa'],
            ['Mom massage', 'image', 'https://images.unsplash.com/photo-1540555700478-4be289fbecef?auto=format&fit=crop&w=1200&q=80', 'Mom postnatal massage'],
        ] as [$name, $type, $url, $alt]) {
            DB::table('landing_media')->insert([
                'landing_page_setting_id' => $pageId,
                'name' => $name,
                'type' => $type,
                'url' => $url,
                'alt_text' => $alt,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_media');
        Schema::dropIfExists('landing_sections');
        Schema::dropIfExists('landing_page_settings');
    }

    private function sections(int $pageId, mixed $now): array
    {
        $base = ['landing_page_setting_id' => $pageId, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];

        return [
            $base + ['type' => 'navigation', 'title' => 'Navigation Menu', 'subtitle' => 'Menu utama landing page', 'icon' => 'menu', 'sort_order' => 10, 'content' => json_encode(['logo_text' => 'Gayatri', 'cta_label' => 'Booking']), 'items' => json_encode([['label' => 'Home', 'url' => '#home'], ['label' => 'Layanan', 'url' => '#services'], ['label' => 'Paket', 'url' => '#packages'], ['label' => 'FAQ', 'url' => '#faq']])],
            $base + ['type' => 'hero', 'title' => 'Hero Section', 'subtitle' => 'Mom & Baby SPA Premium', 'icon' => 'sparkles', 'sort_order' => 20, 'content' => json_encode(['headline' => 'Perawatan Lembut untuk Bunda dan Buah Hati', 'description' => 'Nikmati baby spa, pijat bayi, dan postnatal care dalam suasana soft gold brown yang hangat, higienis, dan profesional.', 'primary_button' => 'Booking Online', 'secondary_button' => 'Lihat Paket', 'image_url' => 'https://images.unsplash.com/photo-1519823551278-64ac92734fb1?auto=format&fit=crop&w=1200&q=80']), 'items' => json_encode([['label' => 'Terapis tersertifikasi'], ['label' => 'Ruang private'], ['label' => 'Follow-up WhatsApp']])],
            $base + ['type' => 'benefits', 'title' => 'Benefit Highlights', 'subtitle' => 'Manfaat yang dirasakan keluarga', 'icon' => 'heart', 'sort_order' => 30, 'content' => json_encode(['headline' => 'Treatment nyaman, aman, dan terukur']), 'items' => json_encode([['title' => 'Relaksasi bayi', 'description' => 'Membantu bayi lebih rileks dan tidur lebih nyenyak.', 'icon' => 'moon'], ['title' => 'Bonding keluarga', 'description' => 'Momen hangat untuk Bunda dan si kecil.', 'icon' => 'heart-handshake'], ['title' => 'Recovery Bunda', 'description' => 'Dukungan relaksasi pasca melahirkan.', 'icon' => 'flower-2']])],
            $base + ['type' => 'about', 'title' => 'About Section', 'subtitle' => 'Tentang Gayatri', 'icon' => 'flower-2', 'sort_order' => 40, 'content' => json_encode(['headline' => 'Spa keluarga dengan standar pelayanan profesional', 'description' => 'Gayatri Mom & Baby SPA menghadirkan treatment yang lembut, bersih, dan personal untuk bayi, anak, dan Bunda.', 'image_url' => 'https://images.unsplash.com/photo-1540555700478-4be289fbecef?auto=format&fit=crop&w=1200&q=80']), 'items' => json_encode([['label' => '5+ tahun pengalaman'], ['label' => 'Ribuan keluarga dilayani'], ['label' => 'Reservasi mudah']])],
            $base + ['type' => 'services', 'title' => 'Services Section', 'subtitle' => 'Pilihan layanan favorit', 'icon' => 'sparkles', 'sort_order' => 50, 'content' => json_encode(['headline' => 'Layanan Mom & Baby Care']), 'items' => json_encode([['title' => 'Baby Spa Premium', 'description' => 'Hydrotherapy dan pijat lembut bayi.', 'price' => 'Mulai Rp250.000', 'icon' => 'waves'], ['title' => 'Mom Postnatal Massage', 'description' => 'Relaksasi pemulihan untuk Bunda.', 'price' => 'Mulai Rp350.000', 'icon' => 'leaf'], ['title' => 'Kids Relaxing Massage', 'description' => 'Pijat anak untuk relaksasi dan kenyamanan.', 'price' => 'Mulai Rp200.000', 'icon' => 'smile']])],
            $base + ['type' => 'packages', 'title' => 'Treatment Packages', 'subtitle' => 'Paket hemat keluarga', 'icon' => 'package', 'sort_order' => 60, 'content' => json_encode(['headline' => 'Paket treatment fleksibel']), 'items' => json_encode([['title' => 'Baby Glow 3x', 'description' => '3 sesi baby spa premium.', 'price' => 'Rp699.000'], ['title' => 'Mom Recovery', 'description' => '2 sesi postnatal massage + konsultasi.', 'price' => 'Rp650.000'], ['title' => 'Family Care', 'description' => 'Paket Bunda dan bayi dalam satu kunjungan.', 'price' => 'Rp499.000']])],
            $base + ['type' => 'promo', 'title' => 'Promo Section', 'subtitle' => 'Penawaran aktif', 'icon' => 'badge-percent', 'sort_order' => 70, 'content' => json_encode(['headline' => 'Promo Baby Spa 10%', 'description' => 'Gunakan kode BABY10 untuk booking minggu ini.', 'badge' => 'Limited Offer']), 'items' => json_encode([['label' => 'Berlaku sampai akhir minggu'], ['label' => 'Slot terbatas'], ['label' => 'Khusus booking online']])],
            $base + ['type' => 'booking', 'title' => 'Booking Online Form', 'subtitle' => 'Request jadwal dari landing page', 'icon' => 'calendar-check', 'sort_order' => 80, 'content' => json_encode(['headline' => 'Booking Online', 'description' => 'Isi form, tim kami akan konfirmasi jadwal via WhatsApp.', 'success_message' => 'Request booking diterima. Admin akan menghubungi Bunda.']), 'items' => json_encode([])],
            $base + ['type' => 'how_to_book', 'title' => 'How to Book', 'subtitle' => 'Alur reservasi', 'icon' => 'list-checks', 'sort_order' => 90, 'content' => json_encode(['headline' => 'Cara booking mudah']), 'items' => json_encode([['title' => 'Isi Form', 'description' => 'Pilih layanan, tanggal, dan jam favorit.'], ['title' => 'Konfirmasi Admin', 'description' => 'Admin cek slot dan menghubungi via WhatsApp.'], ['title' => 'Datang Treatment', 'description' => 'Nikmati treatment di cabang pilihan.']])],
            $base + ['type' => 'why_choose_us', 'title' => 'Why Choose Us', 'subtitle' => 'Alasan memilih Gayatri', 'icon' => 'shield-check', 'sort_order' => 100, 'content' => json_encode(['headline' => 'Kenapa keluarga memilih Gayatri?']), 'items' => json_encode([['title' => 'Higienis', 'description' => 'Peralatan dibersihkan dan disiapkan sebelum treatment.'], ['title' => 'Personal', 'description' => 'Treatment disesuaikan dengan kebutuhan bayi dan Bunda.'], ['title' => 'CRM Follow-up', 'description' => 'Reminder dan follow-up tercatat rapi di dashboard.']])],
            $base + ['type' => 'gallery', 'title' => 'Gallery', 'subtitle' => 'Suasana treatment', 'icon' => 'images', 'sort_order' => 110, 'content' => json_encode(['headline' => 'Galeri Gayatri']), 'items' => json_encode([['title' => 'Ruang treatment', 'image_url' => 'https://images.unsplash.com/photo-1519823551278-64ac92734fb1?auto=format&fit=crop&w=900&q=80'], ['title' => 'Mom care', 'image_url' => 'https://images.unsplash.com/photo-1540555700478-4be289fbecef?auto=format&fit=crop&w=900&q=80'], ['title' => 'Spa ambience', 'image_url' => 'https://images.unsplash.com/photo-1544161515-4ab6ce6db874?auto=format&fit=crop&w=900&q=80']])],
            $base + ['type' => 'testimonials', 'title' => 'Testimonials', 'subtitle' => 'Cerita customer', 'icon' => 'quote', 'sort_order' => 120, 'content' => json_encode(['headline' => 'Apa kata Bunda?']), 'items' => json_encode([['name' => 'Bunda Alya', 'description' => 'Baby spa-nya nyaman, admin cepat konfirmasi jadwal.', 'rating' => '5'], ['name' => 'Bunda Rania', 'description' => 'Tempatnya elegan dan terapis sangat sabar.', 'rating' => '5']])],
            $base + ['type' => 'faq', 'title' => 'FAQ', 'subtitle' => 'Pertanyaan umum', 'icon' => 'circle-help', 'sort_order' => 130, 'content' => json_encode(['headline' => 'Pertanyaan yang sering ditanyakan']), 'items' => json_encode([['title' => 'Apakah harus booking dulu?', 'description' => 'Disarankan booking agar slot treatment tersedia.'], ['title' => 'Apakah aman untuk newborn?', 'description' => 'Tim akan menyesuaikan treatment dengan usia dan kondisi bayi.'], ['title' => 'Bagaimana pembayaran?', 'description' => 'Pembayaran bisa dilakukan sesuai arahan admin setelah konfirmasi.']])],
            $base + ['type' => 'location_contact', 'title' => 'Location & Contact', 'subtitle' => 'Hubungi dan kunjungi kami', 'icon' => 'map-pin', 'sort_order' => 140, 'content' => json_encode(['headline' => 'Lokasi Gayatri', 'address' => 'Jl. Mawar No. 10, Jakarta Selatan', 'phone' => '021-555-0123', 'whatsapp' => '6281234567890', 'maps_url' => 'https://maps.google.com']), 'items' => json_encode([['label' => 'Senin-Minggu 09.00-18.00'], ['label' => 'Parkir nyaman'], ['label' => 'Reservasi via WhatsApp']])],
            $base + ['type' => 'final_cta', 'title' => 'Final CTA', 'subtitle' => 'Ajak booking', 'icon' => 'send', 'sort_order' => 150, 'content' => json_encode(['headline' => 'Siap menjadwalkan treatment terbaik?', 'description' => 'Kirim request booking online dan tim Gayatri akan membantu konfirmasi slot.', 'button' => 'Booking Sekarang']), 'items' => json_encode([])],
            $base + ['type' => 'footer', 'title' => 'Footer', 'subtitle' => 'Informasi bawah halaman', 'icon' => 'layout-template', 'sort_order' => 160, 'content' => json_encode(['copyright' => '(c) 2026 Gayatri Mom & Baby SPA', 'description' => 'Soft Gold Brown Classic Modern landing page powered by CRM Gayatri.']), 'items' => json_encode([['label' => 'Instagram', 'url' => '#'], ['label' => 'WhatsApp', 'url' => '#booking'], ['label' => 'Admin Login', 'url' => '/login']])],
        ];
    }
};
