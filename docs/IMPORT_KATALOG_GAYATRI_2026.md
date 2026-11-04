# Impor katalog Gayatri — 2 November 2026

Katalog dari **MENU GAYATRI-1.pdf** sudah diterapkan ke aplikasi lokal, termasuk variasi, harga, halaman sumber, serta foto produk. Sesuai arahan pemilik, semua pilihan dimasukkan dan rincian yang belum tersedia dikonfirmasi admin.

Sumber: 23 halaman, SHA-256 `dd5402229e62919130e9167e747a74a417023009b90d211f019a8a22fbca116a`. Data yang ditinjau tersimpan di `database/data/gayatri-menu-2026.json`. Harga dan deskripsi berasal dari dokumen; isi dokumen diperlakukan sebagai data katalog.

## Hasil

| Modul | Data yang ditambahkan |
|---|---:|
| Treatment, kelas, tambahan, dan paket | 263 variasi dalam 82 keluarga layanan |
| Kelompok Pijat & Spa | 231 variasi |
| Kelompok Laktasi & Konsultasi ASI | 32 variasi |
| Hubungan tambahan dengan layanan anak | 157 hubungan, belum ditawarkan otomatis selama durasinya belum dikonfirmasi |
| Rental | 20 produk dengan tarif mingguan, dua minggu, dan bulanan sesuai dokumen |
| Produk pendukung | 9 produk fisik, terpisah dari booking dan rental |
| Laundry | 11 tarif; satuan atau kilogram sesuai dokumen |
| Aset | 29 foto produk dan 23 halaman sumber dalam WebP, total 52 berkas sekitar 3,4 MB |

Sebanyak 63 variasi mempunyai harga dan durasi untuk pilihan jadwal web. Sebanyak 200 variasi menggunakan konfirmasi admin. Pilihan jadwal tetap memerlukan slot tersedia dari admin; impor tidak membuat jadwal, terapis, atau transaksi fiktif.

## Cakupan halaman sumber

| Halaman PDF | Cakupan | Jumlah variasi/baris impor |
|---|---|---:|
| 1 | Konseling laktasi dan kelas stimulasi tumbuh kembang yang disebut di pengantar, tanpa tarif/durasi | 2 |
| 2 | Jam layanan, homecare, dan informasi Member Card; tersedia pada halaman sumber | Informasi, bukan transaksi atau pemberian poin otomatis |
| 3 | Ibu/remaja putri, Mom/Girl SPA, V-SPA, pijat ekspres, paket Pijat Brengan | 26 |
| 4 | Pijat/SPA ibu hamil, induksi, perineum, Melahirkan Nyaman | 9 |
| 5 | Yoga, nifas, sumbatan payudara, laktasi dan premium | 25 |
| 6 | Perawatan pascapersalinan, memandikan bayi, bengkung | 7 |
| 7 | Baby, pijat premature, skrining, MPASI, dan tambahan Baby | 25 |
| 8 | Toddler, skrining, MPASI, dan tambahan Toddler | 18 |
| 9 | Kid dan tambahan Kid | 15 |
| 10 | Pijat nafsu makan, oromotor, batuk pilek, insomnia | 45 |
| 11 | Pencernaan, stimulasi berjalan, gadget addiction, diare | 30 |
| 12 | Demam, tumbuh gigi, belekan | 24 |
| 13 | Kelas menyusui reguler/private/homecare dan kombinasi bundling | 17 |
| 14 | Kelas newborn reguler/private/homecare | 3 |
| 15 | Kelas persalinan dan pendamping, bundling | 9 |
| 16–17 | Tindik dan aftercare menurut usia serta tenaga layanan | 8 |
| 18 | Kidos, Larasmara, Tiwa, dan bundling | 9 produk |
| 19–22 | Pompa ASI, kursi makan, car seat, stroller, bouncer, mainan, kebutuhan ibu/bayi | 20 produk |
| 23 | Gayatri Baby Laundry | 11 tarif |

Pilihan tindik menyertakan delapan model anting per kategori usia. Pilihan cukur gundul menyertakan ukuran 0, 3, 6, 9, dan 12 mm. Komponen yang sudah termasuk layanan, seperti mandi hangat pada paket swim atau scrub pada SPA, tidak ditambahkan lagi sebagai biaya tambahan pada hubungan yang diimpor.

## Akses dan pengelolaan

- Katalog publik: `http://127.0.0.1:8000/layanan`, empat tab untuk treatment/kelas, rental, produk pendukung, dan laundry.
- Treatment: menu **Layanan Pijat & Spa** dan **Laktasi & Konsultasi ASI**. Edit keluarga produk, nama variasi, harga, durasi, status, alur pemesanan, dan tambahan layanan.
- Rental: **Katalog Rental**, kemudian **Stok Unit** untuk mencatat unit fisik yang benar-benar ada. Kolom tarif dua minggu sudah tersedia.
- Produk fisik: menu baru **Produk Pendukung** untuk owner, manager, dan admin. Pemesanan serta stok masih dikonfirmasi melalui admin.
- Laundry: **Katalog Tarif Laundry**. Lengkapi minimum, pembulatan, dan estimasi pengerjaan sebelum menonaktifkan penanda konfirmasi.

Katalog mengelompokkan variasi agar pelanggan tidak menghadapi 263 pilihan terpisah sekaligus. Harga, durasi, tautan jadwal/konfirmasi, halaman sumber, dan pilihan detail berubah ketika variasi dipilih. Layanan satu sesi yang siap akan terpilih otomatis pada formulir booking. Tombol WhatsApp menyiapkan pertanyaan produk untuk dikirim sendiri oleh pelanggan.

AI membaca katalog aktif dari database, membedakan keluarga/variasi, dan memberikan tautan yang sesuai. Pilihan premium, private/reguler, usia anak, durasi, dan jumlah sesi disaring dari data katalog. Permintaan variasi yang tidak tercantum tidak diberi harga rekaan. Jalur status transaksi, verifikasi pemilik, dan penyerahan ke admin tetap didahulukan. Harga produk fisik juga dapat dijawab tanpa membuat pesanan melalui chat.

## Data yang harus dikonfirmasi admin

1. Durasi yang tidak tertulis dan harga yang tidak tersedia tetap kosong, dengan label konfirmasi admin.
2. Paket beberapa sesi/hari, gabungan layanan, dan tambahan tetap menggunakan konfirmasi admin. Sistem menolak pengajuan yang mencoba menjadikannya satu booking biasa. Belum ada saldo paket atau pencatatan pemakaian per kunjungan otomatis.
3. Harga homecare kelas tidak dianggap Rp50.000 total. Angka itu dicatat sebagai tambahan di luar tarif kelas dan transport; pilihan serta total dikonfirmasi admin.
4. Rental tidak diberi unit atau deposit fiktif. Deposit serta jeda perawatan harus ditetapkan sebelum estimasi/pemesanan otomatis. Tarif harian yang tidak tersedia tidak ditampilkan sebagai gratis.
5. Laundry belum dapat dihitung sebagai penerimaan normal sebelum minimum, pembulatan, dan estimasi selesai dilengkapi. Form admin memvalidasi ketiga nilai tersebut saat konfirmasi dinonaktifkan.
6. Poin Member Card, diskon loyalitas, kelayakan tindakan kesehatan, serta klaim hasil perawatan tidak diotomatisasi dari brosur.

Hal yang diperiksa khusus: Busui Kembali Bekerja private Rp179.000; Tiwa Oil 100 ml Rp79.000 dengan harga lama Rp100.000 dicoret; Mom Cozy M6 hanya dua minggu/bulanan; Terapi Tumbuh Gigi hanya Toddler dan Kid sesuai tabel sumber.

## Perlindungan data dan pengulangan impor

Backup sebelum perubahan: `storage/app/backups/catalog-import-before-20261102-191449.sqlite`. Pemeriksaan backup menghasilkan `integrity_check=ok`. Perbandingan 20 kelompok tabel membuktikan baris lama pelanggan, transaksi, pembayaran, WhatsApp, knowledge base, dan konteks AI tetap sama.

Enam contoh aktif diarsipkan: dua contoh treatment bawaan dengan nama/deskripsi/harga yang persis cocok dan empat rental berlabel DEMO. Riwayat transaksi tetap menggunakan referensi serta snapshot harga awal. Data kustom tidak dihapus. Tarif laundry demo sebelumnya sudah tidak aktif.

Impor memakai kode katalog/SKU stabil dan `firstOrCreate`, sehingga tidak menggandakan produk atau menimpa harga/deskripsi yang kemudian diedit admin. Penambahan relasi tambahan memakai identitas layanan induk/tambahan. Menjalankan ulang impor dapat memulihkan hubungan tambahan yang sudah dihapus admin; tinjau dahulu bila katalog telah disesuaikan.

Pratinjau lokal:

```powershell
C:\xampp\php\php.exe artisan catalogue:import-gayatri --env=baileys-local
```

Penerapan lokal eksplisit:

```powershell
C:\xampp\php\php.exe artisan migrate --env=baileys-local --force
C:\xampp\php\php.exe artisan catalogue:import-gayatri --env=baileys-local --apply --archive-seed-examples
```

Perintah apply dibatasi ke lingkungan lokal/testing. Berkas WebP disertakan di `storage/app/public/catalog/gayatri-menu/`; pengecualian Git dibatasi hanya ke direktori katalog ini. `public/storage` harus terhubung ke storage publik. Lingkungan kerja ini sudah mempunyai junction tersebut.

Untuk pemulihan, hentikan proses yang memakai database lokal dan simpan salinan database terbaru terlebih dahulu, kemudian pulihkan backup yang telah diverifikasi. Backup sebelum impor mencakup skema lama; pasangkan pemulihan database dengan revisi kode yang sesuai. Jangan menjalankan rollback yang mengubah rincian tak diketahui menjadi nol atau durasi tebakan.

## Validasi selesai

- Suite aktif: **743 pengujian, 6.379 assertions, lulus**. Kelompok `legacy-ai` yang mempertahankan ekspektasi transaksi WA lama tidak termasuk suite ini.
- Pengujian mencakup harga/usia sesuai PDF, impor ulang, larangan booking paket yang dipalsukan tanpa membuat pelanggan/slot terpesan, perlindungan mode paket, kontrol akses produk pendukung, penolakan laundry belum lengkap, kecocokan variasi AI, serta tarif/perpanjangan sewa 14 hari memakai harga transaksi awal.
- SQLite 3.51.3 dan MariaDB 10.4.32 lokal: migrasi baru/upgrade, histori keuangan, impor katalog, serta query kelayakan booking lulus. Pemeriksaan duplikasi dan konkurensi gateway simulasi juga lulus.
- Browser lokal: variasi premium 1/5 sesi, pemilihan otomatis ke booking, delapan pilihan anting dan teks pertanyaan, harga/foto produk, rental dua minggu, seluruh 11 tarif laundry, menu admin, serta tampilan ponsel 390 px diperiksa. Tidak ditemukan overflow horizontal pada katalog yang diperiksa.
- 52 WebP berhasil dibaca; 29 foto dibandingkan secara visual dengan halaman sumber.
- Data operasional tetap: 21 pelanggan, 8 booking, 2 pembayaran booking, 11 transaksi rental, 6 pesanan laundry, 18 pesan WhatsApp, dan 0 antrean job. Tidak ada pesan WhatsApp nyata atau panggilan provider AI berbayar selama pemeriksaan.
- Pint dan pemeriksaan sintaks dijalankan pada daftar file PHP yang terkait. Hasil rinci tersedia di `storage/logs/catalog-import-*`.

Seluruh penerapan ini dilakukan di lokal. VPS, domain produksi, dan project lain di VPS tidak diubah. Untuk rilis berikutnya, siapkan paket migrasi/data/aset dan tinjau konfigurasi operasional admin sebelum penerapan produksi.
