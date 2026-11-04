# Paket D — Kendali admin, percakapan, dan monitoring AI

Tanggal: 1 November 2026. Status: selesai dan diuji di lokal; belum rilis VPS.

## Hasil untuk pelanggan

Asisten menjawab inti pertanyaan lebih dulu, memakai isi FAQ yang relevan, dan tidak menyalin judul dokumen atau menambahkan menu panjang pada setiap jawaban. FAQ kedua yang hanya cocok dengan kata umum disaring. Sapaan tidak diulang pada setiap giliran, tidak ada signature otomatis yang panjang, dan emoji tidak ditambahkan sendiri.

Contoh alur:

```text
Pelanggan: Bagaimana kelengkapan alat rental?
Asisten: Periksa daftar kelengkapan alat dan cocokkan bersama petugas
saat serah terima. Catat ketidaksesuaian sebelum alat digunakan.

Mau lihat katalog alat dan tarif sewanya?
Pelanggan: iya
Asisten: [katalog rental aktif beserta tarif dan tautan pemesanan]
```

Balasan `iya`, `boleh`, atau `lanjut` mengikuti tawaran yang sedang ditampilkan. `Nanti dulu` menutup tawaran dengan singkat. Jika katalog belum tersedia atau layanan pemesanan belum dibuka, asisten menawarkan bantuan admin, bukan menawarkan katalog kosong. Pertanyaan yang tetap belum jelas setelah batas klarifikasi diserahkan ke admin.

Pemesanan dan perubahan transaksi tetap melalui web/modul admin. Pengecekan status tetap menggunakan seluruh verifikasi Paket C. Gaya bahasa dan template tidak dapat memberi izin membuat booking, menandai lunas, atau membaca transaksi orang lain.

## Pengaturan admin

Buka [Balasan Otomatis lokal](http://127.0.0.1:8000/admin/ai/auto-replies).

- Atur nama asisten/bisnis, sapaan, gaya hangat/profesional/ringkas, emoji dan tawaran langkah berikutnya.
- Nomor dan urutan menu tetap mengikuti registri empat layanan; teks panduan tiap layanan dapat disunting.
- Sunting sapaan awal, balasan terima kasih, informasi belum tersedia, penyerahan ke admin dan panduan singkat empat layanan.
- Atur jam/hari admin dalam WIB serta pesan di luar jam layanan. FAQ tetap berjalan; pesan di luar jam hanya ditambahkan ketika percakapan diserahkan. Tidak ada janji waktu balasan yang otomatis dibuat.
- Batas klarifikasi dapat diisi 1–3. Konteks mengikuti TTL dan revisi percakapan yang sudah ada.
- Gunakan **Lihat Pratinjau** untuk mencoba nilai yang belum disimpan. Pratinjau tidak menyimpan pengaturan/log, tidak membuat job, dan tidak memanggil provider berbayar.
- Owner/manager mengatur aktivasi global/domain. Admin boleh mengubah bahasa dan panduan tetapi tidak mengubah sakelar tersebut. Sales/therapist tidak memperoleh akses pengaturan/log AI.
- Penyimpanan memakai revisi kebijakan dan lock. Form usang ditolak; perubahan membatalkan kelayakan balasan lama dalam antrean. Konten lama tetap disimpan, tidak diaktifkan sebagai jalur transaksi.

Template menerima variabel `{sapaan}`, `{nama_asisten}`, dan `{nama_bisnis}`. HTML, tautan bebas, kredensial, nominal harga, variabel pelanggan, instruksi mutasi serta klaim transaksi ditolak. Harga dan tujuan pemesanan berasal dari katalog/route aplikasi.

## Knowledge Base: demo → draft → tinjauan → terbit

[Knowledge Base lokal](http://127.0.0.1:8000/admin/ai/knowledge-base) menampilkan jumlah materi terbit, draft, perlu ditinjau, dan demo. Tombol **Salin ke draft tinjauan** membuat salinan non-demo yang belum terbit; pemanggilan ulang tidak membuat salinan ganda atau menimpa hasil suntingan.

Admin menyesuaikan ketentuan bisnis dalam draft. Owner/manager meninjau dan menerbitkannya. Salinan demo asli tetap simulator saja. Penyimpanan/perubahan status materi memakai pemeriksaan revisi sehingga tinjauan usang tidak menimpa materi baru. Tidak ada FAQ operasional yang diterbitkan otomatis selama pengerjaan ini; 30 demo dan enam materi lama tetap utuh.

## Inbox dan penyerahan ke admin

[WhatsApp Inbox lokal](http://127.0.0.1:8000/admin/inbox) menampilkan layanan, alasan keputusan terakhir, hasil pengiriman, kebutuhan bantuan, dan tautan penelusuran. Filter **Menunggu bantuan admin** tidak bergantung pada unread.

- Membuka Inbox menandai pesan terbaca; penanda bantuan tetap ada sampai admin mengambil alih/menanganinya.
- **Ambil alih percakapan** selalu mengambil alih, tidak lagi bergantian fungsi dengan tombol yang sama.
- **Lanjutkan AI · pesan baru** adalah tindakan terpisah dan tercatat dalam audit. Pelanggan harus aktif/lead, tujuan WA valid, dan AI global aktif. Form kendali usang ditolak.
- Balasan manual menghentikan AI sebelum masuk antrean, sehingga balasan bot yang masih tertunda tidak ikut dikirim.
- Takeover/resume membersihkan konteks domain dan lookup sementara. Batas ID pesan dan waktu resume mencegah pemutaran ulang backlog, termasuk webhook lama yang baru diproses setelah resume. Pesan pada detik yang sama dengan resume diperlakukan konservatif sebagai konteks lama.
- Kegagalan/ketidakpastian pengiriman AI ditandai perlu perhatian. Antrean yang gagal menerima pekerjaan menahan keputusan dan menyerahkannya ke admin; exception mentah tidak disalin ke log AI.

## Penelusuran keputusan dan pengiriman

[AI Logs lokal](http://127.0.0.1:8000/admin/ai/logs) dapat difilter berdasarkan layanan, maksud, alasan, ID pesan sumber, mode dan hasil pengiriman. Halaman detail memperlihatkan:

1. ID/waktu pesan masuk dan tautan Inbox.
2. Layanan, keputusan, sumber jawaban dan revisi kebijakan.
3. Pesan keluar serta hasil gateway, berikut alasan gagal/ditahan/belum pasti.

Tautan dua arah menghubungkan detail keputusan dengan [Monitoring WhatsApp](http://127.0.0.1:8000/admin/whatsapp/monitor). Tautan monitoring dapat memusatkan pemeriksaan pada satu ID pesan keluar. Keputusan sebelum worker mulai tetap terlihat sebagai menunggu worker; ketika pesan dibuat, ID keluarnya ditautkan.

Keputusan dan pengiriman memiliki label terpisah: misalnya keputusan “FAQ sesuai layanan” bisa memiliki hasil kirim “koneksi terputus”. Penerimaan gateway, perangkat menerima pesan dan pesan dibaca dibedakan. Tampilan tidak memakai confidence 100% sebagai jaminan kebenaran.

Pemrosesan sumber diserialisasikan di transaksi singkat, dengan pemeriksaan ulang sesudah jawaban terbentuk. Jalur informasi saat ini lokal dan deterministik; tidak ada panggilan jaringan/model di dalam lock. Kunci pengiriman terikat keputusan/sumber sehingga replay tidak menambah balasan. Takeover/resume di tengah proses tidak dapat mengadopsi revisi baru untuk jawaban lama.

Hasil `unknown` tidak diulang otomatis. Retry manual tetap melalui monitoring: fingerprint, alasan pemeriksaan, konfirmasi belum terkirim, kesehatan sesi, identitas, kebijakan dan konteks diperiksa lagi. Callback worker terlambat tidak mengubah pengiriman yang sudah diterima menjadi gagal.

## Simulator

[Simulator lokal](http://127.0.0.1:8000/admin/ai/simulator) memakai alur yang sama dengan runtime, menyediakan tombol contoh skenario dan riwayat maksimum 10 giliran per sesi. FAQ demo tetap memerlukan centang demo; status privat tetap terbatas pada dua profil demo Paket C.

Sesudah handoff, AI pada simulator berhenti. Gunakan **Lanjutkan AI pada simulator · pesan baru** atau **Mulai percakapan baru**. Mengganti profil atau kedaluwarsa konteks mereset konteks; memulai percakapan baru menghapus riwayat sesi. Kode lookup tetap terenkripsi dalam konteks server. Riwayat/log menyamarkan nomor, token dan kredensial yang dapat dikenali.

Uji cepat: centang demo, kirim `Bagaimana kelengkapan alat rental?`, lalu `iya`. Uji juga `admin`, lalu sebuah pertanyaan: simulator harus menunjukkan bahwa percakapan sedang ditangani admin sampai dilanjutkan.

## Contoh monitoring lokal

Command `ai:seed-operations-demo` membuat tiga contoh **failed**, **unknown**, dan **skipped**. Sudah dijalankan lokal untuk membuat fitur dapat ditinjau.

- Satu pelanggan `[DEMO] Monitoring AI Paket D` berstatus inactive dan satu percakapan dengan tujuan `demo-ai-operations@local` yang bukan JID WhatsApp.
- Enam catatan pesan sintetis dan tiga keputusan contoh, bukan pengiriman WA nyata; tidak ada job/panggilan gateway.
- Provider contoh `demo`, tanpa bukti penerimaan gateway atau grant AI. Kirim ulang dikunci, termasuk jika seseorang mencoba memanggil endpoint retry.
- Detail log diberi banner DEMO. Aktifkan **Sertakan data demo** pada monitoring, atau pilih **Contoh monitoring lokal** di AI Logs.
- Command idempoten dan ditolak pada production.

## Privasi, retensi, dan data

Prompt runtime tidak diduplikasi ke AI Log; pesan asli tetap di Inbox sesuai hak akses. Hasil lookup privat tidak disalin ke response log. Grant verifikasi tidak ditampilkan atau disalin ke metadata keputusan. Tampilan/salinan log menyamarkan kredensial, token, tautan privat dan nomor yang tidak diperlukan.

`ai:prune-decisions` secara default hanya melaporkan jumlah log selesai yang berusia lebih dari 90 hari. `--apply` menerapkan retensi, dijadwalkan pukul 03:15 WIB. Antrean aktif dan pengiriman belum pasti tetap dipertahankan. Pembersihan dari UI dibatasi owner/manager dan memerlukan konfirmasi. Tidak ada pembersihan log lokal yang dijalankan selama pengerjaan ini. Scheduler tetap tidak dinyalakan.

Backup awal: `storage/app/backups/ai-package-d-before-20261101-211956.sqlite`. Perbandingan pada **20 kelompok tabel** memastikan seluruh kolom/ID baris lama tetap sama, termasuk transaksi, jurnal, knowledge, pesan, pengaturan, percakapan dan log lama. Tambahan yang disengaja hanya pelanggan/percakapan demo, enam pesan sintetis, tiga keputusan contoh dan log simulasi browser. Tidak ada booking/order, pembayaran, reminder, job atau perubahan pengaturan lama.

## Implementasi dan bukti

Migrasi `2026_11_01_000007_add_ai_operations.php` dan `2026_11_01_000008_add_ai_resume_boundary.php` sudah diterapkan ke SQLite lokal. Bagian utama: `AiConversationPreferences`, `AiResponseComposer`, `AiAuditPrivacy`, `AiTraceService`, `AiDecisionRetention`, controller/view admin, `InboxService`, `GenerateAiReplyJob`, outbox dan job kirim.

| Pemeriksaan | Hasil |
| --- | --- |
| Runtime aktif tanpa legacy-ai | **693 tes / 6.025 assertion lulus**, nol failure/error |
| Fokus Paket D | **38 tes / 286 assertion lulus** |
| Gateway Node, socket/HTTP palsu | **12 tes lulus** |
| Legacy-ai terisolasi | 116 tes / 608 assertion; lima failure historis sama dengan Paket C, nol error baru |
| Sintaks PHP / formatter | 35 file PHP lulus; formatter pada daftar file eksplisit lulus |
| Whitespace | `git diff --check` lulus |
| Integritas data awal | 20 kelompok tabel: nol perubahan baris lama |
| Browser lokal | Pratinjau sapaan tanpa simpan; FAQ langsung; “iya” mengikuti konteks; log → monitoring pada ID pesan yang sama; Inbox menampilkan kebutuhan bantuan |

Kelima failure legacy tetap berada pada dua skenario konteks `AiAutoReplyTest`, satu skenario klarifikasi `AiAutomationFoundationTest` dan dua fixture reminder Juli pada `AiAutomationReminderRuleTest`. Perbandingan nama failure dengan Paket C menunjukkan nol perubahan. Jalur mutasi legacy tetap dikunci.

Artefak pada `storage/logs`: `ai-package-d-runtime.xml/.txt`, `ai-package-d-focused.xml/.txt`, `ai-package-d-legacy.xml/.txt`, `ai-package-d-gateway.txt`, `ai-package-d-pint-final.txt`, `ai-package-d-after.json`, `ai-package-d-controls.png`, `ai-package-d-trace.png`, `ai-package-d-monitor.png`, `ai-package-d-inbox.png`, `ai-package-d-final.png` dan `ai-package-d-preview.png`.

## Batas dan langkah berikutnya

Paket D tidak menjalankan SSH/deploy, tidak mengubah VPS/proyek lain, tidak mengirim WhatsApp nyata, dan tidak memanggil provider AI berbayar. Hanya web lokal berjalan; gateway, queue worker dan scheduler tetap berhenti. Pemeriksaan halaman monitoring melaporkan kondisi tersebut dengan benar.

Race/sumber usang diuji melalui skenario terkendali dan transaksi SQLite. Beban multi-worker, migrasi/locking MySQL, koneksi WhatsApp perangkat nyata, dan UAT operasional lengkap tetap menjadi bagian **Paket E**. Rilis VPS terisolasi tetap mengikuti **Paket F**. Jika kelak ada panggilan model/jaringan dalam proses pembentukan jawaban, panggilan itu harus dipisahkan dari lock transaksi dan hasilnya diperiksa ulang sebelum dikirim.
