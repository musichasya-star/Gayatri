# Rencana Perombakan Frontend Gayatri

Per 4 November 2026, **Paket A hingga E selesai di lokal**. Paket F adalah validasi akhir sebelum rilis terpisah. Hasil Paket A hingga C tercatat pada dokumen masing-masing. Bukti Paket D tersedia di `storage/logs/landing-package-d/`, dan hasil Paket E di [catatan CMS, SEO, dan performa](HASIL_LANDING_PAKET_E_2026-11-04.md).

Frontend Gayatri akan memakai arah visual ivory, cokelat, dan emas seperti referensi pengguna, dengan ilustrasi yang hangat, hierarki judul yang jelas, serta kartu layanan bergambar. Susunan dan alur pelanggan mencakup **Pijat dan Spa, Laktasi dan Konsultasi ASI, Rental Perlengkapan Bayi, serta Laundry Ibu dan Bayi**. Masing-masing tetap mempunyai pilihan, aturan pemesanan, dan status operasional sendiri.

Rencana ini memperluas hasil audit landing page lokal menjadi perombakan seluruh pengalaman frontend pelanggan. Pengerjaan dimulai di lokal. Kesiapan pemesanan, harga, stok, pembayaran manual, persetujuan admin, dan privasi portal mengikuti sumber data serta pengamanan aplikasi yang sudah ada.

## Referensi Visual dan Adaptasi

Referensi pengguna disalin ke workspace agar tetap tersedia untuk pengerjaan berikutnya:

![Referensi visual Gayatri](C:/xampp/htdocs/Gayatri/docs/references/landing-page-reference-2026-11-03.png)

| Unsur referensi | Penerapan pada Gayatri |
|---|---|
| Latar ivory dengan aksen cokelat dan emas | Menjadi palet bersama seluruh halaman publik; emas digunakan terutama untuk aksen dan dekorasi. |
| Judul serif dengan isi yang sederhana | Judul menggunakan Playfair Display dan isi menggunakan Inter yang sudah tersedia. Ukuran serta jaraknya disesuaikan untuk ponsel. |
| Hero ilustratif dengan lengkung cahaya dan unsur alam | Hero menggunakan komposisi hangat, ruang kosong untuk teks, gambar terpisah, dan dua tindakan yang jelas. Dekorasi tidak menutupi isi atau tombol. |
| Kartu layanan bergambar | Kartu menampilkan nama keluarga layanan, ringkasan manfaat, harga sesuai pilihan, serta tindakan yang sesuai kesiapan produk. |
| Bagian tentang tempat | Menggunakan foto outlet dan informasi yang telah diverifikasi. Ilustrasi tempat tidak disajikan sebagai foto outlet sebenarnya. |
| Kartu paket dengan satu pilihan ditonjolkan | Hanya untuk paket yang benar-benar tersedia. Penanda rekomendasi ditentukan admin; isi, sesi, dan harga mengikuti katalog. |
| Reservasi tiga langkah | Disesuaikan dengan slot tersedia dan konfirmasi admin pada setiap modul. |
| Galeri, FAQ, dan footer ringkas | Galeri mempunyai konteks, FAQ dikelompokkan, dan footer menjadi bagian terakhir halaman. |
| Form singkat pada banner bawah | Menjadi pintu menuju pemesanan yang lengkap atau formulir yang memakai aturan backend yang sama. Nama, layanan, dan tanggal saja belum cukup untuk memastikan booking. |

Nama merek utama tetap **Gayatri**. Tulisan “Kids Massage & Spa” pada contoh tidak otomatis menggantikan identitas usaha karena aplikasi juga melayani ibu, laktasi, rental, dan laundry. Logo utama memakai aset merek yang layak; ornamen pada contoh menjadi acuan gaya.

Screenshot referensi digunakan untuk komposisi dan suasana. Teks, menu, tombol, kartu, dan formulir dibuat sebagai elemen web yang dapat diakses dan diubah melalui CMS. Ilustrasi hero, dekorasi, dan foto disiapkan sebagai aset terpisah.

## Masalah Audit yang Harus Diselesaikan

Audit lokal sebelumnya tersimpan pada `storage/logs/landing-audit-20261103/measurements.json` beserta screenshot pada direktori yang sama.

| Temuan | Perubahan yang harus dihasilkan |
|---|---|
| Header ponsel sekitar 198 piksel dan menu utama tersembunyi tanpa pengganti | Header ponsel ringkas dengan tombol menu yang berfungsi, fokus keyboard, dan satu tindakan utama. |
| Hero desktop sekitar 1.057 piksel; tombol di dalam hero mulai sekitar y=839 pada viewport 1366 × 768 | Tinggi gambar dan judul dikendalikan agar pesan utama serta tombol dapat ditemukan pada layar pertama ukuran tersebut. |
| Rental muncul setelah hero, sementara Laundry berada setelah footer | Empat pintu layanan ditampilkan sejak awal; seluruh konten berada sebelum footer. |
| Halaman ponsel 390 piksel mencapai sekitar 15.500 piksel | Bagian berulang digabung, ringkasan dipadatkan, dan rincian dipindahkan ke halaman layanan. Panjang halaman bukan ukuran keberhasilan tunggal. |
| Tiga contoh Spa diambil dari urutan alfabet dan berupa tambahan layanan | Admin memilih layanan unggulan utama. Tambahan tetap melekat pada treatment terkait. |
| Tiga contoh Laktasi berupa nama bundling yang panjang | Tampilkan keluarga layanan dengan pilihan reguler/private, durasi, dan paket pada detail. |
| CTA Laundry menjanjikan pemesanan, tetapi pemesanan online sedang ditutup | Label dan tujuan CTA mengikuti status layanan; tersedia bantuan kontak yang valid. |
| Form publik memuat cabang demo; kontak masih memuat data contoh | Pilihan publik disaring berdasarkan kesiapan dan kelayakan publikasi. Kontak, lokasi, dan jam diverifikasi. Data historis tetap dipertahankan. |
| Maps belum menunjuk lokasi tertentu, Instagram menuju `#`, WhatsApp menuju bagian booking | Semua tautan mempunyai tujuan yang sesuai label, dengan keadaan alternatif jika informasi belum tersedia. |
| Bahasa CRM, status teknis, dan session tampil kepada pelanggan | Gunakan bahasa pemesanan yang langsung dipahami pelanggan. |

## Cakupan Halaman Publik

| Area | Cakupan desain |
|---|---|
| Beranda `/` | Susunan halaman, navigasi, hero, empat layanan, unggulan, tentang, paket, alur, galeri, FAQ, kontak, dan footer. |
| Katalog `/layanan` | Pencarian, kategori, pemisahan jenis layanan/produk, keluarga dan variasi, harga, serta keadaan siap dipesan/perlu konfirmasi. |
| Pijat dan Spa `/booking?service_group=spa` | Tampilan pengenalan dan formulir yang mengutamakan pilihan treatment, variasi, tambahan, tempat layanan, serta jadwal. |
| Laktasi `/booking?service_group=lactation` | Identitas pendampingan menyusui dan pilihan yang terpisah, dengan komponen serta bahasa visual yang seragam. |
| Rental `/rental` | Katalog, detail alat, ketersediaan, keranjang, checkout, konfirmasi, dan portal transaksi terkait. |
| Laundry `/laundry` | Informasi outlet, keadaan layanan belum dibuka, pemesanan, estimasi/timbang, dan portal pelanggan terkait. |
| Komponen pelanggan | Header, footer, tombol, kartu, formulir, dialog, tab, pemberitahuan, keadaan kosong, dan navigasi kembali. |
| CMS pendukung | Isian konten dan aset, urutan bagian, pilihan unggulan, pratinjau, serta kelayakan konten publik. |

Layar portal mengikuti tema baru sambil mempertahankan verifikasi pemilik, aturan akses dokumen, dan kerahasiaan transaksi. Tampilan admin menjadi pengelola konten publik; desain dashboard admin tidak dirombak kembali dalam pekerjaan ini.

## Sistem Visual

Nilai berikut menjadi titik awal desain. Pasangan warna dan keterbacaan diperiksa pada komponen yang benar-benar dirender.

| Komponen | Usulan |
|---|---|
| Warna halaman | Ivory `#FFF9F2`, permukaan putih/ivory muda, cokelat gelap `#4A281B`, aksen bronze `#A36A2D`, emas dekoratif `#D6AF68`. |
| Teks | Utama `#3E2B21`, pendamping `#716358`. Teks kecil dan informasi penting memakai warna yang cukup gelap. |
| Judul utama | Desktop sekitar 48–64 piksel; ponsel sekitar 34–42 piksel; tinggi baris sekitar 1,08–1,15. Hindari pemotongan kata dan pengaturan tinggi tetap yang menyembunyikan teks. |
| Isi | Sekitar 16–18 piksel, tinggi baris 1,5–1,7; paragraf singkat dengan panjang baris terkendali. |
| Lebar isi | Maksimum sekitar 1.200–1.280 piksel dengan ruang sisi yang menyesuaikan layar. |
| Jarak | Skala bersama 8, 12, 16, 24, 32, 48, dan 64 piksel. Bagian desktop sekitar 64–80 piksel; ponsel sekitar 32–48 piksel, sesuai isi. |
| Kartu | Sudut sekitar 16–24 piksel, batas tipis, bayangan halus, area gambar seragam, tombol sejajar bila ruang memungkinkan. |
| Tombol | Satu gaya utama cokelat, satu gaya sekunder outline/ivory, serta tautan teks. Sasaran tinggi sentuh sekitar 44–48 piksel. |
| Header | Desktop sekitar 72–80 piksel; ponsel sekitar 64–72 piksel pada keadaan tertutup. |
| Hero | Komposisi dua kolom desktop; ponsel mendahulukan judul, ringkasan, dan CTA. Gambar memakai bingkai dengan rasio/aspect yang jelas agar tidak menentukan tinggi halaman dari ukuran foto aslinya. |
| Gerak | Transisi ringan dan singkat; mendukung preferensi reduced motion. Informasi tetap tersedia tanpa animasi. |

Lengkung, daun, kilau, dan ilustrasi dekoratif digunakan secukupnya pada hero atau bagian promosi. Kartu katalog dan formulir mempunyai latar yang tenang agar pilihan pelanggan mudah dibaca.

## Susunan Beranda Baru

| Urutan | Bagian | Isi dan tindakan |
|---|---|---|
| 1 | Header | Logo, Beranda, Layanan, Tentang, Galeri, FAQ, Kontak, dan CTA Pilih Layanan. Paket muncul jika tersedia. Ponsel memakai menu yang dapat dibuka dan ditutup. |
| 2 | Hero dan ringkasan kepercayaan | Satu pesan utama, ringkasan empat cakupan usaha, ilustrasi/foto, CTA Pilih Layanan, dan Lihat Katalog. Informasi seperti pendampingan orang tua hanya ditampilkan bila sesuai layanan dan telah dikonfirmasi. |
| 3 | Empat pintu layanan | Pijat dan Spa, Laktasi dan ASI, Sewa Perlengkapan, serta Laundry. Kartu ringkas, identitas jelas, dan CTA berbeda sesuai modul. |
| 4 | Pilihan unggulan | Empat sampai enam pilihan yang ditentukan admin. Nama keluarga singkat, informasi variasi, harga yang tepat, dan tindakan utama. |
| 5 | Tentang dan bukti pelayanan | Foto tempat/tim yang sebenarnya, pengenalan singkat, fasilitas yang benar, serta tautan lokasi. |
| 6 | Paket tersedia | Bagian opsional. Isi paket, jumlah kunjungan/sesi, harga, dan kebutuhan konfirmasi disampaikan jelas. |
| 7 | Cara pemesanan | Pilih layanan → lengkapi pilihan dan jadwal → terima konfirmasi. Rincian pembayaran manual dan tahap setiap modul tersedia pada halaman masing-masing. |
| 8 | Galeri dan pengalaman pelanggan | Foto asli diberi konteks; ilustrasi diberi keterangan. Testimoni tampil jika sudah diverifikasi dan tersedia untuk publikasi. |
| 9 | FAQ | Pertanyaan umum dikelompokkan berdasarkan layanan. Jawaban singkat disertai tautan rincian bila perlu. |
| 10 | Kontak dan ajakan pemesanan | Alamat, jam, tautan Maps yang tepat, bantuan WhatsApp, dan CTA ke layanan. Tidak mengulang formulir transaksi yang berbeda aturan. |
| 11 | Footer | Identitas bisnis, menu layanan, kontak, sosial, dan tautan kebijakan yang tersedia. Akses staf ditempatkan secara wajar. |

Contoh arah copy hero: **“Sentuhan hangat untuk Bunda dan si kecil.”** Pengantar menjelaskan pilihan pijat dan spa, pendampingan menyusui, sewa perlengkapan, serta laundry. Kalimat final disesuaikan dengan layanan yang dipublikasikan. Nama paket, harga, klaim fasilitas, dan testimoni pada gambar referensi tidak otomatis menjadi data katalog.

## Perjalanan Pelanggan

### Pemilihan Layanan

CTA umum membawa pelanggan ke pilihan empat layanan. Pelanggan tidak diarahkan diam-diam ke booking Spa ketika maksudnya rental atau laundry. Setiap kartu memperlihatkan ringkasan dan tindakan yang tepat:

| Layanan | Tindakan utama | Keadaan alternatif |
|---|---|---|
| Pijat dan Spa | Pilih treatment atau jadwal | Konfirmasi admin untuk paket/pilihan yang belum siap. |
| Laktasi dan ASI | Pilih layanan pendampingan | Konfirmasi kebutuhan atau paket melalui admin. |
| Rental | Pilih alat dan periode sewa | Konfirmasi alat jika unit, deposit, atau kelengkapan belum lengkap. |
| Laundry | Pesan Laundry jika pemesanan dibuka | Informasi layanan dan kontak outlet jika belum dibuka. |

WhatsApp berfungsi untuk pertanyaan, bantuan admin, dan pengecekan status melalui verifikasi yang berlaku. Pemesanan serta perubahan transaksi mengikuti formulir web dan aturan modul yang telah disepakati.

### Katalog dan Detail

Pencarian dan filter berada dekat daftar. Pengunjung dapat membedakan treatment, kelas, sewa, laundry, dan produk pendukung. Kartu memperlihatkan keluarga layanan; variasi menjadi pilihan eksplisit. Pilihan tambahan tidak bersaing sebagai treatment utama pada beranda.

Harga “mulai dari” hanya digunakan jika ada beberapa variasi dan angkanya berasal dari pilihan yang benar. Saat variasi berubah, harga, durasi, status pemesanan, dan tujuan CTA ikut berubah. Berpindah ke booking mempertahankan pilihan layanan yang sudah dipilih. Kembali ke daftar mempertahankan filter yang relevan.

### Booking Treatment

Urutan yang dirancang: kelompok layanan → keluarga/variasi → tambahan → klinik atau kunjungan rumah → cabang/jadwal tersedia → kontak → ringkasan → kirim permintaan. Bentuk akhir dapat memakai satu halaman dengan bagian progresif, agar tidak menciptakan langkah yang tidak diperlukan.

Form dapat dijangkau segera melalui tombol lompat. Penjelasan panjang dan kartu status kosong tidak mendahuluinya pada ponsel. Input kontak memakai label jelas; isian opsional diberi penanda. Jika belum ada slot, pelanggan mendapat penjelasan dan bantuan admin yang sesuai.

Home Service menampilkan alamat, pin lokasi, estimasi jarak/ongkir, dan jangkauan sesuai konfigurasi admin. Permintaan geolocation terjadi setelah pelanggan memilih tindakan lokasi. Ringkasan akhir menunjukkan layanan, jadwal, tempat, tambahan, ongkir jika ada, dan status menunggu konfirmasi.

### Rental dan Laundry

Rental menampilkan harga periode, deposit, status ketersediaan, kebutuhan konfirmasi, dan ketentuan sebelum checkout. Keranjang mempertahankan pilihan. Informasi ongkir serta pengembalian tetap berasal dari modul rental.

Laundry menjelaskan perbedaan estimasi, hasil timbang, rincian yang menunggu persetujuan, dan tagihan yang sudah disetujui. Status layanan tertutup mempunyai jalur bantuan yang dapat digunakan calon pelanggan tanpa harus memiliki pesanan terlebih dahulu.

Pembayaran tetap manual: instruksi setelah tahap yang tepat → unggah bukti melalui akses privat → pemeriksaan admin → pembaruan status. Pesan sukses unggah bukti tidak menyatakan pembayaran telah lunas sebelum diverifikasi. Draf, keadaan memuat, validasi gagal, tombol sedang memproses, dan konfirmasi berhasil menggunakan pola bersama.

## Aset Gambar dan Konten

| Kebutuhan | Rencana aset |
|---|---|
| Hero | Aset landscape dan penyesuaian crop/focal point untuk ponsel; komposisi menyisakan ruang bagi teks. Ilustrasi hangat dapat dibuat sesuai arah referensi saat tahap produksi aset. |
| Empat layanan | Empat visual yang serasi: treatment ibu/anak, pendampingan menyusui, perlengkapan rental, dan pakaian laundry. |
| Produk rental | Foto alat sebenarnya dengan proporsi dan latar seragam. Gambar dari katalog dievaluasi resolusinya sebelum diperbesar. |
| Tentang dan galeri | Foto outlet, fasilitas, dan tim yang benar. Ilustrasi tidak menggantikan bukti kondisi tempat. |
| Logo dan ikon | Logo yang tetap jelas pada ukuran kecil dan satu keluarga ikon. Dekorasi memakai aset ringan sesuai kebutuhan. |
| Paket dan promosi | Konten mengikuti layanan, masa berlaku, serta ketentuan yang benar. Paket belum siap mempunyai alur konfirmasi admin. |

Aset diproduksi terpisah dari tulisan, memakai format dan ukuran yang sesuai, dimensi gambar yang jelas, serta deskripsi alternatif yang relevan. Gambar utama diprioritaskan; gambar di bawah layar dimuat bertahap. Aset sumber, hasil optimasi, dan penggunaannya dicatat agar mudah diganti melalui CMS.

## Pengelolaan CMS dan Data Publik

CMS mendukung teks dan gambar hero, crop/focal point, CTA, urutan bagian, empat kartu layanan, pilihan unggulan berdasarkan ID katalog, paket, galeri, FAQ, serta kontak publik. Form tidak menawarkan tujuan URL bebas untuk alur transaksi yang seharusnya memakai route aplikasi.

Pratinjau desktop/ponsel dan indikator draf tetap dipertahankan. Perubahan struktur konten lama memerlukan pemetaan kompatibel dan backup. Gambar yang belum tersedia mempunyai fallback yang rapi.

Identitas outlet dan kontak publik memakai sumber yang konsisten. Pengaturan API, secret gateway, serta kredensial tidak dimasukkan dalam payload frontend. Cabang demo, pilihan tidak layak publikasi, dan layanan tidak aktif tidak ditawarkan untuk pemesanan baru. Penyaringan tampilan dilengkapi pemeriksaan server ketika formulir dikirim.

Harga transaksi tersimpan, ID layanan, unit/stok, deposit, biaya perjalanan, pembayaran, fee terapis, dan histori pelanggan tidak ditulis ulang untuk menyesuaikan desain. Rincian yang belum lengkap tetap memerlukan keputusan admin.

## Pengerjaan Teknis

Implementasi memakai Laravel Blade, CSS, dan JavaScript yang sudah digunakan aplikasi. Komponen publik bersama dibuat agar perubahan tema konsisten dan tidak bergantung pada salinan CSS setiap halaman.

| Area sumber sekarang | Arah pengerjaan |
|---|---|
| `resources/views/landing/show.blade.php` | Susun ulang beranda dari komponen yang dapat digunakan kembali. |
| `resources/views/landing/partials/` | Perbarui pemilih layanan, unggulan, dan tampilan terkait booking. |
| `resources/views/catalogue/index.blade.php` | Seragamkan kartu, pilihan variasi, filter, dan CTA berdasarkan kesiapan. |
| `resources/views/landing/booking.blade.php` | Rapikan akses ke formulir dan tampilan Pijat/Spa serta Laktasi. |
| `resources/views/rental/` | Terapkan tema pada katalog, detail, keranjang, checkout, dan portal. |
| `resources/views/laundry/` | Terapkan tema pada informasi, pemesanan, estimasi, dan portal. |
| `resources/views/admin/landing/` | Sesuaikan pengelolaan konten yang diperlukan dan pratinjau. |
| Komponen Blade dan aset publik | Header/footer, kartu, formulir, dialog, status, token visual, serta interaksi bersama. |

Aturan route, CSRF, pembatasan permintaan, validasi slot, idempotensi transaksi, verifikasi pemilik, dan akses dokumen tetap menjadi bagian pengujian regresi. Skema konten tambahan ditentukan setelah pemetaan CMS; tidak ada penggantian framework dalam rencana ini.

## Urutan Paket Pengerjaan Frontend

Paket A hingga E telah diterapkan dan diperiksa di lokal. Paket F melanjutkan validasi akhir lintas layanan.

| Paket | Urutan pengerjaan | Target selesai |
|---|---|---|
| A Fondasi visual dan beranda | 1. Petakan konten/rute yang dipertahankan. 2. Buat komponen dan token visual. 3. Header/menu responsif. 4. Hero baru. 5. Empat pintu layanan. 6. Susun bagian dan footer. | Beranda lokal memiliki arah visual referensi, CTA jelas, navigasi ponsel berfungsi, dan tidak ada bagian setelah footer. |
| B Aset dan konten publik | 1. Inventaris aset. 2. Produksi/optimasi visual. 3. Pilih unggulan utama. 4. Verifikasi kontak, klaim, galeri/testimoni, paket/promo. 5. Samakan label kesiapan. | Konten publik relevan dan dapat dipertanggungjawabkan; ilustrasi, foto asli, pilihan siap pesan, dan kebutuhan konfirmasi terbaca jelas. |
| C Katalog dan halaman layanan | 1. Tema katalog. 2. Filter/keluarga/variasi. 3. Presentasi Pijat dan Spa. 4. Presentasi Laktasi. 5. Katalog/detail Rental. 6. Halaman Laundry beserta keadaan belum dibuka. | Pengunjung menemukan pilihan dan mencapai tujuan yang tepat sambil mempertahankan pilihan/filter. |
| D Formulir dan transaksi pelanggan | 1. Akses serta susunan form booking. 2. Ringkasan Home Service. 3. Keranjang/checkout Rental. 4. Estimasi dan persetujuan Laundry. 5. Pembayaran manual dan portal. 6. Keadaan gagal/sukses/draf. | Alur penting dapat dijalankan dengan aturan transaksi dan privasi yang benar dalam pengujian lokal. |
| E CMS dan optimasi | 1. Isian konten/aset/unggulan. 2. Pratinjau dan pemeriksaan publikasi. 3. Kompatibilitas konten lama. 4. Metadata pencarian/berbagi dan identitas bisnis. 5. Optimasi gambar, font, serta aset. | Admin dapat mengelola tampilan dan konten utama; halaman siap menjalani pengukuran performa dan pemeriksaan publikasi. |
| F Validasi akhir | 1. Responsif/keyboard. 2. Data panjang/kosong/tidak siap. 3. Alur empat layanan. 4. Privasi dan regresi transaksi. 5. Browser dan suite yang relevan. 6. Dokumentasi hasil serta bukti visual. | Kriteria penerimaan dipenuhi dan batas pemeriksaan dicatat sebelum rilis terpisah. |

Paket A menyelesaikan fondasi visual dan beranda. Paket B menambahkan ilustrasi foto natural, layanan pilihan melalui CMS, kontak, dan FAQ. Paket C menyelaraskan katalog dan halaman masing-masing layanan. Paket D memperjelas urutan booking, ringkasan ongkos Home Service, checkout Rental, estimasi Laundry, serta tindakan di portal pembayaran.

Pemeriksaan Paket D mencakup 815 pengujian otomatis dengan 6.993 assertion dan 18 pemeriksaan responsif pada enam halaman. Simulasi menggunakan cabang, slot, stok, dan rute uji di basis data terpisah. Cabang dan jadwal operasional Kediri perlu dilengkapi agar pelanggan dapat memilih slot nyata. Hasil pengujian tersimpan di `storage/logs/landing-package-d/full-suite-final.txt` dan `storage/logs/landing-package-d/browser-final.json`.

## Kriteria Penerimaan

1. Beranda, katalog, booking Pijat/Spa, Laktasi, Rental, serta Laundry membuka tampilan normal pada kondisi data yang diuji. Pengujian dimulai di lokal tanpa mengirim pesan atau menjalankan transaksi nyata.
2. Empat layanan mudah ditemukan. CTA mengikuti status aktif/publikasi/kesiapan dan tidak menawarkan pemesanan yang sedang ditutup.
3. Pada desktop 1366 × 768, pesan utama dan CTA hero terlihat pada keadaan awal. Pada ponsel 390 × 844, header tertutup ringkas dan CTA dapat dijangkau tanpa terhalang dekorasi atau tombol mengambang.
4. Halaman diperiksa pada lebar 320, 390, 768, 1024, dan 1366 piksel, beserta pembesaran teks. Konten tidak terpotong; anchor tidak tertutup header; keyboard virtual tidak menutup tindakan penting.
5. Menu, FAQ, dialog, tab, formulir, dan kontrol variasi mempunyai nama aksesibel, urutan fokus jelas, dukungan keyboard, serta kondisi aktif/fokus yang terlihat. Menu/dialog mendukung Escape dan pengembalian fokus sesuai konteks.
6. Kontras teks biasa minimal 4,5:1 dan teks besar 3:1. Konten menyesuaikan lebar setara 320 CSS piksel sesuai pengecualian reflow yang relevan. Sasaran ergonomi tombol utama sekitar 44–48 piksel; batas WCAG 2.5.8 adalah 24 × 24 CSS piksel atau kondisi pengecualiannya. [Kontras W3C](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html), [Reflow W3C](https://www.w3.org/WAI/WCAG22/Understanding/reflow.html), [Target size W3C](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html).
7. Filter dan pilihan layanan bertahan saat berpindah ke formulir serta kembali. Backend memeriksa ulang variasi, slot, stok, ongkir, dan data publik yang memenuhi syarat.
8. Paket beberapa sesi serta pilihan belum lengkap tetap melalui konfirmasi admin. Ketersediaan dan nominal tidak diisi dengan perkiraan yang seolah sudah pasti.
9. Unggah bukti pembayaran, verifikasi pembayaran, status booking, dan status pengiriman WhatsApp mempunyai arti yang berbeda. Portal dan bukti transfer tetap privat.
10. Kontak, sosial, Maps, paket/promo, serta galeri tidak memuat placeholder yang menyesatkan. Semua bagian berakhir sebelum footer.
11. Ukur pemuatan, interaksi, serta kestabilan layout pada halaman representatif. Sasaran Core Web Vitals yang dirujuk adalah LCP ≤2,5 detik, INP ≤200 ms, dan CLS ≤0,1 pada persentil ke-75 kunjungan; pengukuran laboratorium lokal dicatat terpisah dari data pengguna produksi. [Web Vitals](https://web.dev/articles/vitals).
12. Hasil pengujian menyertakan screenshot desktop/ponsel, skenario yang lulus, kendala yang masih ada, serta bukti bahwa transaksi historis dan konfigurasi bisnis tidak berubah tanpa tindakan yang memang dibutuhkan oleh implementasi.

## Acuan Pengerjaan

- Referensi visual: `docs/references/landing-page-reference-2026-11-03.png`.
- Audit landing page: `storage/logs/landing-audit-20261103/measurements.json` dan screenshot pada direktori tersebut.
- Audit serta stabilisasi admin terkait CMS: `docs/AUDIT_UI_UX_ADMIN_2026-11-02.md` dan `docs/PERBAIKAN_UI_UX_PAKET_D_2026-11-03.md`.
- Saat implementasi dimulai, baca rencana ini, kondisi sumber terbaru, serta aturan proyek yang berlaku sebelum mengubah halaman.
