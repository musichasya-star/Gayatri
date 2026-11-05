# Tema Pink & Ocean

Perubahan 5 November 2026 menyatukan warna website publik, dashboard admin,
portal pelanggan, formulir, laporan, serta invoice/kuitansi.

## Palet

Sumber warna utama: `public/css/gayatri-palette.css`.

| Peran | Warna |
| --- | --- |
| Biru laut utama | `#0b718b` |
| Judul dan struktur | `#153e56` |
| Sidebar | `#102f44` ke `#09566e` |
| Pink utama | `#b83271` |
| Pink pastel | `#fce5f0` |
| Biru pastel | `#e8f5fa` |
| Teks isi | `#203d50` |
| Teks sekunder | `#526b7b` |

Layout HTML memuat `partials.theme` sebelum stylesheet modul. Variabel lama
dipertahankan sebagai alias agar komponen modul tetap kompatibel. Gunakan
variabel `--g-*` untuk komponen baru. Warna status sukses, peringatan, dan error
tetap dibedakan dari warna merek.

Ilustrasi SVG layanan, placeholder rental, dan placeholder peta memakai palet
yang sama. Foto serta video asli dipertahankan; hero menggunakan overlay pink
lembut untuk menjaga keterbacaan. Versi aset dikendalikan melalui
`config/gayatri_frontend.php` agar browser mengambil stylesheet/SVG terbaru.

## Pemeriksaan

- 116 tes terkait render, navigasi, katalog, operasional, portal, dan dokumen:
  lulus dengan 1.615 assertions.
- Build Vite dan parsing seluruh CSS: berhasil.
- Pemeriksaan browser pada lebar 1280 dan 390 piksel: beranda, dashboard,
  katalog admin, laporan Laundry, dan halaman pemesanan Laktasi.
- Warna teks utama/sekunder serta teks putih pada tombol utama memiliki rasio
  kontras minimal 5,5:1 pada permukaan yang ditentukan palet.

## Penerapan

Pembaruan produksi hanya mengganti kode tampilan pada release Gayatri baru.
Database, unggahan, kredensial, sesi gateway, dan konfigurasi project lain tidak
diimpor ulang atau diubah. Simpan release sebelumnya untuk rollback. Bangun
ulang cache konfigurasi/view pada release baru dan mulai ulang hanya service
PHP Gayatri setelah pergantian symlink `current`.
