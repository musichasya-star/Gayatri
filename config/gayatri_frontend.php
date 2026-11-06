<?php

return [
    'asset_version' => 'pink-ocean-20261105',
    'admin_script_version' => 'ai-provider-keys-20261106',
    'hero_video' => [
        'desktop' => '/videos/landing/spa-desktop-v2.mp4',
        'mobile' => '/videos/landing/spa-mobile-v1.mp4',
        'poster_desktop' => '/videos/landing/spa-desktop-poster-v2.webp',
        'poster_mobile' => '/videos/landing/spa-mobile-poster-v1.webp',
    ],
    // Initial curation from MENU GAYATRI-1.pdf. Current price/readiness always comes from the database.
    'featured_catalog_codes' => ['GM-07-072', 'GM-07-074', 'GM-03-003', 'GM-05-055', 'GM-13-225', 'GM-13-231'],
    'featured_illustrations' => [
        'GM-07-072' => 'baby-massage',
        'GM-07-074' => 'baby-spa',
        'GM-03-003' => 'mom-massage',
        'GM-05-055' => 'mother-baby',
        'GM-13-225' => 'prenatal',
        'GM-13-231' => 'working-mom',
    ],
    'instagram' => 'https://www.instagram.com/gayatribabyspa/',
    'public_source_pages' => [1, 2],
    'faq' => [
        ['title' => 'Layanan apa saja yang tersedia?', 'description' => 'Gayatri memiliki Pijat & Spa, Laktasi & ASI, Sewa Alat Bayi, serta Laundry Ibu & Bayi. Pilih kartu layanan untuk melihat rincian dan status pemesanannya.'],
        ['title' => 'Bagaimana cara memesan treatment atau konsultasi?', 'description' => 'Pilih layanan dan variasinya, lalu lengkapi jadwal serta kontak pada formulir web. Paket beberapa sesi atau pilihan yang belum lengkap perlu konfirmasi admin terlebih dahulu.'],
        ['title' => 'Apakah bisa dilayani di rumah?', 'description' => 'Pilihan kunjungan rumah mengikuti layanan dan area yang tersedia pada formulir pemesanan. Isi alamat lengkap dan lokasi; biaya perjalanan ditampilkan sesuai pengaturan layanan sebelum permintaan dikirim.'],
        ['title' => 'Bagaimana pemesanan sewa alat bayi?', 'description' => 'Buka katalog sewa, pilih alat dan periode, lalu periksa ketersediaan. Unit, deposit, serta antar-jemput mengikuti rincian pesanan dan konfirmasi admin.'],
        ['title' => 'Apakah Laundry sudah bisa dipesan?', 'description' => 'Periksa status pada halaman Laundry. Form pemesanan tersedia saat outlet membuka layanan; jika belum dibuka, hubungi admin untuk informasi. Hasil timbang dan rincian biaya dikonfirmasi sebelum pengerjaan.'],
        ['title' => 'Bagaimana cara pembayarannya?', 'description' => 'Pembayaran masih manual. Ikuti instruksi pada pesanan atau dari admin setelah konfirmasi. Jika tersedia, unggah bukti melalui portal pesanan; pembayaran dinyatakan terverifikasi setelah diperiksa admin.'],
    ],
    'images' => [
        'hero' => ['file' => 'hero-natural-v2', 'alt' => 'Ilustrasi foto Bunda menggendong bayi dalam suasana keluarga yang hangat.'],
        'spa' => ['file' => 'service-spa-natural-v1', 'alt' => 'Ilustrasi foto perawatan bahu anak dengan pendampingan orang tua.'],
        'lactation' => ['file' => 'service-lactation-natural-v1', 'alt' => 'Ilustrasi foto percakapan pendampingan menyusui bersama Bunda.'],
        'rental' => ['file' => 'service-rental-natural-v1', 'alt' => 'Ilustrasi perlengkapan bayi berupa stroller dan kursi makan, bukan foto unit sewa tertentu.'],
        'laundry' => ['file' => 'service-laundry-natural-v1', 'alt' => 'Ilustrasi foto proses melipat pakaian bayi yang bersih.'],
    ],
];
