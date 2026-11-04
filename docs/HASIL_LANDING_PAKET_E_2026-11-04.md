# Paket E — Konten, SEO lokal, dan performa landing page

Selesai di lokal pada 4 November 2026.

## Panduan admin

1. Buka **Landing Page CMS → Konten & SEO**. Ubah judul pembuka, pengantar, identitas usaha, kota, kontak, jam kunjungan, serta FAQ. Kosongkan tautan Maps untuk memakai pencarian berdasarkan alamat. Nomor WhatsApp ini menjadi kontak umum pada beranda dan katalog; konfigurasi outlet khusus seperti Laundry tetap dikelola di modul masing-masing.
2. Buka **Gambar & media** untuk mengunggah JPG, PNG, atau WebP hingga 5 MB, dengan teks alternatif yang menjelaskan isi foto. Setelah tersimpan, pilih gambar sebagai pembuka atau gambar berbagi di **Konten & SEO**. Untuk Galeri, salin URL media ke item Galeri di **Bagian halaman**.
3. Buka **Layanan unggulan & pengaturan** untuk mengurutkan hingga enam layanan aktif. Tarif dan kesiapan booking mengikuti katalog. Pengaturan lama dan konten halaman booking tetap tersedia.
4. Buka **Pratinjau & terbitkan** untuk memeriksa tampilan desktop, tablet, ponsel, serta kartu berbagi. Daftar periksa memvalidasi isian dan keberadaan aset gambar lokal. Periksa sendiri gambar dari tautan luar, ketepatan kontak, dan tujuan tombol. Draf hanya terlihat oleh staf yang berwenang; perubahan pada beranda yang sudah terbit langsung terlihat setelah disimpan.

## SEO dan performa

- Beranda dan katalog publik memiliki judul, deskripsi, URL kanonis, serta metadata berbagi. Beranda juga menyediakan data terstruktur `LocalBusiness` yang memakai kontak dan alamat yang sama dengan informasi yang terlihat. Tidak ada penambahan koordinat, ulasan, atau jam yang belum dikonfirmasi. [Panduan data terstruktur bisnis lokal](https://developers.google.com/search/docs/appearance/structured-data/local-business).
- `/robots.txt` mengarahkan ke `/sitemap.xml`. Sitemap mencantumkan beranda terbit, katalog utama, Pijat & Spa, Laktasi, informasi Laundry, produk pendukung, serta katalog dan detail alat sewa publik. Draf, pencarian, formulir transaksi, keranjang, portal, dan kuitansi tidak dimasukkan. Halaman transaksi dan pratinjau tetap memakai aturan `noindex`. [Panduan sitemap](https://developers.google.com/search/docs/crawling-indexing/sitemaps/overview) · [Panduan noindex](https://developers.google.com/search/docs/crawling-indexing/block-indexing).
- Inter dan Playfair Display dimuat dari dua berkas WOFF2 lokal dengan `font-display: swap`; lisensinya tersedia di `public/fonts`. Browser desktop yang diuji memilih WebP 768 px sebesar **34.414 byte**, sekitar **77%** lebih kecil dari varian 1.536 px yang sebelumnya menjadi pilihan berikutnya setelah 480 px. Ponsel yang diuji tetap memilih versi 480 px sebesar **20.100 byte**. Gambar besar tetap tersedia untuk layar dengan kepadatan piksel lebih tinggi. Angka ini membandingkan ukuran aset, bukan waktu muat seluruh halaman.

## Hasil lokal

- Beranda diperiksa pada viewport 320, 390, dan 1.366 px tanpa luapan horizontal. Menu ponsel dapat dibuka dan akses katalog tersedia. Tangkapan layar tersimpan di `storage/logs/landing-package-e/`.
- Semua 27 URL yang tercantum di sitemap lokal mengembalikan HTTP 200 dengan URL kanonis yang sama. Form booking, Laundry, dan keranjang merespons normal dan tetap `noindex`. Bukti: `http-checks.json` dan `sitemap-checks.json`.
- Verifikasi unggah, penyimpanan, pratinjau privat, dan penerbitan berjalan pada SQLite pengujian terpisah. Pemeriksaan basis data lokal mengonfirmasi 44 tabel bisnis tetap sama dengan acuan sebelumnya dan lolos pemeriksaan integritas.
- Suite regresi lulus: **819 pengujian dan 7.084 assertion**, dengan grup `legacy-ai` dikecualikan seperti pada Paket D. Laporan ada di `storage/logs/landing-package-e/full-suite.txt`.

Pada validasi Paket F, pastikan `APP_URL` produksi memakai domain HTTPS publik, jalur `/robots.txt` dan `/sitemap.xml` diteruskan ke Laravel, serta penyimpanan gambar publik tersedia. Pemeriksaan indeks dan performa jaringan publik dilakukan setelah rilis. Paket E belum diterapkan ke VPS.
