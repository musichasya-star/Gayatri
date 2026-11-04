# Audit UI/UX dashboard admin Gayatri

Tanggal: **2 November 2026, WIB**. Pemeriksaan dilakukan pada aplikasi lokal dengan akun owner, sesudah impor katalog. Tujuannya menilai keterbacaan katalog, navigasi, penggunaan ruang, konsistensi, formulir, dan alur kerja admin.

## Ringkasan hasil

**Dashboard perlu dibenahi pada susunan informasi dan komponen bersama.** Jumlah modul, variasi layanan, dan pengaturan sudah melampaui kapasitas beberapa pola tampilan awal. Akibatnya, daftar menjadi panjang, informasi berulang, bagian penting berada jauh di bawah layar, dan sejumlah tampilan ponsel terpotong.

Prioritas paling awal adalah **error 500 Transaksi Sewa**, sel layanan/produk yang terpotong pada ponsel, dan halaman arsip dokumen yang melebar. Sesudah itu, susun ulang katalog menurut keluarga layanan serta bedakan status publikasi dengan kesiapan pemesanan.

Warna cokelat–emas, pemisahan modul bisnis, ketentuan konfirmasi admin, foto produk yang sudah tersedia, dan perlindungan transaksi perlu dipertahankan dalam pengerjaan berikutnya.

## Ruang lingkup dan bukti

- **56 tujuan menu sidebar** dibuka pada desktop **1366 × 900** dan ponsel **390 × 844**: total 112 pemeriksaan halaman utama.
- **55 tujuan menu berhasil menampilkan halaman; satu tujuan, Transaksi Sewa, menampilkan 500** pada kedua ukuran.
- Tujuh formulir tambahan dibuka: edit layanan Baby SPA, tambah alat rental, tambah produk pendukung, tambah tarif laundry, tambah booking, pengaturan fee terapis, dan tambah knowledge.
- Uji interaksi terbatas: pencarian topbar, pencarian daftar layanan, menu ponsel dan Escape, serta inspeksi arsip dokumen pada lebar 320 px.
- Screenshot, snapshot DOM, dan pengukuran tersimpan di `storage/logs/ui-ux-admin-20261102/`. Berkas utama: `measurements.json`, `forms.json`, `interaction-findings.json`, dan `data-findings.json`.
- Pemeriksaan mencakup pembacaan tampilan, kode, log error, dan struktur data lokal. Formulir tidak disimpan; pesan WhatsApp, pembayaran, simulasi provider AI, serta perubahan konfigurasi tidak dijalankan. VPS tidak diakses.
- Temuan di bawah berlaku untuk data dan filter yang terlihat saat audit. Tampilan tanpa data pada beberapa modul mengikuti periode/filter serta pengecualian data demo; keadaan tersebut tidak otomatis berarti modul rusak.

## Temuan yang harus didahulukan

| Prioritas | Temuan terverifikasi | Dampak | Saran |
|---|---|---|---|
| P0 | Menu Transaksi Sewa menampilkan 500. Log menunjukkan `Undefined array key "total"`; view membaca `pricing_snapshot['total']` langsung. Pemeriksaan baca saja menemukan 2 dari 11 snapshot tidak mempunyai kunci tersebut. | Daftar transaksi rental tidak dapat digunakan. | Dukung bentuk snapshot historis yang ada, tentukan sumber total yang tepat, lalu tambah uji daftar dengan data lama. Nilai yang tak tersedia harus dinyatakan jelas; jangan mengisi angka nol seolah tagihan valid. |
| P1 | Pada lebar 390 px, sel identitas layanan sekitar 303 px mempunyai isi selebar 334 px. Nama, badge, dan kode tersusun menyamping lalu terpotong. Masalah serupa terlihat pada produk pendukung. | Identitas pilihan sulit dibaca; tidak adanya scrollbar halaman menyembunyikan masalah. | Buat susunan kartu ponsel yang jelas: gambar/nama, variasi, harga, kesiapan, lalu aksi. Satukan isi utama sel dalam pembungkus vertikal; hindari setiap node isi menjadi item flex terpisah. |
| P1 | Arsip Invoice & Kuitansi menggunakan layout dokumen tersendiri, kehilangan sidebar admin, dan melebar hingga 689 px pada viewport 320 px. | Konteks navigasi hilang dan seluruh halaman harus digeser mendatar. | Gunakan kerangka admin untuk halaman arsip; layout cetak dipakai pada pratinjau/cetak dokumen. Batasi scroll mendatar di pembungkus tabel. |
| P1 | Sidebar menampilkan 56 tautan; tinggi isinya sekitar 2.979 px. Rental mengulang 14 tautan pada navigasi lokal, laundry 10. | Pencarian menu dan perpindahan tugas memerlukan banyak perhatian serta guliran. | Kelompok menu dapat dibuka/tutup, tampilkan konteks modul aktif, dan ringkas navigasi lokal menurut kelompok tugas. |
| P1 | Pencarian topbar menerima teks dan Enter, tetapi URL tidak berubah dan tidak muncul hasil. Input tidak berada dalam form; skrip bersama hanya menangani sidebar. | Kontrol menjanjikan pencarian global yang belum bekerja. | Implementasikan hasil lintas modul yang tetap mengikuti hak akses. Selama belum tersedia, tampilkan kontrol dengan fungsi yang jelas dan sesuai implementasinya. |
| P1 | Inbox memakai `chat-layout-vertical` juga pada desktop. Awal panel chat berada sekitar y=1.005 dan area balasan sekitar y=1.848 pada viewport setinggi 900 px. | Admin harus berpindah guliran antara daftar percakapan dan isi chat. | Desktop: daftar percakapan dan chat berdampingan; profil menjadi panel opsional. Ponsel: alur daftar → chat → kembali, dengan area tulis tetap mudah dijangkau. |
| P1 | Home Service memuat 276 kontrol. Kontennya sekitar 10.471 px pada desktop dan 14.951 px pada ponsel. | Pemilihan layanan dan tombol simpan sulit dijangkau; risiko pilihan terlewat meningkat. | Pisahkan Lokasi, Tarif/Jangkauan, Layanan, dan Terapis. Gunakan pencarian/pengelompokan pilihan, daftar yang sudah dipilih, serta tombol simpan yang mudah dijangkau. |
| P1 | Promo demo bernama 10% tampil dengan nilai Rp10. Seeder menggunakan tipe `percentage`, sedangkan form, validasi, dan format daftar menggunakan `percent`. | Maksud diskon dan nilai yang dipahami admin tidak cocok. | Rekonsiliasi tipe data lama dan aturan perhitungan/presentasinya. Uji promo nominal serta persentase sampai ke ringkasan transaksi. |

Sumber kode utama: [daftar transaksi rental](C:/xampp/htdocs/Gayatri/resources/views/admin/rentals/orders/index.blade.php:12), [sel layanan](C:/xampp/htdocs/Gayatri/resources/views/admin/services/index.blade.php:62), [aturan tabel ponsel](C:/xampp/htdocs/Gayatri/public/css/gayatri-theme.css:811), [arsip dokumen](C:/xampp/htdocs/Gayatri/resources/views/admin/rentals/documents/index.blade.php:1), [topbar](C:/xampp/htdocs/Gayatri/resources/views/partials/topbar.blade.php:6), [inbox vertikal](C:/xampp/htdocs/Gayatri/resources/views/admin/inbox.blade.php:28), dan [pemilihan layanan Home Service](C:/xampp/htdocs/Gayatri/app/Http/Controllers/Admin/HomeServiceController.php:22).

## Rancangan perbaikan katalog layanan

### Layanan Pijat & Spa serta Laktasi & Konsultasi ASI

Kedua menu masih memakai judul umum **Layanan** dan tabel delapan kolom. Variasi menjadi baris tersendiri; tambahan layanan yang alfabetis berada di awal daftar. Nama bundling panjang membungkus banyak baris. Pada data audit, 15 baris membuat konten Pijat & Spa sekitar 2.513 px dan Laktasi sekitar 3.099 px di desktop; di ponsel sekitar 8.118 dan 9.692 px.

Rancangan yang disarankan:

1. **Satu baris utama per keluarga layanan**, dengan jumlah variasi, ringkasan harga, dan kesiapan. Buka variasi melalui ekspansi baris atau panel detail. ID variasi dan referensi transaksi tetap dipertahankan.
2. Pisahkan **treatment utama, paket beberapa kunjungan, kelas, dan tambahan** melalui filter. Jangan menjadikan nama alfabetis tambahan sebagai halaman pertama default untuk seluruh katalog.
3. Beri filter kelompok usia/kategori, kesiapan, publikasi, dan rentang harga; pencarian mencakup nama keluarga, variasi, dan kode katalog. Tampilkan jumlah hasil serta tombol hapus filter.
4. Tabel utama cukup memuat **layanan, variasi, harga, kesiapan, publikasi, dan aksi**. Cabang, rincian tambahan, dan kode sumber ditempatkan pada detail atau kolom opsional.
5. Pisahkan status **Tayang/Diarsipkan** dari **Siap pilih jadwal/Konfirmasi admin/Perlu dilengkapi**. Saat ini badge Aktif belum menjelaskan apakah harga dan durasi sudah siap.
6. Harga rata kanan dan tidak terpecah antara `Rp` dan nominal. Foto kecil atau ikon pengganti konsisten membantu pengenalan; nama tetap menjadi identitas utama.
7. Aksi utama **Kelola variasi/Edit**. Aksi arsip ditempatkan dalam menu lain dengan konfirmasi yang menyebut layanan dan akibatnya.

Contoh ringkasan satu keluarga:

| Layanan | Variasi | Harga katalog | Kesiapan | Aksi |
|---|---:|---|---|---|
| Pijat Laktasi Premium | 3 | Rp98.000–Rp475.000 | 1 sesi dapat pilih jadwal; paket 3/5 sesi konfirmasi admin | Kelola variasi |

Paket beberapa kunjungan tetap mengikuti ketentuan konfirmasi admin. Pengelompokan tampilan tidak boleh mengubah paket menjadi satu booking atau menggabungkan histori antarvariasi.

### Form edit dan tambahan layanan

Form Baby SPA yang diperiksa mempunyai **48 kontrol dan 1.599 elemen option**, termasuk pilihan tambahan yang berulang. Enam tombol hapus tambahan tidak mempunyai nama aksesibel. Pada layar desktop, nama pilihan tambahan terpotong dan dropdown status sangat sempit.

- Pisahkan **Informasi utama, Variasi/Harga, Tambahan, dan Publikasi**. Tampilkan ringkasan kesiapan di dekat judul.
- Gunakan pilihan tambahan yang dapat dicari; tampilkan nama singkat pembeda, harga, dan durasi. Saring kelompok/jenis sebelum menampilkan opsi.
- Setiap tambahan menjadi satu baris yang lapang atau satu kartu: nama, biaya, durasi, status konfirmasi, dan hapus bernama jelas.
- Tampilkan ringkasan **tambahan siap ditawarkan** dan **tambahan menunggu konfirmasi**. Saat ini kolom daftar memakai `activeAddOns`, yang mengecualikan tambahan menunggu konfirmasi; tanda `-` dapat disalahpahami sebagai tidak mempunyai tambahan sama sekali.
- Jelaskan asal harga/durasi saat memilih tambahan dan apakah admin sedang memakai nilai katalog atau penyesuaian manual.
- Tambahkan indikator perubahan yang belum disimpan, validasi dekat kolom, dan ringkasan dampak sebelum menyimpan perubahan massal.

### Katalog rental

Kartu dengan foto 210 px membantu mengenali alat, tetapi memakan ruang untuk pekerjaan membandingkan tarif, deposit, dan unit. Navigasi lokal mengambil tiga baris pada desktop. Kartu hanya menunjukkan tarif mulai, sedangkan admin juga perlu membandingkan periode lain.

- Sediakan pilihan **Daftar/Tabel** dan **Kartu**, dengan daftar ringkas untuk operasional.
- Kolom utama: alat, tarif periode yang dipilih, unit siap/total, kesiapan data, publikasi, dan aksi. Rincian semua periode dan kelengkapan dapat dibuka pada detail.
- Filter kategori, status publikasi, unit siap, dan data belum lengkap. Ringkas jumlah produk yang belum mempunyai unit/deposit/jeda perawatan.
- Gunakan aksi kontekstual **Lengkapi unit**, **Tetapkan deposit**, atau **Edit tarif**; pertahankan penghitungan dan pembatasan operasional yang sudah ada.
- Tambahkan pencarian nama/SKU dan kontrol jumlah baris. Foto cukup berupa thumbnail pada tampilan daftar.

### Produk pendukung

Tabel sudah lebih sederhana, tetapi sembilan produk menghasilkan konten sekitar 1.396 px di desktop. Pada ponsel, foto, nama, SKU, dan kategori masih menjadi isi sel flex yang saling menyempit.

- Susun thumbnail, nama, dan SKU dalam satu blok identitas; kartu ponsel memakai urutan vertikal.
- Rapikan harga aktif/harga lama, rata kanan pada desktop. Pertahankan harga lama dicoret dan harga aktif sebagai fokus.
- Tambahkan filter kategori dan status publikasi. Keterangan pemesanan/stok melalui admin cukup jelas pada level halaman dan detail, tanpa diulang panjang pada tiap baris.
- Menu ini tetap menjadi katalog produk fisik dengan konfirmasi admin; perubahan visual tidak menambahkan checkout otomatis.

### Katalog tarif laundry

Navigasi lokal sepuluh tombol membentuk banyak baris di ponsel. Lima belas baris tarif pada filter semua status menghasilkan konten sekitar 2.580 px di desktop dan 8.928 px di ponsel. Penanda aktif dan belum lengkap belum cukup mudah dibedakan.

- Bedakan **laundry pakaian** dan **laundry perlengkapan** pada kategori/label. Istilah pakaian bayi atau potong kurang tepat untuk semua stroller, car seat, dan perlengkapan.
- Tampilkan nama, satuan, harga, kesiapan operasional, publikasi, dan aksi. Minimum/pembulatan/estimasi dapat dibuka pada detail.
- Tarif belum lengkap memakai badge **Perlu minimum/pembulatan/estimasi** dan tombol **Lengkapi tarif**.
- Gunakan satuan tampilan yang sesuai, seperti kg, potong pakaian, atau unit alat, sambil mempertahankan perhitungan internal yang benar.
- Filter aktif sebagai tampilan kerja utama; arsip dan data demo tetap dapat ditinjau terpisah.

## Fondasi tampilan dan navigasi bersama

### Struktur navigasi

Gunakan sekitar delapan kelompok utama yang dapat dibuka/tutup: **Ringkasan, Pelanggan & Komunikasi, Treatment & Konsultasi, Rental, Laundry, AI & WhatsApp, Laporan, Pengaturan**. Pijat & Spa dan Laktasi tetap mempunyai subkatalog serta alur sendiri. Modul rental/laundry tetap terpisah.

Sidebar membuka kelompok aktif dan mempertahankan konteks pada halaman tambah/edit/detail. Saat audit, halaman edit layanan, tambah booking, dan tambah knowledge tidak menandai menu induknya aktif. Pada ponsel, perbaiki Escape, perpindahan fokus, pengembalian fokus ke tombol menu, serta `aria-expanded`. Navigasi lokal cukup menampilkan kelompok tugas yang paling relevan; rental sudah mempunyai scroll tab mendatar di ponsel yang dapat dijadikan pola awal.

### Komponen bersama

- Judul operasional desktop sekitar 28–32 px sebagai usulan awal; judul sekarang mencapai 48 px. Pertahankan ciri merek dengan hierarki yang lebih ringkas.
- Tetapkan pola jarak 8/12/16/24 px dan tinggi kontrol yang konsisten. Kurangi pengulangan garis emas, bayangan, dan kartu bertingkat pada area data.
- Teks isi 14–16 px; metadata tetap terbaca. Gunakan warna emas sebagai aksen, dengan teks data berkontras tinggi.
- Satu gaya filter dengan label tetap terlihat, pencarian, filter utama, filter lanjutan, jumlah hasil, dan reset.
- Satu pola tabel dengan lebar kolom yang disengaja, judul kolom tetap untuk daftar panjang, angka rata kanan, sort yang jelas, dan pagination yang konsisten.
- Kartu ponsel dirancang secara eksplisit. Tabel dua dimensi seperti kalender dapat tetap digeser di dalam pembungkusnya; halaman luar tetap pas pada viewport.
- Status ditampilkan dengan teks yang konsisten, bukan hanya warna. Gunakan bahasa Indonesia untuk label pengguna sambil mempertahankan kode status internal.
- Pisahkan kosong karena belum ada data, kosong karena filter, modul belum diaktifkan, memuat, berhasil, dan gagal. Kondisi kosong biasa memakai tampilan netral dengan tindakan lanjut yang relevan.

### Kesiapan dan status operasional

Badge topbar **AI Aktif** bersamaan dengan gateway tidak tersambung dapat membingungkan. Tampilkan status fitur **AI diaktifkan** terpisah dari **WhatsApp tersambung/tidak tersambung** dan kondisi worker. Gateway lokal memang tidak dijalankan pada sesi pengujian ini; status tersebut bukan hasil uji gangguan VPS.

Ringkas error teknis menjadi bahasa tindakan, misalnya “Baileys belum dapat dihubungi. Periksa layanan gateway.” Kode cURL, URL internal, dan rincian debugging dapat berada dalam bagian **Detail teknis**. Pertahankan pemeriksaan dan pembatasan kirim ulang yang aman.

## Arah perbaikan modul lainnya

- **CRM dan pelanggan:** daftar lebih ringkas, impor CSV menjadi tindakan tersendiri, filter tersimpan, dan profil berisi ringkasan lintas layanan. Pertahankan batas hak akses dan privasi data.
- **Booking dan jadwal:** data/jadwal menjadi area utama; tambah slot dan bulk generate dibuka saat dibutuhkan. Tampilkan tahapan konfirmasi, pembayaran, treatment, dan selesai secara jelas. Label aksi pembayaran harus mudah dibedakan dari aksi edit jadwal.
- **Rental dan laundry operasional:** satu pola daftar pesanan dengan kode, pelanggan, tenggat, status, nilai, dan tindakan berikutnya. Pengembalian, QC, antar-jemput, pembayaran, dan refund mempunyai konteks yang berkesinambungan.
- **Dashboard/laporan:** dashboard menekankan pekerjaan hari ini dan pengecualian; laporan menekankan periode, perbandingan, rincian, dan ekspor. Bedakan tagihan, uang diterima, refund, deposit, dan piutang. Jangan mencampurkan deposit rental dengan pendapatan jasa.
- **Fee terapis:** ringkasan kebijakan aktif, persentase default/khusus, serta contoh hitung yang mudah dibuka. Pertahankan ketentuan nilai treatment setelah diskon, tanpa ongkos perjalanan, dan histori fee yang telah tercatat.
- **AI:** ringkas banner kebijakan berulang; prioritaskan percakapan, hasil, alasan, sumber jawaban, dan penyerahan ke admin. Bidang teknis ID/kode/chunks dapat ditempatkan pada detail lanjutan. Modul transaksi AI lama yang dikunci perlu diberi konteks status yang tegas, tanpa membuka kembali jalur transaksi melalui chat.
- **CMS dan pengaturan:** label konten menggunakan bahasa yang dipahami operator. Kelompokkan pengaturan per tujuan; lindungi perubahan yang belum disimpan dan tampilkan hasil pratinjau. API key tetap disamarkan.
- **User, cabang, terapis:** susunan tabel/aksi/status sama, menu aktif konsisten pada detail, dan tindakan nonaktif dipisahkan dari edit biasa.

## Urutan pengerjaan yang disarankan

| Paket | Lingkup | Selesai bila |
|---|---|---|
| A — Stabilitas dan komponen dasar | Error Transaksi Sewa; sel/kartu ponsel; arsip invoice; struktur sidebar, topbar, label, dan status dasar; rekonsiliasi promo demo. | Seluruh tujuan menu membuka halaman normal, data terbaca, dan kontrol inti berperilaku sesuai label. |
| B — Katalog | Keluarga/variasi treatment, rental, produk pendukung, tarif laundry, formulir tambahan, kesiapan katalog, pencarian/filter. | Admin dapat mencari, membandingkan, dan melengkapi satu pilihan tanpa menelusuri semua variasi atau ratusan checkbox. |
| C — Operasional | Inbox, booking/jadwal, Home Service, penerimaan/QC, unit/perawatan, antar-jemput, pembayaran/pengembalian. | Tindakan berikutnya mudah dikenali dan perpindahan antarbagian tidak kehilangan konteks transaksi. |
| D — Laporan, AI, dan pengaturan | Dashboard utama/modul, laporan, fee, knowledge, simulator/log, WhatsApp monitoring, CMS, pengguna. | Ringkasan berbeda jelas dari rincian; pengaturan teknis muncul sesuai kebutuhan operator. |
| E — Validasi lintas ukuran dan peran | Desktop/tablet/ponsel, keyboard, kontras, data panjang/kosong/arsip, filter, error, serta peran owner/admin/sales/terapis. | Tidak ada isi terpotong, alur penting dapat diselesaikan, dan hak akses serta angka transaksi tetap benar. |

Mulai pengerjaan A dan B sebagai prioritas awal: menyelesaikan hambatan yang ditemukan sekaligus menjawab kebutuhan utama penataan katalog.

## Kriteria penerimaan untuk pengerjaan berikutnya

1. Halaman utama semua menu dan detail transaksi lama dapat dibuka tanpa 500. Uji memakai contoh snapshot lama yang sebelumnya memicu error.
2. Identitas layanan, variasi, harga, badge, dan aksi tidak terpotong pada 320, 390, 768, 1024, dan 1366 px. Scroll mendatar hanya muncul pada area data yang memerlukannya.
3. Menu aktif benar pada daftar/tambah/edit/detail. Keyboard dapat membuka/menutup menu dan fokus tetap jelas.
4. Seluruh kontrol memiliki label/nama aksesibel. Tombol hapus tambahan menyebut tindakan yang benar. Target tombol utama ponsel sekitar 44 px sebagai sasaran ergonomi.
5. Pencarian/filter dapat dipakai, dihapus, dan dipertahankan saat berpindah halaman. Pengelompokan katalog tidak mengubah ID variasi, harga historis, atau aturan konfirmasi.
6. Badge publikasi, kesiapan pemesanan, pembayaran, dan koneksi mempunyai arti yang berbeda dan konsisten.
7. Pesanan baru, pembayaran, refund, kirim ulang, dan perubahan pengaturan diuji terpisah dengan data/gateway simulasi sebelum rilis. Audit ini tidak menjalankan tindakan tersebut.

Acuan aksesibilitas untuk validasi: [W3C Reflow](https://www.w3.org/WAI/WCAG22/Understanding/reflow.html) menjelaskan penyesuaian konten pada lebar setara 320 CSS px dan pengecualian bagian yang perlu dua dimensi; [W3C Contrast Minimum](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum) menjelaskan rasio 4,5:1 untuk teks biasa dan 3:1 untuk teks besar; [W3C Target Size Minimum](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html) menjelaskan ukuran/penjarakan target interaksi. Angka 44 px di atas adalah sasaran ergonomi yang diusulkan, bukan klaim syarat minimum WCAG untuk semua tombol.

## Contoh bukti visual

Sel identitas layanan di ponsel; isi menyamping dan sebagian kode terpotong:

![Sel layanan ponsel](C:/xampp/htdocs/Gayatri/storage/logs/ui-ux-admin-20261102/service-mobile-row.png)

Form tambahan layanan; nama pilihan dan dropdown status terlalu sempit:

![Tambahan layanan desktop](C:/xampp/htdocs/Gayatri/storage/logs/ui-ux-admin-20261102/service-addons-desktop.png)

## Catatan pemeriksaan setiap menu

Tabel berikut melengkapi temuan bersama. Semua menu telah ditinjau pada dua ukuran utama; tinggi konten merupakan hasil ukur sesi ini, bukan tolok ukur performa atau batas desain yang wajib.

<!-- MENU_COVERAGE -->

| Menu | Prioritas | Temuan | Perbaikan yang disarankan |
|---|---|---|---|
| 1. Dashboard | P2 | Banyak kartu ringkasan; di ponsel konten sekitar 4.314 px. | Prioritaskan tugas hari ini, ringkasan per modul, dan pengecualian; kelompokkan metrik total/periode. |
| 2. Customers | P1 | Impor CSV selalu terbuka sebelum daftar; 15 pelanggan membuat konten desktop sekitar 3.433 px. | Impor sebagai dialog/halaman sendiri, daftar ringkas, filter berlabel, dan identitas sel ponsel yang utuh. |
| 3. WhatsApp Inbox | P1 | Daftar dan chat tersusun vertikal pada desktop; area tulis jauh di bawah layar. | Dua panel desktop dan profil opsional; ponsel berpindah daftar/chat dengan tombol kembali. |
| 4. Bookings | P1 | Tabel delapan kolom dengan banyak aksi; menu induk tidak aktif pada halaman tambah. | Ringkas identitas/status, bedakan pembayaran dari edit, dan pertahankan konteks daftar pada formulir. |
| 5. Jadwal Tersedia | P2 | Tambah Slot dan Bulk Generate mendahului daftar jadwal. | Jadwal sebagai area utama; buka formulir pembuatan saat diperlukan dengan ringkasan bentrok/kapasitas. |
| 6. Home Service | P1 | 276 kontrol dan daftar semua layanan aktif dalam checkbox panjang. | Pisah bagian pengaturan; pencarian/pengelompokan pilihan dan ringkasan yang dipilih. |
| 7. Reminders | P2 | Log, ringkasan, filter, dan pembuatan manual bercampur dalam satu halaman. | Antrean/log sebagai fokus, pembuatan manual terpisah, dan label status serta tindakan kegagalan yang jelas. |
| 8. Ucapan Ulang Tahun | P2 | Pengaturan, template, calon penerima, dan riwayat berada dalam satu guliran. | Bagian Hari Ini, Mendatang, Riwayat, Pengaturan; status scheduler/gateway konsisten. |
| 9. Follow-Up | P2 | Label Title/Due/Priority/Assigned bercampur bahasa; sel berisi beberapa jenis teks. | Pakai judul, tenggat relatif, prioritas, penanggung jawab, dan tindakan utama yang ringkas. |
| 10. Feedback | P2 | Keadaan kosong berupa pesan singkat dalam tabel. | Jelaskan kondisi kosong/filter dan akses ke riwayat atau pengaturan permintaan feedback. |
| 11. Customer Retention | P2 | Kartu segmen 30/60/90 dan filter memanjang; label aktif tidak menunjukkan jumlah pelanggan. | Ringkas segmen dengan jumlah sasaran, tampilkan dasar periode, dan lanjutkan ke daftar relevan. |
| 12. Promo & Voucher | P1 | Promo demo 10% tampil Rp10; tipe data lama percentage berbeda dari percent. | Rekonsiliasi tipe dan presentasi/perhitungan; seragamkan label berlaku, berakhir, dan kuota. |
| 13. Campaigns | P2 | Status scheduled/Schedule/Recipients/Hasil masih campuran bahasa; jadwal demo lampau tetap ditampilkan. | Tampilkan status pengiriman dan kesiapan gateway/worker, jadwal lewat waktu, serta konteks penerima. |
| 14. Branches | P2 | Tabel sederhana, tetapi status Active/Inactive dan aksi perlu konsistensi global. | Gunakan label/status bersama; pisahkan arsip dan sediakan detail keterkaitan data. |
| 15. Layanan Pijat & Spa | P1 | Variasi/tambahan bercampur, delapan kolom, identitas sel terpotong di ponsel. | Kelompok keluarga/variasi, filter jenis dan kesiapan, tabel ringkas serta kartu ponsel khusus. |
| 16. Laktasi & Konsultasi ASI | P1 | Judul tetap Layanan; bundling panjang mendominasi baris dan membungkus berulang. | Judul sesuai kelompok, baris induk per keluarga, rincian paket dan variasi di panel/ekspansi. |
| 17. Produk Pendukung | P1 | Foto, nama, dan SKU tersebar dalam sel flex ponsel; baris desktop relatif tinggi. | Blok identitas vertikal, thumbnail konsisten, harga terarah, filter kategori/publikasi. |
| 18. Landing Page CMS | P2 | Editor menampilkan sejumlah label teknis dan banyak kelompok pengaturan. | Form konten terstruktur dengan bahasa operator, pratinjau, indikator belum disimpan, dan navigasi bagian. |
| 19. Therapists | P2 | Data login, cabang, spesialisasi, status, dan aksi berada pada satu tabel. | Ringkas profil utama; detail akses/cabang/spesialisasi dapat dibuka; aksi dan label status seragam. |
| 20. Fee Terapis | P2 | Kebijakan, filter laporan, rekap, dan rincian tersebar pada halaman panjang. | Ringkasan kebijakan aktif dan contoh hitung; rincian tabel dapat dibuka tanpa mengulang semua ringkasan. |
| 21. Penerimaan Laundry | P1 | Sepuluh navigasi lokal menghabiskan ruang ponsel; penerimaan belum aktif pada data audit. | Ringkas subnavigasi; arahkan ke langkah melengkapi outlet/tarif dan pisahkan tugas penerimaan baru. |
| 22. Dashboard Laundry | P2 | Banyak metrik dan bagian laporan; konten ponsel sekitar 4.757 px. | Prioritaskan pesanan dan tindakan operasional hari ini; metrik lanjutan dibuka sesuai kebutuhan. |
| 23. Laporan Laundry | P2 | Bagian atas banyak mengulang dashboard sebelum rincian. | Laporan fokus periode, perbandingan, rincian, dan ekspor dengan definisi angka yang mudah dibuka. |
| 24. Permintaan Laundry | P2 | Filter dan keadaan tanpa permintaan mendominasi halaman saat ini. | Tab tahapan dengan jumlah, pencarian berlabel, dan tindakan verifikasi/konversi yang berurutan. |
| 25. Bukti Pembayaran Laundry | P2 | Keadaan tidak ada bukti tampil seperti pemberitahuan berwarna peringatan. | Keadaan kosong netral; pisahkan Menunggu dan Riwayat, berikan konteks transaksi saat meninjau. |
| 26. Proses & QC Laundry | P2 | Ringkasan dan filter mendahului daftar; tahapan proses penting untuk operasi. | Filter tahap dengan jumlah dan progres pesanan; tombol tindakan sesuai tahap, QC, dan serah terima. |
| 27. Antar-Jemput Laundry | P2 | Navigasi dan delapan kontrol filter memakan ruang sebelum jadwal. | Ringkasan per hari/petugas, filter ringkas, penugasan dan status antar-jemput yang jelas. |
| 28. WhatsApp Laundry | P2 | Pengaturan template dan riwayat berisi banyak teks pesan penuh. | Pilih jenis notifikasi, pratinjau terarah, dan riwayat ringkas dengan detail pesan bila dibuka. |
| 29. Katalog Tarif Laundry | P1 | Navigasi berbaris panjang; kesiapan berbeda dari aktif; satuan/kategori perlu membedakan alat dan pakaian. | Ringkas daftar; filter data belum lengkap; kategori/satuan tepat dan tombol Lengkapi tarif. |
| 30. Pengaturan Outlet | P2 | Form pengaturan dan petunjuk mulai layanan disandingkan; navigasi mobile besar. | Checklist kesiapan outlet, label jelas, dan simpan per bagian dengan validasi konsisten. |
| 31. Katalog Rental | P1 | Kartu foto besar; hanya tarif mulai terlihat dan semua navigasi lokal diulang. | Pilihan daftar/kartu, perbandingan periode, unit siap, kesiapan data, dan aksi melengkapi stok/deposit. |
| 32. Dashboard Rental | P2 | Ringkasan, grafik, saldo, tindakan, tabel produk dan pemakaian membuat halaman panjang. | Fokus tindakan/pengecualian pada dashboard; informasi keuangan berlapis dengan definisi tetap benar. |
| 33. Laporan Rental | P2 | Struktur hampir sama dengan Dashboard Rental, kemudian menambahkan tabel rincian. | Laporan dibedakan melalui perbandingan periode, filter, rincian, dan ekspor. |
| 34. Stok Unit | P2 | Form tambah unit mendahului daftar; beberapa kartu unit berisi kontrol edit langsung. | Daftar unit berfilter produk/kesiapan, tindakan tambah/edit terpisah, dan pemisahan data demo yang jelas. |
| 35. Perawatan Unit | P2 | Kartu riwayat per unit memerlukan banyak guliran saat jumlah unit bertambah. | Antrean berdasarkan tenggat/kondisi dan unit, lalu buka kronologi perawatan di detail. |
| 36. Jadwal Antar-Jemput | P1 | Pemilihan transaksi diarahkan ke Transaksi Sewa yang saat audit 500. | Pulihkan ketergantungan daftar transaksi, lalu susun jadwal/petugas dan pilihan transaksi yang dapat dicari. |
| 37. Invoice & Kuitansi | P1 | Kerangka hijau dokumen tanpa sidebar; halaman melebar pada ponsel. | Arsip memakai kerangka admin; pratinjau/cetak boleh terpisah, dengan scroll tabel terkendali. |
| 38. Verifikasi Pembayaran | P2 | Bukti pembayaran dan pengajuan perpanjangan bercampur dalam satu judul menu. | Pisah tab dengan jumlah serta konteks tagihan/perpanjangan dan status peninjauan. |
| 39. Pembatalan & Refund | P2 | Daftar pengajuan kosong pada status awal yang dipilih. | Status permintaan, keputusan, dan dana yang benar-benar dibayarkan perlu dibedakan jelas. |
| 40. Kalender Sewa | P2 | Kalender 30 hari memang memerlukan scroll mendatar; kolom unit sudah menempel. | Pertahankan pembungkus dan kolom unit; tambahkan fokus hari ini/rentang pendek serta penanda selain warna. |
| 41. Transaksi Sewa | P0 | 500 pada desktop/ponsel; view mengasumsikan kunci total snapshot selalu ada. | Dukung snapshot historis, tampilkan nilai dari sumber yang benar, dan uji daftar memakai data lama. |
| 42. Peringatan Rental | P2 | Keadaan tanpa peringatan sudah dijelaskan, tetapi tidak memberi ringkasan kelengkapan katalog. | Pisahkan alarm transaksi dari kebutuhan melengkapi stok/deposit/jeda perawatan, dengan tautan tindakan. |
| 43. WhatsApp Rental | P2 | 45 kontrol dan pengaturan/template panjang; sekitar 5.120 px di ponsel. | Pisah jadwal, template per jenis, pratinjau, dan riwayat; status gateway/worker tetap jelas. |
| 44. Pengaturan Rental | P2 | 30 kontrol dengan beberapa kebijakan bisnis dalam satu guliran. | Bagian Umum, Pembayaran, Ketentuan/Denda, Antar-Jemput, dengan ringkasan dan validasi per bagian. |
| 45. Balasan Otomatis | P2 | Banner mode panjang dan pengaturan gaya/jam/template/simulator memenuhi halaman. | Ringkasan mode singkat, tab pengaturan, dan pratinjau percakapan yang selalu mudah dijangkau. |
| 46. Persona | P2 | Prompt terpotong pada daftar; tujuan persona dan hubungan dengan Balasan Otomatis kurang menonjol. | Jelaskan persona aktif, ringkas gaya bahasa, dan buka prompt/versi pada detail. |
| 47. Knowledge Base | P1 | Filter dan banner mendahului daftar; menu induk tidak aktif pada form tambah; kolom chunks bersifat teknis. | Prioritaskan cakupan layanan, publikasi/peninjauan dan sumber; detail teknis dan filter lanjutan dipisah. |
| 48. Scope & Guardrail | P2 | Ringkasan aturan statis berbahasa campuran; Booking dan jadwal dapat disalahpahami sebagai aksi transaksi AI. | Jelaskan aturan sistem tetap dan panduan yang dapat diatur; gunakan bahasa informasi/pengarahan web. |
| 49. Data Automation | P2 | Fitur lama yang dikunci masih hadir sebagai pusat rule/approval bersama mode baru. | Tandai status operasional dan letakkan arsip/fitur lanjutan sesuai konteks; pertahankan pengamanan transaksi. |
| 50. Simulator | P2 | Banner dan opsi uji mendahului percakapan; hasil berada pada bagian lain. | Percakapan sebagai area utama; identitas uji dan detail keputusan/sumber dapat dibuka di panel. |
| 51. AI Logs | P2 | 20 baris dan enam filter, termasuk ID/kode teknis; konten mobile sekitar 8.473 px. | Utamakan perlu admin/gagal/berhasil; ringkas daftar, buka alasan/sumber pada detail, filter teknis lanjutan. |
| 52. Reports | P2 | Portal laporan menampilkan banyak kartu dan definisi angka sebelum rincian. | Navigasi laporan lintas modul, periode konsisten, pemisahan ringkasan/rincian, dan definisi angka ringkas. |
| 53. WhatsApp Gateway | P1 | Error cURL/URL teknis tampil langsung; AI Aktif pada topbar bersamaan dengan koneksi berhenti. | Bahasa status yang jelas, detail teknis tertutup, dan pemisahan fitur AI dari koneksi gateway. |
| 54. Monitoring WhatsApp | P2 | Informasi gateway, worker, riwayat, dan antrean bertumpuk; kondisi lokal offline sudah dijelaskan. | Tindakan yang diperlukan sebagai fokus; ringkas status utama dan buka rincian per proses tanpa melemahkan retry aman. |
| 55. Settings | P2 | Provider AI dan gateway ditampilkan bersama; kolom secret sudah disamarkan. | Pertahankan perlindungan secret, kelompokkan tujuan, tampilkan kolom sesuai provider, dan validasi dekat kolom. |
| 56. Users & Roles | P2 | Tabel akun panjang pada mobile; aksi edit/nonaktif perlu tingkat penekanan yang berbeda. | Ringkas nama/role/status, detail akses saat dibuka, dan konfirmasi tindakan akun sesuai dampaknya. |
