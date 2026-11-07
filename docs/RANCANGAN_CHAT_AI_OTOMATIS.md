# Rancangan Perubahan Chat AI Otomatis Gayatri

| Informasi | Nilai |
| --- | --- |
| Tanggal | 2 November 2026 |
| Versi dokumen | 1.5 |
| Status | Paket A–E diimplementasikan dan diuji lokal dengan gateway simulasi; tinjauan konten bisnis dan Paket F belum selesai |
| Lingkup pertama | Pengembangan dan pengujian lokal, kemudian rilis VPS tersendiri |

Navigasi cepat:

- [Laporan implementasi Paket A](PAKET_A_CHAT_AI_AUDIT_DAN_HASIL.md)
- [Laporan implementasi Paket B](PAKET_B_CHAT_AI_AUDIT_DAN_HASIL.md)
- [Laporan implementasi Paket C dan panduan demo](PAKET_C_CHAT_AI_AUDIT_DAN_HASIL.md)
- [Laporan implementasi Paket D dan kendali admin](PAKET_D_CHAT_AI_AUDIT_DAN_HASIL.md)
- [Keputusan dan batas kewenangan](#1-tujuan-dan-keputusan-utama)
- [Arsitektur dan alur pelanggan](#6-arsitektur-target)
- [Knowledge per layanan](#8-pengetahuan-katalog-dan-kualitas-jawaban)
- [Pengecekan status dan identitas](#9-pengecekan-status-transaksi-yang-aman)
- [Pengaturan admin](#10-pengaturan-admin-yang-direncanakan)
- [Pembatasan jalur transaksi lama](#14-menonaktifkan-jalur-transaksi-ai-lama)
- [Paket dan langkah pengerjaan](#16-paket-pengerjaan-dan-urutan-dependensi)
- [Matriks pengujian](#17-matriks-pengujian-wajib)
- [Definisi selesai](#22-definisi-pekerjaan-selesai)

## 1. Tujuan dan keputusan utama

Chat WhatsApp menjadi asisten informasi, pemilihan layanan, pengarahan ke website, pengecekan status transaksi, dan penghubung ke admin. Pemesanan serta perubahan transaksi dilakukan melalui halaman web yang mendukungnya atau dashboard admin.

Perubahan dilakukan bertahap pada sistem yang sudah ada. Koneksi Baileys, inbox, riwayat pelanggan, notifikasi, monitoring pengiriman, dan data transaksi tetap digunakan.

Keputusan rancangan:

1. Gunakan satu asisten WhatsApp untuk empat kelompok layanan yang terpisah.
2. Jawab pertanyaan umum dari knowledge yang disetujui dan sesuai kelompok layanan.
3. Sediakan menu angka dan pemahaman teks bebas.
4. Arahkan pemesanan baru ke formulir web yang sesuai.
5. Izinkan pengecekan transaksi melalui kode transaksi dan nomor WhatsApp, setelah identitas pengirim serta kepemilikan transaksi diverifikasi.
6. Hentikan pembuatan, konfirmasi, pengubahan jadwal, dan pembatalan transaksi oleh jalur otomatis AI.
7. Pembayaran tetap manual. Bukti transfer tidak berarti pembayaran sudah disetujui.
8. Sediakan pengambilalihan oleh admin dan penghentian AI yang benar-benar berlaku pada antrean.
9. Terapkan pembatasan dalam kode aplikasi, bukan hanya instruksi kepada model.
10. Uji lokal dengan data demo dan gateway palsu sebelum pengujian nomor WhatsApp khusus serta rilis produksi.

Dokumen ini merupakan rancangan aplikasi berdasarkan keputusan percakapan dan pemeriksaan source lokal. Paket A–E telah diimplementasikan dan diuji secara lokal; rincian aktual, batas pengujian, serta bukti test tersedia pada laporan masing-masing. Bagian **usulan**, tinjauan bisnis yang belum dilakukan, dan rilis produksi tetap merupakan target lanjutan.

## 2. Batas kewenangan WhatsApp, web, dan admin

| Aktivitas | Chat AI WhatsApp | Web pelanggan | Admin |
| --- | --- | --- | --- |
| FAQ dan pemilihan layanan | Menjawab dari sumber yang sesuai | Menampilkan detail layanan | Mengelola sumber informasi |
| Harga dan syarat | Membaca katalog serta ketentuan aktif | Menghitung rincian resmi | Mengatur tarif dan kebijakan |
| Jadwal atau stok | Informasi baca saja; keputusan akhir di web | Memvalidasi saat transaksi disimpan | Mengelola operasional |
| Pemesanan baru | Mengirim tautan formulir | Membuat permintaan sesuai aturan modul | Membantu membuat melalui dashboard |
| Konfirmasi booking | Tidak mengubah status | Menampilkan status permintaan | Mengonfirmasi sesuai otorisasi |
| Ubah jadwal | Mengarahkan ke fitur web yang tersedia/admin | Hanya kemampuan yang sudah didukung dan terotorisasi | Memvalidasi dan memproses |
| Pembatalan/refund | Menjelaskan alur, lalu mengarahkan | Permintaan melalui portal jika didukung | Meninjau dan memutuskan |
| Perpanjangan rental | Mengarahkan ke portal | Mengirim permintaan dan menghitung sesuai aturan | Memvalidasi ketersediaan/persetujuan |
| Konfirmasi hasil timbang | Mengarahkan ke portal laundry | Menyetujui hasil timbang sesuai alur | Mencatat hasil timbang |
| Bukti pembayaran | Memberi petunjuk menuju unggah privat | Unggah jika portal modul mendukung | Memeriksa dan membukukan |
| Menandai lunas/refund | Dilarang | Tidak otomatis dari unggahan | Melalui jurnal dan otorisasi modul |
| Pengecekan status | Baca saja setelah verifikasi | Portal terotorisasi | Sesuai hak akses |
| Koreksi profil CRM | Tidak menimpa profil dari hasil ekstraksi | Mengikuti verifikasi yang tersedia | Mengoreksi dengan otorisasi |

Perbedaan kemampuan setiap portal harus terlihat dalam jawaban. Portal rental dan laundry sudah mempunyai alur pelanggan, sedangkan halaman booking treatment belum boleh dianggap sebagai portal lengkap untuk seluruh transaksi historis.

## 3. Kelompok layanan dan sumber datanya

| Kode domain usulan | Label pelanggan | Sumber utama | Tujuan pemesanan lokal saat ini |
| --- | --- | --- | --- |
| spa | Pijat & Spa | Service dengan kelompok spa, addon, jadwal, promo terkait | /booking |
| lactation | Laktasi & Konsultasi ASI | Service dengan kelompok lactation, knowledge edukasi yang disetujui | /booking?service_group=lactation |
| rental | Rental Perlengkapan Bayi | RentalProduct, unit, tarif, ketentuan dan ketersediaan rental | /rental |
| laundry | Laundry Ibu & Bayi | LaundryTariff, outlet, ketentuan timbang dan antar-jemput | /laundry |
| general | Informasi Umum | Informasi bisnis yang ditandai berlaku umum | Menu bantuan atau admin |

Home service merupakan pilihan cara pelayanan pada treatment yang memenuhi syarat. Home service tidak digabungkan dengan perhitungan ongkir rental atau laundry.

Aturan:

- Gunakan identitas kelompok layanan yang tersimpan, bukan menebak ulang dari nama untuk setiap permintaan.
- Satu produk atau FAQ boleh terkait beberapa domain melalui pemetaan eksplisit.
- Status aktif domain AI tidak otomatis mengaktifkan pemesanan publik, outlet, kurir, campaign, atau notifikasi.
- Jika pemesanan publik suatu modul belum aktif, AI memberi informasi yang diizinkan dan menawarkan bantuan admin.
- Nama layanan, tarif, cabang, dan jam operasional tidak boleh dikarang atau disalin ke prompt sebagai nilai tetap.

## 4. Kondisi source yang perlu diperbaiki

Tabel berikut adalah baseline sebelum implementasi Paket A pada tanggal dokumen, bukan verifikasi keadaan VPS. Jalur mutasi telah ditutup oleh Paket A; domain, menu, knowledge dan pengarahan web telah dikembangkan melalui Paket B.

| Komponen saat ini | Temuan | Perubahan yang direncanakan |
| --- | --- | --- |
| app/Services/AI/AiService.php | Persona aktif pertama dipakai secara umum; banyak cabang balasan berpusat pada booking | Tambahkan orkestrasi domain dan pisahkan fakta, pengarahan web, serta jawaban generatif |
| app/Services/AI/ConversationFlowService.php | Alur booking_new dan booking_reschedule masih dapat dijalankan | Lewati alur transaksi pada mode baru; pertahankan sejarahnya |
| app/Services/AI/AiChatCustomization.php | Template dan aturan kata kunci bersifat global | Tambahkan cakupan domain, validasi, versi, serta prioritas yang tidak mengalahkan keamanan |
| app/Services/AI/KnowledgeRetrievalService.php | Knowledge aktif dicari tanpa filter domain | Filter domain dan publikasi sebelum menghitung relevansi |
| app/Models/KnowledgeBase.php | Sudah memiliki meta, status, dan rentang berlaku | Tambahkan cakupan domain dan status kelayakan untuk AI tanpa menghilangkan metadata lama |
| app/Services/AI/AiGuardrailService.php | Pemeriksaan kata medis/sensitif bersifat umum | Bedakan pertanyaan informasi layanan, edukasi umum, keluhan, dan permintaan tindakan berisiko |
| app/Jobs/ProcessIncomingWhatsAppMessageJob.php | Memicu balasan dan ekstraksi sebagai jalur terpisah; angka LID dapat terbawa sebagai nomor | Satukan konteks pemrosesan, identitas terverifikasi, dan idempotensi |
| app/Jobs/GenerateAiReplyJob.php | Memeriksa AI sebelum menyusun balasan, lalu memanggil flow lama terlebih dahulu | Periksa kebijakan sebelum pemrosesan dan sebelum pengiriman; tambah validasi kepemilikan pesan |
| app/Jobs/ExtractDataFromIncomingMessageJob.php | Jalur ekstraksi perlu diaudit bersama automation berikutnya | Ekstraksi pada mode baru hanya membantu pemahaman, bukan izin mutasi |
| app/Services/AI/AiAutomationExecutorService.php | Memiliki jalur mutasi booking serta profil pelanggan | Wajib memeriksa kebijakan terkini sebelum efek samping apa pun |
| app/Services/AI/AiAutomationApprovalService.php | Approval lama dapat menjalankan mutasi booking/profil | Tahan approval transaksi AI lama dan pindahkan penanganan ke modul resmi |
| app/Jobs/ProcessAiAutomationRuleJob.php dan job AI turunannya | Dapat meneruskan pembuatan customer, booking, followup, reminder, dan approval | Terapkan pemeriksaan saat job berjalan, termasuk replay job lama |
| app/Services/AI/AiService.php: bookingLookupReply | Mencari berdasarkan customer atau conversation, status aktif, dan tanggal; belum lookup kode + nomor secara tepat | Ganti dengan lookup terotorisasi untuk kode tertentu, termasuk transaksi selesai/batal |
| app/Jobs/SendWhatsAppMessageJob.php dan app/Services/WhatsApp/OutboundMessages.php | Sudah mempunyai fondasi status kirim dan idempotensi | Tambahkan konteks balasan AI agar jawaban usang atau setelah takeover tidak terkirim |
| Monitoring WhatsApp | Sudah tersedia status dan retry aman | Kaitkan alasan keputusan AI dengan status pengiriman yang ada |

Nama file di atas adalah komponen yang sudah ada. Nama kelas, tabel, dan field tambahan di bagian berikut merupakan usulan.

## 5. Aturan wajib yang harus dijaga

1. **Tidak ada mutasi transaksi dari chat AI.** Berlaku pada flow, ekstraksi, automation rule, executor, approval lama, job tertunda, retry, dan simulator.
2. **Identitas berasal dari event gateway yang diverifikasi.** Teks nomor pelanggan tidak boleh menjadi pengganti identitas pengirim.
3. **Kode + nomor + pemilik harus sesuai.** Mengetahui kode transaksi atau menulis nomor orang lain tidak memberikan akses.
4. **Tidak ada kebocoran lintas pelanggan atau domain.** Scope query, knowledge, dan payload untuk model harus dibatasi.
5. **Admin mengambil alih berarti AI berhenti.** Job yang sedang atau belum diproses harus memeriksa ulang keadaan sebelum mengirim.
6. **Status pembayaran berasal dari jurnal modul.** Unggahan, pesan “sudah transfer”, dan hasil OCR tidak boleh menandai lunas.
7. **Klik tautan dan jawaban “ya” bukan konfirmasi transaksi.** AI hanya menyatakan permintaan tercatat jika ada hasil valid dari modul transaksi.
8. **Harga dan ketersediaan bersifat dinamis.** Keputusan akhir selalu diperiksa saat web/admin menyimpan transaksi.
9. **Data lama dipertahankan.** Tidak menghapus history chat, booking, ledger, portal, atau approval untuk menyelesaikan migrasi.
10. **Gangguan tidak membuka akses.** Kebijakan/identitas yang tidak bisa diverifikasi menghasilkan penolakan aman atau bantuan admin.
11. **Tidak mengirim ulang secara buta.** Hasil pengiriman tidak pasti tetap mengikuti aturan unknown pada monitoring.
12. **Domain tidak dikenali berarti klarifikasi.** Jangan menggunakan pengetahuan spa sebagai jawaban umum untuk semua layanan.

Pembatasan izin tetap dilaksanakan oleh aplikasi meskipun pesan atau dokumen knowledge berisi instruksi untuk melanggarnya. Prinsip ini mengikuti pemisahan instruksi dan data serta pemeriksaan izin pada batas eksekusi dalam [panduan OWASP tentang prompt injection](https://cheatsheetseries.owasp.org/cheatsheets/LLM_Prompt_Injection_Prevention_Cheat_Sheet.html).

## 6. Arsitektur target

~~~mermaid
flowchart TD
    A[Pesan pelanggan] --> B[Baileys dan verifikasi webhook]
    B --> C[Simpan inbox dan cegah duplikasi]
    C --> D[Identitas pengirim dan kebijakan AI]
    D --> E{AI boleh menangani?}
    E -->|Tidak| F[Inbox untuk admin]
    E -->|Ya| G[Router domain dan maksud]
    G --> H{Jenis kebutuhan}
    H -->|FAQ| I[Knowledge domain dan data katalog]
    H -->|Pesan layanan| J[Tautan web resmi yang aktif]
    H -->|Cek status| K[Verifikasi kode, nomor dan pemilik]
    H -->|Admin atau keluhan| F
    I --> L[Jawaban berbasis sumber]
    J --> M[Validasi jawaban dan tautan]
    K --> N[Ringkasan status dari database]
    L --> M
    N --> M
    M --> O[Periksa ulang takeover, versi dan konteks]
    O --> P[Antrean kirim idempoten]
    P --> Q[Monitoring pengiriman]
    J -. pelanggan mengisi .-> R[Form web]
    R --> S[Validasi dan penyimpanan oleh modul]
    S --> T[Notifikasi transaksi resmi]
    T --> Q
~~~

AI tidak memperoleh tool bebas untuk SQL, HTTP arbitrer, file server, mutasi order, atau pembayaran. Database tetap dibaca melalui service aplikasi dengan parameter dan scope yang tervalidasi.

### 6.1 Komponen usulan

| Komponen | Tanggung jawab |
| --- | --- |
| AiConversationOrchestrator | Menentukan urutan kebijakan, router, lookup, knowledge, dan balasan |
| AiInteractionPolicy | Aturan mode, tindakan yang diizinkan, serta versi kebijakan |
| AiDomainRouter | Menghasilkan domain, intent, kandidat, alasan, dan kebutuhan klarifikasi |
| AiConversationContext | Konteks aktif, menu yang ditampilkan, langkah lookup, TTL, dan revisi |
| AiServiceDirectory | Pemetaan domain ke katalog, ketersediaan publik, dan route resmi |
| WhatsAppSenderIdentity | Identitas pengirim terverifikasi, sesi, JID/LID, dan pemetaan CRM |
| AiTransactionLookup | Validasi lookup dan orkestrasi adapter transaksi baca saja |
| BookingStatusReader | Ringkasan booking spa/laktasi dari status serta ledger booking |
| RentalStatusReader | Ringkasan rental, masa sewa, fulfilment dan pembayaran |
| LaundryStatusReader | Ringkasan laundry, timbang, proses, serah terima dan pembayaran |
| AiReplyValidator | Mencegah klaim transaksi palsu, link terlarang, dan kebocoran data |
| AiDecisionLog | Jejak keputusan yang terhubung dengan pesan dan monitoring |

Nama kelas dapat disesuaikan dengan struktur proyek saat implementasi. Pemisahan tanggung jawab dan pemeriksaan izin wajib dipertahankan.

### 6.2 Urutan penanganan

1. Verifikasi sumber event, sesi gateway, jenis chat, dan ID pesan.
2. Simpan pesan masuk sekali; tolak grup/status/broadcast/pesan dari sistem sendiri dari jalur AI pelanggan.
3. Bentuk identitas pengirim tanpa menganggap angka LID sebagai nomor telepon.
4. Periksa status pelanggan, AI global, mode, takeover, dan akses percakapan.
5. Tangani perintah admin, menu, stop, atau permintaan manusia.
6. Selesaikan alur baca saja yang aktif jika pesan sesuai, misalnya nomor pada langkah cek status.
7. Identifikasi domain dan kebutuhan; klarifikasi jika ambigu.
8. Untuk status: lakukan verifikasi pemilik sebelum membaca detail transaksi.
9. Untuk pemesanan/perubahan: ambil tautan resmi atau arahkan admin.
10. Untuk FAQ: ambil fakta katalog dan knowledge sesuai scope.
11. Susun jawaban; model hanya digunakan jika diperlukan untuk bahasa atau klasifikasi.
12. Validasi output dan periksa ulang versi, konteks, serta takeover.
13. Simpan keputusan, lalu antrekan satu balasan dengan identitas pesan pemicu.
14. Worker kirim memeriksa ulang izin sebelum memanggil gateway.

Prioritas ini mencegah rule kata kunci mengambil alih lookup privat atau melewati keputusan keamanan.

## 7. Rancangan pengalaman pelanggan

### 7.1 Menu utama

Contoh:

~~~text
Halo Bunda, saya asisten virtual Gayatri.
Saya bisa membantu informasi layanan dan pengecekan status.

1. Pijat & Spa
2. Laktasi & Konsultasi ASI
3. Rental Perlengkapan Bayi
4. Laundry Ibu & Bayi
5. Cek status pesanan
0. Bicara dengan admin

Silakan pilih angka atau langsung tulis kebutuhan Bunda.
Pemesanan dilakukan melalui formulir web agar detailnya tercatat dengan tepat.
~~~

- Menu tampil saat sapaan awal, diminta dengan “menu”, atau setelah konteks kedaluwarsa.
- Nomor menu harus stabil. Layanan yang belum buka ditandai belum tersedia atau disembunyikan tanpa mengubah makna angka lain.
- Input angka dipahami hanya jika ada menu/langkah yang sesuai. Angka tanggal, jumlah alat, berat, atau kode bukan otomatis pilihan menu.
- Perintah “menu” kembali ke menu tanpa membatalkan transaksi apa pun.
- Kata “batal” pada proses cek status membatalkan langkah percakapan tersebut, bukan order.
- Pesan spesifik seperti “mau sewa stroller” langsung ditangani tanpa memaksa membuka menu.
- Pertanyaan dua domain dipisahkan secara ringkas, lalu tawarkan layanan yang ingin dilanjutkan.

### 7.2 Submenu domain

Masing-masing domain menyediakan:

1. Lihat layanan/katalog dan informasi harga.
2. Tanya ketentuan atau FAQ.
3. Pesan melalui web.
4. Cek status transaksi.
5. Kembali ke menu utama atau bantuan admin.

Nomor submenu dibaca dari konteks menu aktif, bukan diproses oleh rule angka global.

### 7.3 Pengarahan pemesanan

| Pesan pelanggan | Balasan yang diharapkan |
| --- | --- |
| “Mau booking pijat bayi besok” | Tautan booking spa; jadwal diperiksa dan dipilih di formulir |
| “Mau konsultasi ASI” | Tautan booking dengan kelompok lactation |
| “Sewa stroller satu minggu” | Tautan katalog rental/produk valid; jumlah dan periode dipilih di web |
| “Mau laundry baju bayi, dijemput” | Tautan laundry jika aktif; alamat dan ongkir diisi/dihitung di formulir |
| “Terapis datang ke rumah bisa?” | Jelaskan home service jika aktif dan eligible; biaya/radius mengikuti pengaturan |
| “Sudah isi form, sudah pasti ya?” | Jelaskan status permintaan sebenarnya; tawarkan cek kode, tanpa menjanjikan konfirmasi |
| “Tolong ubah jadwal saya” | Portal/alur web yang mendukung, atau admin jika belum didukung |
| “Saya sudah transfer” | Petunjuk unggah bukti privat atau admin; status pembayaran tidak berubah |

Jangan meminta nama, alamat, detail bayi, atau jadwal panjang melalui chat untuk mengulang formulir web. Data tambahan hanya diminta jika diperlukan untuk klarifikasi atau lookup yang aman.

### 7.4 Contoh kasus yang perlu dibedakan

- “Laundry baju kena ASI” adalah konteks laundry, bukan otomatis konsultasi laktasi.
- “Apakah pakaian bayi alergi boleh pakai pewangi?” membutuhkan informasi produk/kebijakan laundry yang disetujui; jangan memberi diagnosis.
- “Bayi demam, bisa dipijat?” memerlukan batasan keselamatan yang disetujui dan rujukan ke tenaga kesehatan/admin.
- “Saya batal tanya rental, mau spa” mengganti konteks informasi; tidak membatalkan rental yang tercatat.
- “Refund saya sampai mana?” dapat menjadi lookup status permintaan setelah verifikasi, bukan izin mencairkan refund.

### 7.5 Media, pesan sistem lain, dan preferensi pelanggan

- Paket awal berfokus pada teks. Voice note, foto, lokasi, dan dokumen tetap masuk inbox untuk admin; jangan menganggap sudah dipahami jika belum ada pemroses yang didukung.
- Foto bukti transfer di chat tidak membuktikan lunas. Arahkan ke unggah privat yang tersedia atau pemeriksaan admin.
- Jangan mengunduh URL/file yang disebut pelanggan atau knowledge secara otomatis untuk menjalankan instruksinya.
- Balasan rating feedback dan tombol campaign yang sudah mempunyai konteks terverifikasi harus diproses sesuai alurnya sebelum dibaca sebagai pilihan menu umum.
- Pastikan satu pesan angka tidak sekaligus dicatat sebagai rating dan dipakai sebagai pilihan layanan tanpa aturan yang jelas.
- Permintaan berhenti dibalas AI harus menghentikan balasan otomatis sesuai preferensi percakapan. Jangan menganggapnya pembatalan order.
- Preferensi berhenti promosi ditangani terpisah dari chat AI dan notifikasi transaksi; jangan mengaktifkan ulang opt-out yang sudah tersimpan.

## 8. Pengetahuan, katalog, dan kualitas jawaban

### 8.1 Urutan sumber kebenaran

1. Data transaksi dan ledger modul untuk status pribadi, setelah otorisasi.
2. Data katalog, tarif, promo, jadwal, dan pengaturan aktif untuk fakta operasional.
3. Knowledge terbit yang sesuai domain dan rentang berlaku untuk FAQ.
4. Template atau rule domain untuk penyampaian jawaban yang tidak bertentangan dengan sumber di atas.
5. Model untuk menyusun bahasa dari sumber yang tersedia.
6. Klarifikasi atau admin jika sumber tidak tersedia, bertentangan, atau tidak cukup.

Model tidak menjadi sumber harga, stok, jadwal, rekening, nominal tagihan, atau status pembayaran. Status privat disusun secara deterministik di aplikasi; jangan mengirim seluruh transaksi kepada model hanya untuk memformat jawaban.

### 8.2 Pengelolaan knowledge

Metadata yang diusulkan:

| Field konseptual | Isi |
| --- | --- |
| domains | Satu atau beberapa domain yang diizinkan |
| audience | customer_public atau internal_only |
| publication_status | draft, published, needs_review, archived |
| reviewed_by / reviewed_at | Peninjau dan waktu pemeriksaan |
| valid_from / valid_until | Gunakan rentang yang sudah ada |
| revision | Versi untuk audit dan invalidasi cache |
| source_reference | Asal kebijakan/dokumen yang dapat diperiksa admin |

Ketentuan:

- Dokumen umum hanya masuk domain general setelah ditinjau; knowledge lama tanpa domain menjadi needs_review.
- Jangan memetakan semua dokumen lama sebagai general karena dapat mencampurkan tarif dan ketentuan layanan.
- Isi internal, catatan medis, kredensial, dan data pelanggan tidak masuk retrieval FAQ publik.
- Chunk mengikuti izin induknya; memisahkan chunk tidak boleh menghilangkan scope.
- Filter domain + general yang disetujui, publikasi, audience, serta tanggal berlaku dilakukan sebelum ranking.
- Jika tidak ada hasil yang relevan, jangan otomatis memakai FAQ domain lain.
- Konflik harga diselesaikan melalui katalog aktif; konflik kebijakan diteruskan untuk peninjauan admin.
- Perubahan knowledge/template mengubah revisi dan membatalkan cache yang terkait.
- Mulai dengan pencarian teks yang sudah ada. Embedding/vector database menjadi peningkatan berikutnya jika evaluasi membuktikan perlu.

### 8.3 Knowledge minimum setiap layanan

| Domain | Materi minimum |
| --- | --- |
| Pijat & Spa | Daftar treatment, durasi, sasaran usia jika tersedia, persiapan, lokasi, jam, home service, cara pesan, perubahan jadwal |
| Laktasi | Jenis konsultasi, alur pertemuan, persiapan, biaya/durasi dari katalog, batas edukasi umum dan kondisi yang memerlukan tenaga kesehatan |
| Rental | Jenis alat, kelengkapan, periode sewa, deposit/DP, syarat, kebersihan, pengiriman, pengembalian, perpanjangan, denda dan pembatalan |
| Laundry | Tarif kiloan/satuan, minimum, estimasi proses, hasil timbang, pemisahan cucian sesuai kebijakan outlet, antar-jemput, pembayaran dan komplain |
| Umum | Identitas bisnis, kontak, lokasi yang berlaku, cara menghubungi admin, privasi dan petunjuk penggunaan web |

Klaim seperti steril, bebas alergi, pasti tersedia, atau hasil medis tertentu hanya boleh tampil jika ada kebijakan/fakta yang mendukung dan sudah disetujui.

## 9. Pengecekan status transaksi yang aman

### 9.1 Input dan verifikasi

Input pelanggan:

- Jenis layanan, jika format kode tidak cukup menentukan.
- Kode booking/order yang tepat.
- Nomor WhatsApp yang digunakan saat pemesanan.

Nomor boleh ditulis dengan format 08, 62, atau +62 dan separator yang wajar, lalu dinormalisasi menggunakan aturan yang berlaku. Dukungan nomor internasional lain merupakan pekerjaan terpisah karena CustomerPhone saat ini memvalidasi nomor Indonesia.

Algoritma:

1. Validasi format dan panjang input; jangan fuzzy-match kode atau menampilkan kandidat transaksi.
2. Tentukan adapter domain dari kode yang tervalidasi atau pilihan layanan.
3. Ambil identitas WhatsApp pengirim dari event gateway yang sudah diverifikasi.
4. Pastikan nomor yang diberikan sama dengan nomor pengirim terverifikasi atau identitas CRM yang sudah dipetakan dan diverifikasi.
5. Query transaksi dengan kode tepat **dan** pemilik yang sesuai. Conversation ID saja tidak memberikan akses.
6. Cocokkan kontak pemesanan yang sah, termasuk kasus snapshot kontak berbeda dengan profil CRM terbaru.
7. Jika ada duplikasi CRM, pemetaan LID meragukan, atau perubahan nomor belum diverifikasi, hentikan lookup detail dan arahkan admin.
8. Susun hanya ringkasan yang diizinkan dari status/ledger aktual.
9. Catat keputusan lookup tanpa menyimpan nomor lengkap dalam log analitik.
10. Hapus konteks verifikasi setelah selesai atau setelah TTL berakhir.

Kode transaksi adalah pengenal, bukan bukti hak akses. Pemeriksaan kepemilikan pada query mengikuti [panduan OWASP tentang IDOR](https://cheatsheetseries.owasp.org/cheatsheets/Insecure_Direct_Object_Reference_Prevention_Cheat_Sheet.html).

### 9.2 Penanganan Baileys LID

- JID telepon dan LID adalah jenis identitas berbeda.
- Jangan menghapus akhiran @lid lalu menganggap angkanya sebagai nomor telepon.
- Pemetaan harus berasal dari metadata gateway tepercaya atau verifikasi yang dikelola aplikasi.
- Simpan asal pemetaan, waktu verifikasi, dan sesi/provider terkait.
- Untuk identitas yang belum terpetakan, FAQ/menu publik tetap dapat berjalan, tetapi detail transaksi privat ditolak.
- Jangan menggabungkan profil CRM hanya karena nama atau nomor yang diketik terlihat sama.
- Perubahan nomor pelanggan ditangani melalui alur verifikasi admin; bukan prompt AI.
- Jika verifikasi otomatis lintas nomor dibangun kemudian, gunakan challenge ke kontak pemilik yang sudah diketahui, masa berlaku, batas percobaan, dan audit. Fitur ini bukan prasyarat paket awal.

### 9.3 Data yang boleh dikembalikan

| Modul | Ringkasan yang diizinkan setelah verifikasi |
| --- | --- |
| Booking spa/laktasi | Kode, layanan, tanggal/jam, cabang/cara pelayanan, status booking, status pembayaran, sisa bayar jika akurat dari ledger |
| Rental | Kode, ringkasan alat, periode, status transaksi, status antar/pengembalian, pembayaran, status permintaan perpanjangan |
| Laundry | Kode order/permintaan publik, tahap timbang/persetujuan/proses/siap/serah terima, status antar-jemput dan pembayaran |

- Termasuk transaksi selesai dan batal; jangan membatasi hanya transaksi masa depan yang aktif.
- Pisahkan status pesanan, pembayaran, pengiriman, serta permintaan refund/perpanjangan.
- Permintaan publik laundry yang belum menjadi order dibaca sebagai permintaan; jangan mengarang kode order.
- Pembayaran booking historis yang ditandai lunas tetap historis; tidak dianggap cash baru.
- Tampilkan waktu pembacaan dalam WIB bila diperlukan untuk membedakan informasi yang berubah.
- Jangan tampilkan alamat lengkap, catatan kesehatan, rekening refund, bukti transfer, catatan internal, atau token portal.
- Tautan portal hanya diterbitkan oleh service portal yang sudah memverifikasi pemilik; bukan direkonstruksi dari kode.

Contoh format setelah verifikasi:

~~~text
Kode: BK-CONTOH-001
Layanan: sesuai data booking
Jadwal: sesuai database, WIB
Status booking: Menunggu konfirmasi admin
Pembayaran: Belum ada pembayaran terverifikasi

Admin akan memproses sesuai antrean. Jika memerlukan perubahan,
saya bantu arahkan ke alur yang tersedia.
~~~

Contoh gagal yang tidak membocorkan keberadaan transaksi:

~~~text
Data belum dapat diverifikasi dari percakapan ini.
Periksa kode serta nomor WhatsApp yang dipakai saat pemesanan,
atau pilih 0 agar dibantu admin.
~~~

Default usulan: maksimal 5 percobaan lookup per pengirim per 10 menit, konteks lookup berlaku 10 menit, ditambah batas global/sesi untuk mencegah penebakan tersebar. Angka ini perlu dievaluasi dari pengujian, bukan dianggap jaminan keamanan.

## 10. Pengaturan admin yang direncanakan

Gunakan menu AI yang sudah tersedia sebagai dasar:

| Halaman | Pengembangan |
| --- | --- |
| /admin/ai/auto-replies | Tab mode, identitas asisten, menu layanan, template domain dan handoff |
| /admin/ai/knowledge-base | Domain, audience, publikasi, tinjauan dan masa berlaku |
| /admin/ai/simulator | Simulasi multi-pesan dengan domain, alasan routing, sumber, kebijakan dan pratinjau tautan |
| /admin/ai/logs | Filter domain/intent/hasil, lookup, fallback, takeover dan kegagalan |
| /admin/ai/data-automation | Tampilkan tindakan lama yang diblokir, histori dan rujukan ke modul transaksi |
| /admin/whatsapp/monitor | Tautan dari keputusan AI ke hasil pengiriman dan alasan skipped/failed/unknown |
| Inbox | Domain aktif, alasan handoff, ringkasan kebutuhan dan tombol takeover/resume |

### 10.1 Mode operasional

| Mode usulan | Perilaku |
| --- | --- |
| off | Tidak menghasilkan balasan otomatis; inbox dan notifikasi transaksi tetap sesuai pengaturannya |
| information_web | FAQ, menu, tautan web, status baca saja dan handoff |
| legacy_transaction | Label migrasi untuk keadaan lama; bukan pilihan normal setelah aktivasi mode baru |

- Simpan versi kebijakan dan siapa yang mengubahnya.
- Pada cutover, information_web menjadi mode aktif; kegagalan baca kebijakan berarti berhenti aman.
- Jangan menyediakan tombol yang diam-diam mengaktifkan mutasi lama.
- off hanya mematikan balasan AI; pengiriman transaksi modul tetap dibedakan.
- Perubahan mode tidak otomatis menyalakan gateway, worker, scheduler, atau fitur pemesanan publik.

### 10.2 Pengaturan yang dapat diedit

- Nama asisten, sapaan, gaya bahasa, emoji, dan signature.
- Aktif/nonaktif domain, label menu, ringkasan layanan dan urutan yang tervalidasi.
- Greeting, fallback, link pemesanan, penjelasan pembayaran, dan pesan di luar jam layanan.
- FAQ/rule yang dibatasi domain, prioritas, periode berlaku, dan status aktif.
- Jam admin serta balasan penyerahan ke admin.
- Batas panjang respons, jumlah klarifikasi, TTL konteks, dan lookup dalam rentang aman.

Pengaturan link utamanya dipilih dari route resmi. URL bebas tidak boleh memuat host sembarang atau token; jika ada kebutuhan domain tambahan, kelola daftar host yang disetujui.

Admin tidak dapat menonaktifkan pemeriksaan pemilik, membuat AI menandai lunas, atau memberi tool mutasi hanya melalui kolom instruksi.

### 10.3 Hak akses

| Peran | Hak usulan |
| --- | --- |
| Owner/manager aktif | Mengubah mode, publikasi knowledge, pengaturan utama dan melihat audit |
| Admin aktif | Mengelola konten/rule dan simulasi sesuai otorisasi; dapat menerbitkan Knowledge Base sejak 7 November 2026 sesuai permintaan pengguna. Izin perubahan mode AI tetap mengikuti pengaturan sebelumnya. |
| Sales/therapist | Inbox dan informasi sesuai pembatasan yang sudah berlaku; tidak otomatis memperoleh pengaturan AI atau data transaksi semua pelanggan |

Hak baru harus eksplisit melalui middleware/policy. Jangan memperluas scope CRM/inbox yang sudah dilindungi.

## 11. Pengarahan web dan hubungan transaksi

### 11.1 Tautan dan validasi

- Bangun tautan dari named route aplikasi dan base URL lingkungan yang benar.
- Lokal memakai alamat lokal; produksi memakai https://gayatri-kediri.web.id setelah diverifikasi saat rilis.
- Hanya parameter publik yang tervalidasi, misalnya kelompok layanan atau ID produk yang aktif.
- Jangan menganggap parameter preselect produk/layanan sudah didukung; implementasikan serta validasi bila dipakai.
- Nama, nomor WA, alamat, catatan bayi, kode akses, dan bukti transfer tidak dimasukkan ke query string.
- URL hasil model harus diganti/divalidasi terhadap tujuan resmi sebelum dikirim.
- Jangan mengirim URL 127.0.0.1, host internal, atau domain dashboard WAHA/Baileys kepada pelanggan produksi.
- Layanan yang ditutup, produk nonaktif, atau formulir yang tidak tersedia menghasilkan bantuan admin.
- Tidak perlu membuka endpoint lookup status publik baru untuk memenuhi fitur cek status WhatsApp.

### 11.2 Form dan pembayaran

Setiap formulir harus memvalidasi data pada server, menampilkan kesalahan yang bisa diperbaiki, serta memberi kesempatan pelanggan memeriksa ringkasan. Prinsip ini selaras dengan [panduan validasi formulir W3C](https://www.w3.org/WAI/tutorials/forms/validation/).

- Booking tetap memeriksa jadwal, service group, kapasitas, cara pelayanan, ongkir home service, serta verifikasi kontak.
- Rental tetap memeriksa keranjang, stok per unit, masa sewa, hold, ketentuan, deposit, ongkir dan denda sesuai modul.
- Laundry membedakan estimasi awal dari hasil timbang, persetujuan pelanggan, ongkir dan invoice final.
- DP, pelunasan, refund, dan kuitansi mengikuti jurnal masing-masing modul.
- Bukti pembayaran tetap privat, validasi tipe/ukuran file tetap berlaku.
- Notifikasi setelah transaksi dipicu oleh event bisnis yang tersimpan, bukan oleh kalimat AI.
- Jika booking treatment belum memiliki portal unggah bukti untuk pelanggan, gunakan bantuan admin dan dashboard keuangan; jangan menjanjikan fitur yang belum ada.

### 11.3 Pelacakan asal dari WhatsApp

Untuk paket awal cukup tandai sumber publik nonpersonal seperti source=whatsapp jika form mendukungnya. Tanda sumber ini hanya untuk analitik, bukan otorisasi atau bukti kepemilikan.

Penghubung privat percakapan ke form merupakan peningkatan opsional:

- Token acak, masa berlaku, scope, dan penggunaan dibatasi.
- Simpan hash token, bukan token mentah dalam log.
- Jangan memuat data pribadi di dalam URL.
- Tautan yang diteruskan tidak boleh otomatis menautkan order baru ke identitas pengirim awal.
- Konfirmasi kontak tetap mengikuti aturan modul.

## 12. Status percakapan, antrean, dan handoff

### 12.1 Konteks percakapan

Pisahkan konteks dari status bisnis:

- Domain aktif dan kandidat domain.
- Maksud terakhir: faq, web_order, status_lookup, change_request, payment_help, human_handoff.
- Menu/versi menu terakhir, waktu tampil dan TTL.
- Langkah lookup: need_domain, need_code, need_phone, verifying, done.
- ID pesan terakhir yang sudah diproses dan revisi konteks.
- Waktu kedaluwarsa serta alasan reset.

Default usulan konteks umum 30 menit; konteks lookup 10 menit. Jika berpindah domain, simpan penanda konteks yang diperlukan secara terpisah; jangan membawa tanggal booking spa ke periode rental.

Paket awal tidak membuat draft transaksi di chat. Konteks yang dipertahankan hanya membantu informasi dan lookup.

### 12.2 Handoff

- Permintaan “admin”, “manusia”, atau pilihan 0 mengaktifkan handoff.
- Balasan penyerahan cukup sekali per kejadian; jangan terus membalas saat admin sedang menangani.
- Tandai percakapan perlu ditangani dan pertahankan unread sampai admin memeriksa.
- Jangan menjanjikan waktu respons yang tidak dikonfigurasi.
- Admin bisa resume AI secara eksplisit setelah kebutuhan selesai.
- Resume menghapus konteks privat yang kedaluwarsa dan menggunakan pesan baru; jangan mengirim backlog jawaban AI lama.
- Eskalasi yang belum ditangani tidak otomatis disamakan dengan kasus selesai.
- Takeover percakapan tidak mematikan notifikasi transaksi sah dari modul; jenis pengiriman harus dibedakan.

### 12.3 Antrean dan pengiriman

- Deduplikasi inbound memakai identitas provider + sesi + ID pesan yang terverifikasi; audit struktur unik yang tersedia sebelum menambah indeks.
- Handler harus aman terhadap dua event bersamaan, bukan hanya pemeriksaan exists sebelum insert.
- Serialisasi perubahan konteks per percakapan; gunakan revisi untuk menolak pembaruan usang.
- Job menyimpan ID referensi, policy revision, context revision, dan pesan pemicu; jangan menyimpan nomor verifikasi atau respons privat lengkap dalam payload antrean tanpa kebutuhan.
- Setelah panggilan model, baca ulang takeover dan revisi sebelum menyimpan status/menjadwalkan pengiriman.
- Sebelum gateway dipanggil, cek kembali AI aktif, identitas penerima/sesi, mode, dan konteks yang masih sesuai.
- Jika admin sudah membalas atau konteks berubah, tandai balasan skipped beserta alasan.
- Pesan status yang terlalu lama harus disusun ulang dari database; jangan mengirim snapshot lama setelah gateway pulih.
- Pertahankan outbox, dispatch token, message ID gateway, dan retry aman dari monitoring yang sudah ada.
- Tidak menjanjikan exactly-once lintas jaringan; timeout setelah gateway menerima tetap dapat menghasilkan unknown.
- Jangan menjalankan queue:retry all atau queue:clear untuk menyelesaikan migrasi.
- Batasi timeout dan jumlah percobaan provider; jangan membentuk loop balasan atau memanggil model untuk menu/status yang dapat dijawab aplikasi.
- Saat provider tidak tersedia, menu, link resmi, dan lookup yang aman tetap dapat memakai template lokal; jika sumber/identitas tidak tersedia, arahkan admin.

Pengambilalihan yang terjadi setelah gateway menerima pesan tidak bisa menarik kembali pengiriman tersebut. Pemeriksaan terakhir sedekat mungkin dengan send serta audit waktu perlu menjelaskan batas ini.

## 13. Perubahan data yang diusulkan

Semua perubahan bersifat tambahan dan harus diperiksa terhadap schema terbaru saat implementasi.

| Area | Pilihan penyimpanan awal | Catatan migrasi |
| --- | --- | --- |
| Mode dan domain | Perluasan setting ai.chat.customization atau namespace setting baru yang terversi | Pertahankan format lama dan tentukan satu sumber kebijakan efektif |
| Konteks AI | Tabel ai_conversation_contexts, satu row per conversation | Unique conversation_id, revision, domain, intent, step, expires_at, payload minimal |
| Knowledge domain | Tabel pemetaan knowledge_base_domains | Unique pasangan knowledge/domain; hindari menimpa meta lama |
| Publikasi knowledge | Field tambahan untuk audience, review/publikasi, revision | Dokumen lama needs_review; status aktif lama tetap terdokumentasi |
| Identitas WA | Tabel whatsapp_identities atau perluasan pemetaan yang sudah ada | Scope provider/sesi, jenis identitas, kontak terverifikasi, provenance dan revoked_at |
| Jejak keputusan | Perluasan AiLog; tabel terpisah hanya jika struktur lama tidak cukup | Domain, intent, hasil, reason_code, sumber/revisi, durasi, pesan masuk/keluar |
| Konteks balasan keluar | Field/metadata pesan dan argumen job opsional yang kompatibel | ID pemicu, revision, jenis balasan, expiry; gunakan outbox yang ada |
| Tindakan AI lama | Reason/status tambahan pada log/approval sesuai struktur yang tersedia | Simpan alasan diblokir dan tautan menuju penanganan manual |

Konteks lookup sementara yang mengandung nomor/kode diperlakukan sebagai data privat: simpan minimum, gunakan enkripsi bila perlu, batasi akses, dan hapus setelah selesai/kedaluwarsa.

Jangan menyimpan ulang ledger, nomor lengkap, detail order, atau token portal dalam log keputusan. Indeks lookup mengikuti kolom kode transaksi dan pemilik yang tersedia pada tiap modul.

Migrasi harus diuji pada SQLite lokal/test serta versi MySQL/MariaDB yang benar-benar dipakai target. Hindari ketergantungan pada enum/JSON/index yang hanya berhasil di satu engine.

## 14. Menonaktifkan jalur transaksi AI lama

### 14.1 Titik pemeriksaan wajib

| Jalur | Perlakuan pada information_web |
| --- | --- |
| ConversationFlowService untuk booking/reschedule | Tidak membuat/melanjutkan mutasi; beri tautan resmi |
| Ekstraksi data masuk | Boleh klasifikasi kebutuhan; tidak memperoleh otoritas eksekusi |
| ProcessAiAutomationRuleJob | Cek kebijakan sebelum dispatch job turunan |
| CreateBookingFromAiDataJob | Tolak saat runtime meskipun job dibuat sebelum cutover |
| Create/UpdateCustomerFromAiDataJob | Blokir pembaruan profil dari AI; jangan mengaktifkan ulang pelanggan |
| CreateFollowupFromAiDataJob | Default blokir automation berbasis ekstraksi; handoff operasional menggunakan alur aplikasi yang diaudit |
| CreateReminderFromAiDataJob | Blokir reminder yang ditentukan model; reminder modul dari transaksi sah tetap berjalan |
| CreateAiAutomationApprovalJob | Jangan membuat approval transaksi AI baru |
| AiAutomationExecutorService | Pemeriksaan terakhir sebelum seluruh efek samping, termasuk update profil sebelum confirmation |
| AiAutomationApprovalService approve/editApprove/reject | Approval transaksi lama tidak boleh memutasi booking, termasuk efek samping reject |
| Controller/test/simulator automation | Tidak menjadi jalur alternatif untuk menjalankan mutasi |
| Retry job lama | Evaluasi mode terkini; hasil blocked/skipped yang bisa ditinjau |
| SendWhatsAppMessageJob untuk balasan AI lama | Tanpa konteks izin baru, tahan untuk ditinjau; jangan kirim klaim konfirmasi lama |

Pencatatan pesan masuk dan pembuatan lead minimum untuk inbox tetap boleh berjalan sesuai perlindungan CRM. Ini berbeda dari memperbarui nomor, alamat, status, atau profil pelanggan berdasarkan tebakan model.

### 14.2 Penanganan data yang sudah terbentuk

1. Inventaris flow aktif, approval pending, booking draft/pending dari AI, dan job tertunda.
2. Hentikan flow lama secara logis dengan alasan migration_to_information_web; simpan sejarah.
3. Tahan approval lama dari seluruh endpoint yang memiliki efek samping.
4. Sediakan tindakan arsip/penyelesaian administratif yang hanya menutup catatan approval, tanpa otomatis membatalkan booking.
5. Admin memeriksa booking yang sudah benar-benar tersimpan melalui menu booking.
6. Booking valid dipertahankan; draft yang perlu dibatalkan diproses secara eksplisit sesuai aturan pembayaran dan kapasitas.
7. Jangan menjalankan ulang seluruh extracted data atau membuat booking baru untuk menggantikan catatan lama.
8. Laporkan jumlah yang ditahan, ditinjau, dan tersisa sampai rekonsiliasi selesai.

Rollback tidak boleh otomatis mengembalikan akses mutasi chat lama.

## 15. Kontrak hasil pemrosesan

Gunakan struktur hasil yang tervalidasi, bukan mengandalkan teks jawaban untuk menentukan tindakan.

Contoh konseptual:

~~~json
{
  "domain": "rental",
  "intent": "web_order",
  "result": "answered",
  "reply_source": "service_directory",
  "route_key": "rental.index",
  "transaction_mutation": false,
  "requires_human": false,
  "reason_code": "web_order_redirect",
  "policy_revision": 3,
  "context_revision": 7,
  "source_message_id": 123
}
~~~

Field contoh ini merupakan kontrak usulan dan tidak boleh dianggap sudah tersedia.

- domain dan intent menggunakan nilai yang diizinkan aplikasi.
- route_key diselesaikan aplikasi menjadi URL; model tidak menentukan host.
- transaction_mutation wajib false pada mode baru, tetapi flag ini bukan pengganti pemeriksaan izin di executor.
- reason_code digunakan oleh pengujian, log, dan monitoring.
- result lookup dapat berupa verified, verification_required, unavailable, rate_limited, atau no_verified_match; jawaban ke pelanggan tidak boleh membocorkan rincian internal.
- Nilai confidence dari model bukan probabilitas yang sudah terkalibrasi. Gunakan sumber, aturan, dan pengujian untuk memutuskan klarifikasi.

Reason code awal:

| Kelompok | Contoh |
| --- | --- |
| Kebijakan | ai_disabled, transaction_action_blocked, policy_unavailable, legacy_approval_blocked |
| Domain/konten | domain_clarification, knowledge_missing, knowledge_expired, unsupported_request |
| Web | web_order_redirect, public_ordering_disabled, destination_unavailable |
| Lookup | ownership_verified, no_verified_match, sender_unverified, identity_ambiguous, lookup_rate_limited |
| Handoff | requested_human, sensitive_request, human_takeover |
| Antrean | duplicate_event, duplicate_reply, stale_context, stale_policy, stale_status, delivery_unknown |

## 16. Paket pengerjaan dan urutan dependensi

Paket A–E telah diimplementasikan dan diuji di lokal dengan gateway simulasi. Rilis tetap menunggu tinjauan konten bisnis dan Paket F. Bukti serta batas validasi E tersedia pada [laporan Paket E](PAKET_E_CHAT_AI_AUDIT_DAN_HASIL.md). Checkbox tidak menyatakan pengujian perangkat nyata/versi engine VPS sudah dilakukan.

| Paket | Tahap | Hasil utama | Ketergantungan |
| --- | --- | --- | --- |
| A. Pengamanan fondasi | 0–1 | Baseline, mode baru, seluruh jalur mutasi tertutup | Dikerjakan pertama |
| B. Asisten semua layanan | 2–4 | Router, knowledge, menu dan tautan web empat domain | Paket A |
| C. Status transaksi | 5 | Lookup terverifikasi booking/rental/laundry | Paket A–B |
| D. Kendali admin | 6–7 | Pengaturan, handoff, simulasi, audit dan ketahanan antrean | Paket A–C |
| E. Validasi lokal | 8–9 | Regression, demo, UAT dan rekonsiliasi migrasi | Paket A–D |
| F. Rilis terkendali | 10–11 | Deploy terisolasi dan pemantauan awal | Paket E lulus dan rilis dijadwalkan |

### Tahap 0 — Audit dan baseline

**Tujuan:** mengetahui seluruh jalur yang dapat mengubah data atau mengirim pesan sebelum mengubah perilaku AI.

Langkah:

1. Catat status Git dan area perubahan lokal; jangan menimpa modul lain atau panduan deployment.
2. Catat versi aplikasi, provider AI/gateway yang digunakan, schema, route publik, dan engine database target tanpa menyalin secret.
3. Petakan alur webhook → inbox → reply job serta jalur paralel extraction → automation → executor/approval.
4. Inventaris semua action, observer, event listener, scheduler, endpoint approval, dan job lama yang bisa menulis ke booking/profil/keuangan/reminder.
5. Catat default status/flag pemesanan publik setiap layanan tanpa otomatis mengaktifkannya.
6. Ambil backup lokal dengan metode konsisten; tentukan prosedur pemulihan dan lokasi privat.
7. Jalankan baseline suite AI dan integrasi terkait menggunakan fake gateway/provider.
8. Catat kasus gagal beserta sebab, bukan hanya total. Prioritaskan kegagalan yang masih relevan pada mode baru.

**Baseline Paket A:** 239 test dan 1.460 assertion dijalankan dengan isolasi jaringan. Lima kegagalan terdahulu direproduksi: konteks layanan setelah pembatalan, penggabungan tanggal/jam, klarifikasi booking baru versus reschedule, serta 2 kasus reminder dengan tanggal fixture tetap. Kasus tersebut dipertahankan dalam kelompok uji perilaku historis legacy-ai; runtime baru diuji secara terpisah.

**Hasil:** peta alur, daftar action/job berisiko, daftar flag layanan, backup, dan laporan baseline.

**Kriteria lulus:**

- [x] Semua entry point mutasi AI dan pengiriman lama tercatat.
- [x] Database pengujian serta gateway dipastikan terpisah dari transaksi/pelanggan nyata.
- [x] Setiap kegagalan baseline mempunyai tindak lanjut: diperbaiki, dipindah ke uji kompatibilitas legacy, atau diganti dengan uji perilaku baru yang jelas.

Jangan sekadar menghapus/skip assertion keamanan untuk membuat suite hijau.

### Tahap 1 — Mode information_web dan pembatasan transaksi

**Tujuan:** membangun batas keamanan sebelum memperluas kemampuan AI.

Langkah:

1. Implementasikan AiInteractionPolicy dengan mode, revision, fail-closed, dan pembacaan terkini oleh worker.
2. Tambahkan gate pada flow lama, extraction dispatch, rule job, seluruh job turunan, executor, approval termasuk reject, dan endpoint simulasi.
3. Blokir mutasi profil hasil ekstraksi, termasuk perubahan nomor serta mengubah status inactive/blocked menjadi lead.
4. Pertahankan pencatatan inbox/lead minimum yang aman dan jalur notifikasi resmi dari modul.
5. Tambahkan pemeriksaan minimum di job kirim untuk AI off, takeover, pesan lawas, dan konteks yang hilang.
6. Tahan flow/approval lama secara logis dan simpan alasannya.
7. Sediakan balasan sementara yang aman berupa informasi umum dan tautan resmi; jangan memanggil flow transaksi lama sebagai fallback.
8. Uji job yang diantrekan sebelum perubahan mode lalu dieksekusi sesudahnya.

**Hasil:** mode baru, gate server, jalur transaksi AI lama tertutup.

**Kriteria lulus:**

- [x] Pesan “booking/ya/ubah/batalkan/sudah transfer” tidak membuat atau mengubah transaksi.
- [x] Eksekusi langsung executor dan replay job lama juga tidak menimbulkan mutasi.
- [x] Approve, editApprove, dan reject catatan legacy tidak mengubah booking.
- [x] Admin tetap bisa memproses transaksi melalui dashboard modul dengan otorisasi normal.
- [x] Notifikasi modul yang sah tetap mengikuti aturan semula.

**Bukti Paket A:** 571 test runtime aktif / 5.361 assertion lulus, termasuk 42 test kebijakan / 249 assertion. Lima kegagalan historis tetap tercatat pada kelompok legacy-ai. SQLite lokal telah dimigrasikan dan satu flow legacy ditahan. Verifikasi VPS/MySQL serta pengiriman WhatsApp nyata belum dilakukan.

### Tahap 2 — Orkestrasi, router domain, dan konteks

**Tujuan:** memahami kebutuhan empat layanan dengan konteks yang tidak bercampur.

Langkah:

1. Tambahkan registry domain dan pemetaan kelompok service yang eksplisit.
2. Buat orchestrator yang mengikuti urutan pada bagian 6.2.
3. Implementasikan router teks bebas, pilihan menu, intent, dan klarifikasi.
4. Buat konteks domain/langkah dengan TTL, revision, serta pemeriksaan ID pesan pemicu.
5. Pisahkan domain dan status bisnis; “batal” pada percakapan tidak memutasi transaksi.
6. Tambahkan antarmuka identitas pengirim; identitas belum terverifikasi tetap boleh mengakses informasi publik saja.
7. Serialisasikan perubahan konteks dan tangani pesan cepat/berurutan tanpa jawaban ganda.
8. Uji multi-domain, pergantian topik, menu kedaluwarsa, dan angka yang bukan pilihan menu.
9. Uji benturan dengan rating feedback/tombol campaign dan input media yang belum didukung.

**Hasil:** satu pintu balasan dengan routing empat domain dan general.

**Kriteria lulus:**

- [x] “Sewa stroller” diarahkan ke rental dan “laundry kena ASI” ke laundry.
- [x] “Konsultasi ASI” terpisah dari pijat/spa.
- [x] Konteks booking tidak terbawa ke rental atau laundry.
- [x] Pesan ambigu menghasilkan klarifikasi; tidak menebak tindakan transaksi.

### Tahap 3 — Knowledge dan template per domain

**Tujuan:** jawaban relevan, terkontrol, dan bersumber jelas.

Langkah:

1. Tambahkan metadata/pemetaan domain serta kelayakan publikasi.
2. Buat daftar knowledge lama yang perlu ditinjau; pertahankan isinya.
3. Siapkan FAQ minimum untuk empat layanan dan general menggunakan kebijakan bisnis yang tersedia.
4. Hubungkan fakta dinamis dengan service katalog, bukan salinan tarif di prompt.
5. Ubah retrieval agar menyaring scope, publikasi, audience, dan masa berlaku sebelum ranking.
6. Tambahkan rule/template domain serta validasi agar rule tidak mengalahkan lookup/handoff/kebijakan.
7. Sesuaikan batasan medis dan keluhan agar tidak sekadar memblokir semua kata “ASI/alergi/refund”.
8. Simpan referensi sumber/revisi dan invalidasi cache.
9. Uji konflik tarif, konten internal, knowledge kosong, kedaluwarsa, serta prompt injection dalam dokumen.

**Hasil:** knowledge terkurasi dan balasan per layanan.

**Kriteria lulus:**

- [x] Dokumen domain lain/internal tidak masuk konteks jawaban.
- [x] Konten tanpa scope yang disetujui tidak otomatis menjadi pengetahuan umum.
- [x] Harga/status tidak disimpulkan dari teks model.
- [x] Knowledge kurang menghasilkan klarifikasi/admin yang sesuai.

### Tahap 4 — Tautan web dan alur bantuan transaksi

**Tujuan:** memastikan AI membawa pelanggan ke tujuan yang bisa dipakai.

Langkah:

1. Implementasikan AiServiceDirectory dengan named route, base URL lingkungan dan status pemesanan publik.
2. Buat template pengalihan pemesanan untuk spa, laktasi, rental dan laundry.
3. Validasi produk/service aktif bila mengirim deep link; tambahkan preselect hanya jika form mendukungnya.
4. Buat matriks kemampuan portal: pesan, reschedule, pembatalan, perpanjangan, persetujuan timbang, bukti pembayaran.
5. Untuk kemampuan yang belum tersedia, arahkan admin secara jujur.
6. Tautkan pembayaran manual ke portal privat yang sah atau admin; jangan mengirim endpoint file langsung.
7. Uji URL lokal/produksi, layanan nonaktif, form tutup, ID tidak valid, dan tautan berbahaya dari model/rule.

**Hasil:** pengarahan web empat layanan yang sesuai pengaturan.

**Kriteria lulus:**

- [x] Setiap layanan aktif mempunyai link yang benar dan bisa dibuka.
- [x] Tidak ada data pribadi atau token privat dalam link katalog/form.
- [x] AI tidak mengklaim booking sudah dibuat sebelum form berhasil.
- [x] Pemesanan publik yang nonaktif ditangani dengan admin, tanpa diaktifkan otomatis.

**Bukti Paket B:** 618 test runtime aktif / 5.546 assertion lulus, termasuk 47 test asisten layanan dan 42 test kebijakan Paket A. Browser lokal memverifikasi menu beberapa giliran serta form knowledge. Lima draft FAQ disiapkan, satu knowledge lama tetap needs_review, dan data bisnis asli tidak berubah. Laporan Paket B menjelaskan batas verifikasi.

### Tahap 5 — Identitas dan lookup status terverifikasi

**Tujuan:** pelanggan dapat mengecek transaksi melalui kode dan nomor dengan perlindungan kepemilikan.

Langkah:

1. Audit normalisasi JID/LID, sesi/provider, kontak CRM serta snapshot pemesanan.
2. Implementasikan pemetaan identitas terverifikasi dan penanganan duplikasi/nomor berubah.
3. Buat alur need_domain → need_code → need_phone → verifying.
4. Buat adapter BookingStatusReader, RentalStatusReader dan LaundryStatusReader.
5. Gunakan kode tepat + scope pemilik; jangan memakai OR conversation_id sebagai jalan alternatif.
6. Baca ledger/status yang sebenarnya, termasuk selesai, batal, legacy historis dan permintaan laundry yang belum menjadi order.
7. Terapkan jawaban minimum, TTL, rate limit per pengirim serta batas global.
8. Kaitkan dengan portal hanya lewat service akses yang memverifikasi pemilik.
9. Uji lookup sah, kode/nomor salah, LID, nomor pihak lain, duplikasi CRM dan kode lintas domain.

**Hasil:** lookup baca saja untuk booking spa/laktasi, rental dan laundry.

**Kriteria lulus:**

- [x] Pemilik sah menerima ringkasan yang benar.
- [x] Orang lain yang mengetahui kode + nomor tetap tidak menerima detail.
- [x] Identitas LID tidak terpetakan tidak mendapatkan akses privat.
- [x] Tidak ditemukan, tidak berhak dan ambigu tidak membocorkan keberadaan transaksi.
- [x] Lookup tidak membuat payment, booking, order, refund, atau reminder.

**Bukti Paket C:** 655 tes runtime aktif / 5.738 assertion lulus, 37 tes khusus lookup / 192 assertion, dan 12 tes gateway lulus. Lookup booking spa/laktasi, rental, laundry dan permintaan laundry awal memakai identitas pengirim terverifikasi, snapshot kontak, scope pemilik, TTL/rate limit serta pemeriksaan ulang sebelum kirim/retry. Sebanyak 30 FAQ dan dua profil transaksi demo terisolasi tersedia pada simulator lokal. Baris data asli terverifikasi utuh setelah pemulihan insiden pengujian yang dicatat lengkap pada laporan Paket C. Belum diuji melalui WhatsApp nyata atau VPS/MySQL.

### Tahap 6 — Pengaturan admin, handoff, dan simulator

**Tujuan:** admin dapat mengatur isi balasan tanpa membuka batas keamanan.

Langkah:

1. Kembangkan halaman auto-replies, knowledge, simulator dan inbox yang sudah ada.
2. Tambahkan validasi setting, role/policy, revision dan audit perubahan.
3. Terapkan publikasi knowledge terpisah dari draft dan pratinjau.
4. Tambahkan takeover/resume eksplisit serta unread yang mencerminkan kebutuhan ditangani.
5. Simulator harus memakai jalur router/policy yang sama dengan runtime.
6. Sediakan mode simulasi identitas demo; jangan menggunakan nomor pelanggan nyata untuk mencoba query privat.
7. Tampilkan domain, intent, sumber, reason code, route tujuan dan tindakan yang diblokir.
8. Simulator tidak mengirim WA, mengubah bisnis, menerbitkan token portal produksi, atau memanggil provider berbayar secara diam-diam.
9. Jika tes provider nyata diperlukan, tampilkan sebagai tindakan tersendiri sesuai konfigurasi yang sudah sah.

**Hasil:** admin mengelola konten dan meninjau perilaku AI dari dashboard.

**Kriteria lulus:**

- [x] Role tanpa izin tidak dapat mengubah setting atau membaca log privat.
- [x] Instruksi admin yang meminta “buat booking otomatis” tetap ditolak oleh kebijakan kode.
- [x] Takeover menghentikan balasan yang masih antre; resume tidak mengirim backlog.
- [x] Simulasi dapat dijalankan tanpa pesan nyata dan tanpa mutasi transaksi.

### Tahap 7 — Observabilitas dan ketahanan antrean

**Tujuan:** keputusan AI dan kegagalan dapat dijelaskan tanpa merusak retry aman.

Langkah:

1. Hubungkan incoming message, keputusan AI, outgoing message dan hasil gateway.
2. Lengkapi deduplikasi yang tahan race condition, context revision dan policy revision.
3. Uji takeover atau perubahan mode saat panggilan model masih berjalan.
4. Tambahkan expiry untuk respons status serta pemeriksaan identitas sebelum send.
5. Tampilkan reason skipped, blocked, failed dan unknown secara berbeda.
6. Tentukan retensi log keputusan dan penghapusan konteks privat sementara.
7. Uji provider timeout, gateway putus, database/cache gagal dan worker terhenti.
8. Pertahankan retry manual yang memerlukan pemeriksaan konteks dan hasil kirim.

**Hasil:** alur dapat ditelusuri dari pesan masuk sampai hasil kirim.

**Kriteria lulus:**

- [x] Event duplikat tidak menambah balasan atau mutasi.
- [x] AI tidak menimpa status human_handled akibat race setelah model selesai.
- [x] Pengiriman tidak pasti tidak otomatis diulang.
- [x] Log tidak memuat key, kredensial, token portal atau data pelanggan yang tidak diperlukan.

**Bukti Paket D:** 693 tes runtime aktif / 6.025 assertion, 38 tes khusus operasi AI / 286 assertion, dan 12 tes gateway lulus. Gaya/templat tervalidasi, pratinjau tanpa simpan, FAQ lebih langsung dengan respons lanjutan kontekstual, tinjauan knowledge, takeover/resume terpisah, batas backlog, penanda bantuan dan penelusuran sumber–keputusan–pengiriman tersedia. Retensi menjaga antrean/hasil belum pasti. Tiga contoh monitoring lokal tidak mengirim WA dan tidak dapat di-retry. Sebanyak 20 kelompok tabel awal terverifikasi utuh. Lima failure legacy tetap sama; pengujian perangkat nyata/MySQL dan UAT lengkap menunggu Paket E.

### Tahap 8 — Regression, keamanan, dan stabilisasi

**Tujuan:** membuktikan perubahan tidak merusak modul lain.

Langkah:

1. Jalankan unit/feature untuk policy, routing, knowledge, lookup, handoff dan antrean.
2. Perbaiki kegagalan AI lama yang relevan; ubah ekspektasi legacy yang memang berubah dengan dokumentasi.
3. Jalankan integrasi booking, CRM security, ledger, home service, rental, laundry, notifikasi serta monitoring.
4. Jalankan suite penuh dengan gateway/provider/mail palsu.
5. Uji migration fresh dan upgrade dari schema lama pada SQLite serta engine target.
6. Uji request paralel, input rusak/panjang, nomor ambigu, dan serangan melalui prompt/rule/knowledge.
7. Build aset jika UI berubah; periksa format, diff, dan halaman desktop/mobile.
8. Catat batas pengujian yang belum dilakukan. Kegagalan keamanan/transaksi tidak boleh diluluskan sebagai catatan ringan.

**Hasil:** laporan pengujian, daftar risiko tersisa dan hasil perbaikan.

**Kriteria lulus:**

- [ ] Seluruh uji wajib pada bagian 17 lulus.
- [x] Tidak ada kegagalan baru pada runtime aktif modul terkait; ekspektasi chat tertutup disesuaikan dengan kendali admin baru.
- [x] Kegagalan baseline yang relevan sudah diselesaikan; tiga sisa dialog booking legacy dijelaskan beserta bukti pemisahannya.
- [x] Perhitungan dan histori pembayaran tidak berubah akibat migrasi AI.

**Bukti Paket E:** 731 tes runtime / 6.225 assertion dan 12 tes gateway lulus. Sebanyak 38 tes baru / 200 assertion mencakup kualitas, alur empat layanan dan ingress. Fresh/upgrade pada SQLite dan MariaDB terisolasi lulus, termasuk lima skenario dua proses. Dua fixture reminder legacy diperbaiki; tiga failure dialog booking lama tetap terpisah. Versi MySQL persis pada VPS dan perangkat WhatsApp belum diuji; cek tersebut tetap menjadi syarat rilis sesuai lingkungannya.

### Tahap 9 — Demo lokal dan UAT

**Tujuan:** admin menilai alur pelanggan lengkap sebelum rilis.

Langkah:

1. Siapkan pelanggan demo, knowledge, booking spa/laktasi, rental, laundry dan variasi pembayaran.
2. Gunakan database, antrean, cache prefix dan sesi gateway khusus pengujian.
3. Jalankan simulasi otomatis dahulu tanpa gateway nyata.
4. Uji melalui browser: chat/simulator → web → simpan transaksi demo → baca status.
5. Jika perlu uji WhatsApp nyata, gunakan nomor/sesi khusus dan daftar penerima yang dibatasi; aktifkan worker hanya selama pengujian yang disengaja.
6. Uji per domain: FAQ, menu, link, pemesanan, status, pembayaran manual, handoff dan kegagalan.
7. Rekonsiliasi flow/approval/job legacy; jangan hapus atau melanjutkannya massal.
8. Matikan proses uji dan pastikan tidak ada pesan demo tertunda yang bisa terkirim kemudian.

**Hasil:** data demo aman, bukti UAT dan daftar koreksi admin.

**Kriteria lulus:**

- [x] Empat layanan berhasil dilalui dari informasi sampai transaksi yang dapat diverifikasi melalui endpoint aplikasi pada database pengujian terisolasi; halaman browser diperiksa terpisah.
- [x] Pelanggan nonpemilik gagal membaca transaksi milik pihak lain pada pengujian.
- [ ] Admin meninjau panduan, menerbitkan FAQ bisnis nyata dan menyelesaikan UAT operasional dengan konfigurasi usahanya.
- [x] Tidak ada pesan atau perubahan transaksi pelanggan nyata selama pengujian.

**Tindak lanjut E:** 12 draft alur ditambahkan, tetapi belum ada FAQ non-demo terbit pada database lokal. Katalog laktasi belum mempunyai layanan aktif dan pemesanan laundry publik masih ditutup sesuai pengaturan awal. Jangan menganggap demo/suite terisolasi sebagai izin menerbitkan ketentuan atau membuka layanan. Pengguna memilih gateway simulasi dahulu pada 2 November 2026.

### Tahap 10 — Persiapan dan rilis VPS

**Tujuan:** menerapkan hasil lokal tanpa mengganggu proyek lain.

Tahap ini dilakukan pada pekerjaan deployment tersendiri; pembuatan dokumen tidak menjalankan SSH atau deploy.

Langkah:

1. Verifikasi keadaan VPS aktual: folder aplikasi Gayatri, versi release, PHP/Node, database, queue/cache, domain, dan process manager.
2. Pastikan service lain terinventaris dan tidak menjadi target perintah perubahan.
3. Siapkan backup aplikasi, database, setting, serta daftar flow/approval/job yang akan ditahan.
4. Siapkan release folder dan aset sebelum cutover; jangan menjalankan migrasi destruktif.
5. Terapkan scope database, cache prefix, antrean, worker, scheduler dan service Baileys milik Gayatri. Gunakan konfigurasi yang kompatibel dengan prefix/job lama.
6. Jangan mengubah konfigurasi global Redis/database/webserver/firewall hanya untuk kebutuhan ini.
7. Hentikan atau drain worker Gayatri yang relevan secara terkendali; pastikan tidak ada worker kode lama yang masih melakukan mutasi.
8. Jika perlu maintenance, batasi ke aplikasi Gayatri. Simpan pesan masuk dengan aman atau pastikan retry webhook tetap idempoten; jangan kehilangan event selama jeda.
9. Jalankan migrasi tambahan, aktivasi kebijakan baru, dan rekonsiliasi item legacy.
10. Jalankan worker versi baru hanya pada antrean yang benar; hindari restart seluruh Supervisor, PM2 atau Docker host.
11. Perbarui cache/config milik aplikasi sesuai perubahan. Perhatikan bahwa queue:restart berbagi cache dapat memengaruhi worker lain jika scope belum dipisah.
12. Periksa HTTPS, link produksi, callback Baileys, status sesi, worker heartbeat dan monitoring.
13. Lakukan smoke test memakai akun/nomor demo yang ditetapkan.
14. Catat release, migration batch, policy revision, waktu cutover, hasil smoke test dan prosedur rollback.

**Hasil:** release terisolasi dan laporan deployment.

**Kriteria lulus:**

- [ ] Domain aplikasi dan gateway Gayatri berfungsi sesuai konfigurasi aktual.
- [ ] Tidak ada link lokal atau endpoint internal yang terkirim ke pelanggan.
- [ ] Tidak ada worker lama yang masih mengeksekusi action AI legacy.
- [ ] Proyek lain tetap berjalan dan konfigurasi bersama tidak berubah di luar kebutuhan yang disetujui.

### Tahap 11 — Pemantauan awal dan penyelesaian

**Tujuan:** menangkap masalah nyata tanpa membuka kembali mutasi lama.

Langkah:

1. Tinjau log routing, fallback, lookup ditolak, takeover, dan kegagalan kirim pada 24–72 jam pertama sebagai kegiatan rilis; otomasi baru hanya dibuat jika diminta.
2. Perbaiki FAQ/domain yang sering salah berdasarkan contoh yang disamarkan.
3. Rekonsiliasi pesan unknown bersama monitoring; jangan melakukan bulk retry.
4. Pastikan approval/flow legacy yang tersisa telah ditinjau admin.
5. Catat keputusan akhir dan perbarui panduan penggunaan.
6. Hapus kode legacy hanya dalam pekerjaan lanjutan setelah kompatibilitas, backup dan kebutuhan histori terjamin.

**Kriteria lulus:**

- [ ] Tidak ditemukan mutasi transaksi oleh AI maupun akses lintas pelanggan.
- [ ] Masalah operasional dapat ditelusuri sampai sumber dan hasil kirim.
- [ ] Rollback yang aman tersedia dan tidak mengaktifkan mutasi chat lama.

## 17. Matriks pengujian wajib

Nama test baru merupakan usulan; gunakan struktur suite yang sesuai proyek saat implementasi.

| ID | Kasus | Hasil wajib |
| --- | --- | --- |
| POL-01 | AI off lalu pesan masuk | Inbox tercatat, tidak ada balasan AI |
| POL-02 | Permintaan booking lengkap melalui chat | Link web, tidak ada booking/hold baru |
| POL-03 | Pelanggan membalas “ya” pada konfirmasi lama | Tidak ada konfirmasi atau mutasi |
| POL-04 | Reschedule/cancel dari job legacy | Blocked, transaksi tetap |
| POL-05 | Approve/editApprove/reject legacy | Tidak ada efek samping booking |
| POL-06 | AI mengekstrak profil pelanggan inactive/blocked | Profil/status tidak diaktifkan atau ditimpa |
| POL-07 | Prompt/rule meminta mark paid atau refund | Ditolak; ledger tidak berubah |
| DOM-01 | “Mau pijat bayi” | spa |
| DOM-02 | “Konsultasi menyusui” | lactation |
| DOM-03 | “Sewa stroller” | rental |
| DOM-04 | “Laundry baju kena ASI” | laundry |
| DOM-05 | Dua domain dalam satu pesan | Jawaban terpisah/klarifikasi yang tepat |
| DOM-06 | Angka tanggal/jumlah tanpa menu | Tidak dianggap pilihan global |
| DOM-07 | Konteks menu kedaluwarsa | Tidak memakai submenu lama |
| DOM-08 | Angka rating pada alur feedback aktif | Diproses sesuai konteks, tidak menjadi dua tindakan |
| DOM-09 | Voice note/foto tanpa pemroses yang didukung | Masuk inbox/admin, tidak dianggap telah dipahami |
| KB-01 | FAQ domain lain memiliki kata sama | Tidak masuk jawaban domain aktif |
| KB-02 | Knowledge draft/internal/expired | Tidak dipakai |
| KB-03 | Harga knowledge berbeda dengan katalog aktif | Katalog diprioritaskan, konflik dicatat |
| KB-04 | Knowledge lama belum ditinjau | Tidak menjadi general otomatis |
| KB-05 | Instruksi berbahaya dalam knowledge | Tidak mengubah izin, link atau penerima |
| WEB-01 | Empat jalur pemesanan aktif | Tujuan web sesuai domain |
| WEB-02 | Publik laundry/rental dinonaktifkan | Bantuan admin, flag tidak berubah |
| WEB-03 | Model menghasilkan URL asing atau localhost produksi | Diblokir/diganti URL resmi |
| WEB-04 | Pelanggan mengklik link tanpa submit | Tidak ada order/konfirmasi |
| WEB-05 | Produk nonaktif/ID dimanipulasi | Ditolak aman oleh web dan pengarahan AI |
| LKP-01 | Kode + nomor + pengirim sah | Ringkasan akurat |
| LKP-02 | Nomor 08 dibanding +62 ekuivalen | Normalisasi benar |
| LKP-03 | Pengirim lain mengetahui kode dan nomor korban | Tidak ada detail |
| LKP-04 | LID belum terpetakan | Informasi publik boleh, lookup privat ditolak |
| LKP-05 | Duplikasi CRM atau mapping ambigu | Tidak memilih pelanggan sembarang |
| LKP-06 | Kode salah/nomor salah/tidak berhak | Balasan generik tanpa enumerasi |
| LKP-07 | Booking selesai/batal | Tetap terbaca setelah verifikasi |
| LKP-08 | Laundry masih permintaan, belum order | Status permintaan, tanpa kode/order palsu |
| LKP-09 | Bukti transfer belum disetujui | Tetap menunggu verifikasi |
| LKP-10 | Legacy paid tanpa ledger baru | Tidak menciptakan cash/payment baru |
| LKP-11 | Banyak tebakan kode | Rate limit berlaku dan diaudit |
| QUE-01 | Webhook duplikat bersamaan | Satu incoming dan satu keputusan/balasan efektif |
| QUE-02 | Takeover saat model berjalan | Balasan tertahan; human_handled tidak ditimpa |
| QUE-03 | Mode berubah ketika job antre | Kebijakan terbaru berlaku |
| QUE-04 | Job send status terlambat | Refresh status aman atau skipped |
| QUE-05 | Gateway timeout setelah kemungkinan menerima | Unknown, tanpa blind retry |
| QUE-06 | Worker replay setelah send diterima | Tidak mengirim ganda |
| QUE-07 | Sesi/nomor penerima berubah sebelum send | Ditahan; tidak dialihkan sembarang |
| QUE-08 | Provider gagal tetapi data lokal tersedia | Template/menu/lookup aman atau handoff, tanpa loop |
| ADM-01 | Sales/therapist mengakses setting/log privat | Scope/hak akses ditegakkan |
| ADM-02 | Simulator dijalankan | Tidak ada mutasi, WA nyata, atau token produksi |
| ADM-03 | Resume sesudah handoff | Tidak mengirim backlog lama |
| REG-01 | DP/pelunasan/refund, invoice dan fee therapist | Perhitungan serta histori tetap benar |
| REG-02 | Birthday/campaign/reminder/rental/laundry notification | Tidak terganggu oleh mode AI |
| REG-03 | Form publik request ganda/race kapasitas | Tetap mengikuti perlindungan modul |
| REG-04 | Migrasi fresh/upgrade SQLite dan engine target | Data lama terjaga dan schema kompatibel |

Pengujian race condition perlu menjalankan dua proses/koneksi sesuai engine yang relevan. Test berurutan dengan database in-memory saja tidak cukup membuktikan keamanan konkurensi produksi.

### 17.1 Kelompok test yang direncanakan

- AiInteractionPolicyTest: gate, legacy job, approval, dan efek samping.
- AiDomainRoutingTest: menu, teks bebas, multi-domain, TTL.
- AiKnowledgeScopeTest: publikasi, domain, audience, masa berlaku.
- AiWebDirectionTest: named route, flag layanan, link dan data pribadi.
- AiTransactionLookupTest: ownership, format nomor, LID, ledger/status.
- AiHandoffQueueTest: takeover, revisi, replay, kedaluwarsa dan unknown.
- Perluasan test AI/inbox/integrasi yang sudah ada.

Jangan mengasumsikan nama test usulan sudah dapat dijalankan.

### 17.2 Contoh perintah lokal

Perintah berikut adalah acuan untuk tahap implementasi, bukan instruksi untuk menjalankan server/worker sekarang.

Baseline test yang sudah ada, dari root proyek:

~~~powershell
& 'C:\xampp\php\php.exe' vendor/phpunit/phpunit/phpunit --exclude-group=legacy-ai --filter='Ai|WhatsAppMonitoring|BaileysIntegration|WahaIntegration'
~~~

Sesudah perubahan:

~~~powershell
& 'C:\xampp\php\php.exe' vendor/phpunit/phpunit/phpunit --exclude-group=legacy-ai
npm.cmd run build
git diff --check
~~~

- PHPUnit menggunakan konfigurasi testing; verifikasi database in-memory/test dan fake gateway/provider sebelum menjalankan suite.
- Perintah artisan untuk data/runtime lokal harus memakai lingkungan lokal khusus yang sudah disiapkan, misalnya --env=baileys-local. Jangan menggunakan database default tanpa pemeriksaan.
- Gunakan fixture waktu beku untuk test jadwal/reminder; hindari tanggal tetap yang menjadi masa lalu.
- Simpan ringkasan/JUnit secara privat; jangan menyalin output HTML yang berisi token atau data sensitif ke laporan.
- Uji koneksi provider nyata terpisah dari suite utama dan tidak diperlukan hanya untuk menyusun dokumen ini.

## 18. Skenario demo dan uji penerimaan

Gunakan data berlabel DEMO, identitas fiktif yang tidak mengarah ke pelanggan nyata, dan nomor/sesi uji yang memang dikuasai untuk pengujian jaringan.

| Demo | Alur |
| --- | --- |
| Spa | Tanya treatment → buka form spa → request demo tersimpan → kode + nomor → menunggu konfirmasi → admin konfirmasi → status diperiksa ulang |
| Laktasi | Tanya layanan ASI → FAQ laktasi → form kelompok lactation → cek booking; tidak tercampur spa |
| Rental | Tanya alat → katalog/keranjang → order demo beberapa alat → DP manual → bukti menunggu verifikasi → pembayaran disetujui → cek status dan portal |
| Laundry | Tanya tarif → formulir → permintaan jemput → hasil timbang → persetujuan portal → invoice final → bukti pembayaran → status proses/serah terima |
| Nonpemilik | Nomor lain mencoba kode + nomor pemilik → detail tidak diberikan |
| Gangguan | Gateway putus/provider gagal → pesan tidak berulang; admin melihat alasan pada monitoring |
| Handoff | Minta admin ketika jawaban masih disusun → admin mengambil alih, AI berhenti |
| Legacy | Job/approval sebelum cutover dicoba ulang → diblokir, tidak ada transaksi baru |

Data demo tidak boleh mengaktifkan scheduler campaign/birthday atau mengantrekan notifikasi ke pelanggan nyata. Di akhir UAT, cek seluruh job dan antrean notifikasi demo.

## 19. Monitoring, ukuran keberhasilan, dan privasi

### 19.1 Informasi operasional

- Volume pesan yang ditangani dan waktu proses.
- Domain/intent dan jumlah klarifikasi.
- Jawaban FAQ, pengarahan web, lookup berhasil/ditolak, serta handoff.
- Reason code kegagalan dan sumber/revisi konten.
- Hubungan keputusan AI dengan queued, sent, failed, skipped, atau unknown.
- Jumlah tindakan legacy yang diblokir dan rekonsiliasinya.

Bedakan “tautan dikirim” dari “pelanggan membuka” dan “transaksi tersimpan”. Jangan menyebut pengiriman tautan sebagai konversi booking.

### 19.2 Sasaran penerimaan awal

| Ukuran | Sasaran awal |
| --- | --- |
| Mutasi transaksi oleh AI pada mode baru | 0 dalam seluruh pengujian wajib |
| Kebocoran lintas pemilik | 0 dalam seluruh pengujian wajib |
| Routing kasus wajib | Seluruh kasus matriks yang ditetapkan lulus |
| Ketepatan status/nominal | Sesuai database/ledger pada saat dibaca |
| Duplikasi balasan pada replay test | 0 pengiriman efektif tambahan yang tidak sah |
| Link layanan | Seluruh tujuan yang dikirim valid, aktif dan sesuai domain |
| Pengiriman nyata selama suite otomatis | 0 |

Hasil pengujian tidak menjamin tidak ada bug produksi; monitoring serta kontrol server tetap wajib. Target waktu respons numerik ditetapkan setelah mengukur server dan provider yang digunakan.

### 19.3 Retensi dan akses

- Konteks verifikasi dibersihkan setelah selesai/kedaluwarsa.
- Default usulan retensi log keputusan 90 hari, ditinjau sesuai kebutuhan operasional; bukan perubahan retensi jurnal transaksi.
- History inbox mengikuti kebijakan aplikasi dan otorisasi penghapusan yang sudah ada.
- Log analitik menyimpan ID, reason, domain dan waktu; nomor disamarkan dan pesan mentah tidak disalin tanpa kebutuhan.
- Batasi payload yang dikirim ke provider AI ke informasi yang dibutuhkan, terutama untuk layanan ibu/bayi.
- Ringkasan status privat menggunakan template aplikasi agar tidak perlu mengirim detail transaksi ke model eksternal.
- Akses serta perubahan log/setting mengikuti hak peran, no-store pada dashboard, dan audit.

## 20. Risiko utama dan penanganannya

| Risiko | Penanganan |
| --- | --- |
| Mutasi masih berjalan dari job/approval lama | Gate runtime dan final executor, tahan legacy, uji replay |
| Nomor LID dianggap nomor pemilik | Identitas bertipe dan mapping terverifikasi, tolak detail jika ambigu |
| Knowledge bercampur | Scope sebelum retrieval, review konten lama, general eksplisit |
| AI mengaku booking/lunas padahal belum | Jawaban deterministik dari data, output validator, tanpa tool mutasi |
| Admin takeover tetapi AI tetap mengirim | Recheck setelah model dan sebelum send, revisi konteks |
| Harga/ongkir berubah di tengah proses | Web hitung ulang saat submit, status dibaca ulang saat dibutuhkan |
| Form/portal belum mendukung janji AI | Registry kemampuan dan fallback admin |
| Token/data pribadi tersebar lewat link/log | Link publik tanpa PII, service portal privat, redaksi log |
| Worker lama berjalan setelah cutover | Drain/restart hanya worker Gayatri dan verifikasi versi |
| Deploy mengganggu proyek VPS lain | Scope folder, database, cache/queue dan process manager |
| Rollback mengaktifkan booking AI kembali | Fallback off atau information_web aman; mutation gate tetap tertutup |

## 21. Strategi rollback

Urutan respons bila terjadi masalah:

1. Jika keamanan lookup bermasalah, nonaktifkan lookup privat dan arahkan admin.
2. Jika routing/knowledge bermasalah, gunakan template menu serta tautan resmi yang sudah diverifikasi.
3. Jika balasan AI secara umum bermasalah, ubah mode menjadi off dan lanjutkan inbox manual.
4. Hentikan hanya worker AI/Gayatri yang terkait, bukan seluruh server.
5. Jangan mengulang pesan unknown atau replay transaksi lama.
6. Jika perlu rollback kode, pilih release yang kompatibel dengan schema tambahan dan tetap mempunyai gate mutasi.
7. Jangan rollback ke versi yang otomatis menjalankan booking chat setelah antrean legacy tertahan.
8. Pemulihan database hanya dilakukan dengan rencana rekonsiliasi agar transaksi baru yang sah tidak hilang.
9. Dokumentasikan dampak, keputusan, waktu, dan hasil pemulihan.

Migrasi tambahan sebaiknya dapat dibiarkan selama rollback aplikasi. Hindari down migration yang menghapus data operasional hanya untuk kembali ke versi sebelumnya.

## 22. Definisi pekerjaan selesai

- [ ] Mode information_web aktif sesuai lingkungan yang diuji.
- [ ] FAQ/menu empat layanan berjalan dengan sumber yang tepat.
- [ ] Booking dan order baru diarahkan ke web/admin.
- [ ] Seluruh mutasi AI lama tertutup, termasuk job, approval, reject dan retry.
- [ ] Cek status memakai kode, nomor, identitas pengirim dan scope pemilik.
- [ ] Booking selesai/batal, rental, laundry serta pembayaran historis ditampilkan dengan benar.
- [ ] Admin dapat mengelola konten, takeover/resume dan simulasi dengan izin yang benar.
- [ ] Antrean idempoten, balasan usang tertahan dan unknown tidak diulang sembarang.
- [ ] Monitoring dapat menelusuri pesan masuk → keputusan → pengiriman.
- [ ] CRM, jurnal pembayaran, fee therapist, rental, laundry dan notifikasi lulus regression.
- [ ] UAT lokal serta pengujian keamanan selesai dengan bukti.
- [ ] Flow/approval/job legacy sudah direkonsiliasi.
- [ ] Panduan admin, release note, backup dan rollback tersedia.
- [ ] Jika sudah rilis: deployment Gayatri terisolasi dan smoke test produksi lulus.

Checklist keseluruhan di atas tetap menjadi target akhir. Paket A–E memiliki bukti lokal, termasuk pengujian teknis lintas modul dan engine terisolasi. Tinjauan/publikasi konten bisnis, UAT operasional dengan konfigurasi usaha, versi engine target yang persis, perangkat WhatsApp nyata dan rilis VPS belum dinyatakan selesai.

## 23. Urutan praktis saat melanjutkan pengerjaan

1. **Paket A: audit + mode information_web + penguncian seluruh jalur mutasi AI lama** sudah selesai di lokal; gunakan laporan hasil sebagai baseline berikutnya.
2. **Paket B: menu/router + knowledge per layanan + pengarahan web** sudah selesai di lokal. FAQ tambahan disiapkan sebagai draft untuk ditinjau owner/manager/admin.
3. **Paket C: lookup status dengan verifikasi pemilik** selesai di lokal. Gunakan dua profil demo pada simulator; data pelanggan nyata tidak dapat dipilih di simulator.
4. **Paket D: pengaturan admin + handoff + simulasi + monitoring** selesai di lokal. Gunakan laporan Paket D untuk pengujian gaya bahasa, tinjauan FAQ, takeover/resume dan alur pengiriman.
5. **Paket E: regression + demo/UAT teknis lokal + rekonsiliasi legacy** telah dikerjakan; gunakan laporan E untuk temuan, 12 draft tinjauan, dan bukti pengujian. Selesaikan kesiapan materi/katalog/outlet bersama admin sebelum rilis.
6. Kerjakan **Paket F: deployment terisolasi dan pemantauan awal** setelah gate konten dan konfigurasi dipenuhi; verifikasi engine/runtime target dan smoke test deployment secara terpisah.

Contoh instruksi lanjutan untuk pengembang:

> Tinjau laporan Paket E dan siapkan gate Paket F: lengkapi/publikasikan knowledge bisnis melalui owner/manager/admin, pastikan katalog laktasi dan pengaturan laundry sesuai operasi nyata, lalu verifikasi runtime/engine target sebelum deployment Gayatri terisolasi. Pertahankan seluruh pengamanan AI dan data. Pengujian WhatsApp nyata memerlukan sesi serta penerima uji yang ditentukan pengguna; saat ini pengguna memilih simulasi.

Pengayaan yang dapat dikerjakan setelah fondasi stabil:

- Analitik konversi WhatsApp → web yang menjaga privasi.
- Penghubung privat antarperangkat dengan verifikasi.
- Perbaikan relevansi pencarian berdasarkan evaluasi; embeddings bila dibutuhkan.
- Multi-cabang, bahasa tambahan, dan penugasan admin berdasarkan layanan.
- Portal booking treatment yang lebih lengkap jika diperlukan, sebagai proyek transaksi web tersendiri.

Pengayaan tersebut tidak menjadi alasan menunda pengamanan transaksi, pemisahan domain, atau lookup pemilik.

## 24. Referensi dan pemeliharaan dokumen

Sumber internal utama:

- app/Services/AI/ dan app/Jobs/ untuk alur AI serta automation.
- app/Services/CRM/ untuk transaksi, pembayaran, portal dan operasional.
- app/Services/WhatsApp/ untuk gateway, outbox, retry dan monitoring.
- routes/web.php untuk tujuan publik/admin yang benar-benar tersedia.
- tests/Feature/ untuk baseline serta regression.
- deploy/ubuntu-vps.md untuk prosedur deployment yang perlu diperiksa kembali saat rilis.

Referensi primer eksternal, diperiksa 1 November 2026:

- [OWASP: IDOR Prevention](https://cheatsheetseries.owasp.org/cheatsheets/Insecure_Direct_Object_Reference_Prevention_Cheat_Sheet.html) — dasar pemeriksaan kepemilikan objek.
- [OWASP: LLM Prompt Injection Prevention](https://cheatsheetseries.owasp.org/cheatsheets/LLM_Prompt_Injection_Prevention_Cheat_Sheet.html) — dasar pemisahan data/instruksi dan pembatasan izin di aplikasi.
- [W3C WAI: Validating Input](https://www.w3.org/WAI/tutorials/forms/validation/) — dasar validasi dan koreksi data formulir.

Detail kelas, mode, field, TTL, paket, dan acceptance criteria merupakan keputusan rancangan Gayatri dalam dokumen ini, bukan persyaratan yang dikutip dari referensi eksternal.

Setiap paket selesai harus memperbarui checkbox, file implementasi, bukti test, risiko tersisa dan versi dokumen. Perubahan scope dicatat agar pengembang berikutnya tidak menganggap usulan sebagai fitur yang sudah berjalan.

| Versi | Tanggal | Perubahan |
| --- | --- | --- |
| 1.0 | 2026-11-01 | Rancangan awal: asisten informasi empat layanan, pemesanan web, lookup terverifikasi, gate mutasi legacy, tahapan lokal sampai VPS |
| 1.1 | 2026-11-01 | Paket A selesai lokal: policy, gate legacy, kontrol antrean, backup/audit dan 571 test aktif lulus; berikutnya Paket B |
| 1.2 | 2026-11-01 | Paket B selesai lokal: empat domain, menu, knowledge terbit, katalog, tautan web dan 618 test aktif lulus; berikutnya Paket C |
| 1.3 | 2026-11-01 | Paket C selesai lokal: identitas JID/LID, lookup pemilik, 30 FAQ demo, simulator terisolasi dan 655 tes aktif lulus; pemulihan database serta pengaman tes dicatat; berikutnya Paket D |
| 1.4 | 2026-11-01 | Paket D selesai lokal: kendali/gaya admin, alur lanjutan kontekstual, tinjauan FAQ, handoff/resume tanpa backlog dan trace pengiriman; 693 tes aktif lulus; berikutnya Paket E |
| 1.5 | 2026-11-02 | Paket E teknis lokal: pemahaman bahasa, rencana jawaban gabungan, fokus katalog, audit/draft knowledge, ingress idempoten dan handoff media; 731 tes aktif/12 gateway lulus, migrasi dan dua proses MariaDB terisolasi; kesiapan bisnis/perangkat nyata/rilis tetap tercatat terpisah |
| 1.6 | 2026-11-06 | Percakapan natural: provider/model aktif digunakan pada jalur informasi aman, persona dan preferensi gaya dibaca, salam dipisahkan dari menu, riwayat singkat per percakapan dan sumber publik terbit masuk konteks; lookup, handoff, dan larangan mutasi tetap deterministik. |
| 1.7 | 2026-11-07 | Sesuai instruksi pengguna, admin dapat menerbitkan/memperbarui/mengaktifkan Knowledge Base operasional maupun DEMO. Validasi isi publik, cakupan, masa berlaku, revisi, audit penerbit, dan pemisahan DEMO tetap berlaku; izin role lain serta kendali aktivasi AI tidak diperluas. |

### Catatan implementasi percakapan natural — 6 November 2026

- Akar respons kaku: `information_web` sebelumnya hanya memakai router/composer deterministik. Mengganti provider/model tidak mengubah jalur ini. `AiNaturalConversation` kini menyusun balasan model dari jawaban berbasis data, persona, preferensi, serta maksimal delapan pesan publik terbaru dalam 30 menit pada percakapan yang sama.
- Salam dan percakapan ringan tidak memunculkan menu. Menu angka tetap tersedia atas permintaan eksplisit; menyebut nama layanan cukup dijawab dengan pembuka layanan yang singkat. Salam lanjutan mempertahankan fokus layanan/katalog.
- Persona dibaca lengkap sampai batas form 12.000 karakter setelah penyamaran rahasia; dipakai sebagai panduan gaya, tidak sebagai sumber tarif atau izin transaksi. Preferensi gaya dan aturan keamanan lebih tinggi prioritasnya. Perubahan persona membatalkan balasan antrean yang memakai versi lama.
- Knowledge diambil sesuai cakupan dan relevansi: aktif, terbit, publik, masa berlaku benar, serta bukan demo untuk WhatsApp produksi. Materi draft/internal tidak dikirim ke provider dan tidak otomatis diterbitkan. Katalog tetap menjadi sumber angka dan tautan. Riwayat bukan sumber harga atau status terkini.
- Status transaksi dan handoff tetap di jalur sistem. Riwayat sebelum resume, pesan staf, pesan gagal/belum terkirim dan hasil lookup privat tidak dipakai untuk percakapan model. Simulator membawa riwayat sesi yang disamarkan dan menghapusnya saat reset/resume/pergantian profil demo.
- Keluaran JSON diperiksa format, sumber, angka, tautan, klaim tindakan, batas panjang, dan batasan penting pemesanan/pembayaran. Ini pengamanan aplikasi, bukan bukti bahwa seluruh makna keluaran model selalu benar; evaluasi contoh percakapan tetap diperlukan. Provider gagal/timeout atau hasil tidak lolos pemeriksaan memakai jawaban sistem tanpa memaparkan error mentah/API key.
- Simulator sekarang memakai provider tersimpan jika tersedia dan mencantumkan pemakaian API. Pratinjau gaya yang belum disimpan tetap lokal. AI Logs menampilkan provider/model sebenarnya serta alasan penggunaan jawaban cadangan.
- Tidak diperlukan migrasi atau impor ulang database. Aktivasi produksi memakai rilis kode Gayatri terpisah dan restart antrean secara graceful; gateway WhatsApp dan proyek lain tidak perlu diubah.
- Validasi lokal perubahan: 371 tes AI aktif dengan 2.230 assertions lulus (grup legacy yang mengharapkan mutasi transaksi lama dikecualikan). Uji model nyata pada kandidat rilis memakai pesan sintetis tanpa membuat transaksi/pesan WhatsApp. Pertanyaan harga boleh dijawab tanpa tautan yang tidak diminta; tautan pemesanan tetap wajib bila diarahkan ke web. Fokus layanan dipertahankan pada “cara bookingnya”; pertanyaan usia masuk FAQ, bukan pencarian harga.
