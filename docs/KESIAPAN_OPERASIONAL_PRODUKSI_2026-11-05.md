# Kesiapan Operasional Produksi dan Pengujian Transaksi Pelanggan

## Menu admin

`/admin/readiness` tersedia untuk owner, manager, dan admin. Menu juga dapat
dibuka dari dashboard. Pengguna sales/terapis serta pengunjung tidak mendapat
akses. Pemeriksaan mengamati pengaturan tanpa mengaktifkan layanan, mengubah
harga, menerbitkan panduan, atau membuat transaksi.

Lima belas pemeriksaan mencakup sesi WhatsApp utama, worker/antrean/webhook,
konfigurasi AI, cakupan knowledge, cabang publik, katalog dan jadwal dua kelompok
treatment, kesiapan unit rental, Laundry, instruksi pembayaran, Home Service,
serta email pemulihan akun. Koneksi gateway diperiksa melalui endpoint kesehatan
dengan timeout terbatas. API key tidak ditampilkan atau diuji ulang setiap kali
halaman dibuka.

Tautan **Unduh daftar kelengkapan** menghasilkan CSV katalog yang memerlukan
tinjauan, berisi kode, nama, rincian yang kurang, serta tautan edit. Nilai yang
dapat menjadi formula spreadsheet dinetralkan. Respons halaman/CSV privat dan
tidak disimpan cache browser.

## Pengujian produksi pada 5 November 2026

- OpenAI `gpt-4.1-mini`: pemeriksaan akses model dan satu permintaan generasi
  teknis tanpa data pelanggan menghasilkan HTTP 200 dan jawaban yang diharapkan.
- Baileys `gayatri-main`: WORKING. Pesan uji dikirim satu kali ke nomor yang
  secara eksplisit diberikan pengguna; catatan penerimaan gateway dan delivery
  tersedia di inbox/monitor. Tidak ada transaksi keuangan yang dibuat.
- Backup baru dibuat setelah konfigurasi AI dan sesi WhatsApp diperbarui.
- Backup dipulihkan ke dua database MariaDB sementara dengan akun yang hanya
  memiliki akses ke database verifikasi tersebut. Tidak ada aplikasi, worker,
  scheduler, sesi gateway, atau konfigurasi produksi yang dijalankan dari salinan.
- Hasil restore: **77 tabel, 154 foreign key, 59 berkas**, integritas tabel dan
  hubungan data lulus. Dump kanonis hasil restore pertama dan kedua identik.
  Pelanggan 21, booking 8, rental 11, dan Laundry 6 pada backup yang diuji.
- Database, akun, dan direktori kerja verifikasi sementara dibersihkan setelah
  selesai. Backup asli tetap disimpan. Script yang dapat dipakai kembali:
  `scripts/operations-restore-check.py --backup /srv/gayatri/backups/daily/gayatri-<UTC>.tar.gz`.
  Script hanya menerima direktori/nama backup Gayatri dan memerlukan root lokal.

## Bukti pengujian dan masa berlaku

Ringkasan bukti disimpan privat pada disk `local`, `operations/verification.json`.
Halaman hanya membaca empat jenis bukti yang diizinkan. Nilai bebas, exception,
API key, nomor pelanggan, dan rekaman transaksi tidak disalin ke tampilan.
Hasil lebih dari 24 jam ditandai perlu diuji kembali; bukti provider juga
ditandai lama jika provider, model, atau API key berubah. Hasil lama tetap
memperlihatkan waktu pemeriksaannya.

Keberhasilan panggilan OpenAI terpisah dari keputusan balasan pelanggan:
jalur informasi aktif menggunakan katalog, panduan publik yang terbit,
verifikasi pemilik transaksi, dan kendali penyerahan kepada admin. Penambahan
API key tidak menerbitkan FAQ atau membuka kembali transaksi melalui AI.

## Kelengkapan usaha

Rincian yang belum tersedia tetap mengikuti keputusan pemilik untuk konfirmasi
admin. Jangan mengisi durasi, harga, deposit, jumlah unit, ataupun jadwal dengan
angka perkiraan hanya agar status menjadi hijau.

Urutan penyelesaian oleh pengelola usaha:

1. Lengkapi cabang nyata beserta alamat/kontak, kemudian buka slot sesuai jadwal
   terapis untuk Pijat & Spa dan Laktasi.
2. Gunakan CSV kelengkapan untuk meninjau durasi/harga, deposit dan jeda
   pembersihan rental, unit fisik, serta minimum/pembulatan/estimasi Laundry.
3. Tinjau dan terbitkan FAQ yang sesuai kebijakan nyata melalui akun owner atau
   manager. Draf dan demo tetap tidak digunakan untuk FAQ pelanggan.
4. Lengkapi rekening/instruksi DP-pelunasan. Aktifkan penerimaan dan pemesanan
   Laundry ketika outlet serta tarifnya siap.
5. Siapkan pengiriman email dan verifikasi penerimaan tautan pemulihan akun.

## Batas pengujian

Validasi lokal lulus: **424 tes, 5.075 assertions**. Rinciannya: 28 tes kesiapan,
hak akses, navigasi dan pengaturan (592 assertions); 393 tes transaksi dan
verifikasi AI (4.214 assertions); serta tiga perjalanan pelanggan baru yang
meneruskan pesanan yang sama sampai penyelesaian (269 assertions). Lint PHP,
parsing CSS, dan sintaks script restore lulus. Pengujian kesiapan serta tiga
perjalanan lengkap juga ditambahkan ke workflow GitHub tersendiri.

Pengujian transaksi menggunakan data sintetis dan database SQLite dalam memori,
gateway/mail palsu, serta penyimpanan pengujian terpisah. Produksi diperiksa
melalui akses halaman, kesehatan service, panggilan AI teknis, pengiriman ke
nomor uji yang diizinkan, dan restore database terisolasi. Hasil transaksi
terisolasi tidak berarti jadwal/stok/ketentuan usaha yang belum diisi sudah siap.
Tes pesan masuk dari perangkat nyata memerlukan pemilik nomor membalas pesan
uji; bedakan bukti tersebut dari simulasi penerimaan webhook.
