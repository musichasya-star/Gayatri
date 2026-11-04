# Hero video landing page — 4 November 2026

Hero beranda kini memakai video suasana spa yang diberikan pengguna, dengan teks dan tombol tetap berupa HTML yang bisa dibaca sebelum video selesai dimuat.

- Desktop/tablet di atas 700 px: diperbarui menggunakan `Create_baby_spa_background_loop_45a33037.mp4`, 1280 × 720 (16:9), melalui aset `spa-desktop-v2.mp4`.
- Ponsel sampai 700 px: sumber `Create_baby_spa_looping_video_e89ca9f8.mp4`, 360 × 640 (9:16). Bidang hero mengikuti proporsi vertikal dan dapat bertambah tinggi untuk konten yang lebih panjang.
- Kedua sumber sekitar 8 detik, diolah menjadi MP4 H.264 tanpa audio dengan metadata di awal file untuk pemutaran web. Ukuran keluaran 677.702 byte (desktop) dan 365.048 byte (ponsel); resolusi asli dipertahankan. Gambar pengganti WebP diambil dari frame awal video masing-masing; poster desktop baru berukuran 19.962 byte. Nama aset desktop versi baru memastikan browser mengambil video dan poster pengganti.
- Browser hanya memuat video yang cocok dengan ukuran layar. Animasi berulang tanpa suara dan berhenti saat hero tidak terlihat atau tab berada di latar. Sesuai permintaan terbaru, tombol jeda/putar disembunyikan pada desktop dan mobile.
- Preferensi pengurangan gerakan dan penghemat data menggunakan gambar diam. Jika autoplay ditolak atau video gagal, latar memakai gambar pengganti. Perilaku mengikuti [panduan autoplay media MDN](https://developer.mozilla.org/en-US/docs/Web/Media/Guides/Autoplay).

## Pengaturan admin

Di **Landing Page CMS → Konten & SEO**, pilih **Latar pembuka → Video suasana spa** atau **Gambar pilihan**. Judul dan pengantar tetap bisa diubah. Konten lama yang sudah menggunakan foto khusus tetap memakai mode gambar sampai admin memilih video.

## Verifikasi

Penggantian video desktop v2:

- 2 pengujian PHP dengan 32 assertion serta 5 pengujian JavaScript lulus.
- Browser desktop 1366 px memutar video v2 pada resolusi 1280 × 720 tanpa error; tombol jeda/putar berfungsi dan tidak ada luapan horizontal.
- Browser mobile 390 px tetap memutar video v1 360 × 640 beserta poster mobile sebelumnya, tanpa error atau luapan horizontal.
- Bukti visual pembaruan tersimpan di `storage/logs/hero-video-desktop-v2/desktop.png` dan `mobile.png`.

Verifikasi implementasi awal:

- 40 pengujian PHP dengan 467 assertion lulus, mencakup landing page, CMS, dan halaman layanan publik.
- 5 pengujian JavaScript lulus: pemilihan sumber, pergantian ukuran saat dijeda, pengurangan gerakan, penghemat data, tab/latar, autoplay ditolak, dan media gagal.
- Pemeriksaan browser pada lebar 320, 390, 768, 1.024, dan 1.366 px tidak menemukan luapan horizontal. Komposisi teks diperbaiki pada ukuran 320 px. Pergantian sumber desktop/ponsel, kontrol jeda/putar, dan jeda saat keluar layar diverifikasi.
- Bukti visual dan hasil uji PHP tersimpan di `storage/logs/hero-video/`.

Implementasi dilakukan di lokal. Video mobile dan posternya tidak diubah. Ukuran 360 × 640 (mobile) dan 1280 × 720 (desktop terbaru) merupakan resolusi sumber; tampilan di layar besar mengikuti kualitas sumber tersebut.
