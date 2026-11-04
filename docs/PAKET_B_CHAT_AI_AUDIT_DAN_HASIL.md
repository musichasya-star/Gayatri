# Paket B Chat AI — Layanan, FAQ dan Pengarahan Web

Tanggal: 1 November 2026. Lingkup: lokal, Tahap 2–4 pada RANCANGAN_CHAT_AI_OTOMATIS.md.

## 1. Hasil enam langkah

| Langkah | Implementasi |
| --- | --- |
| 1. Pisahkan layanan | Router spa, lactation, rental, laundry dan informasi umum; mengenali kata layanan serta nama katalog aktif |
| 2. Menu dan teks bebas | Menu utama, submenu, pergantian domain, klarifikasi, konteks 30 menit, reset dan angka sesuai menu yang tampil |
| 3. Knowledge per layanan | Cakupan eksplisit, publikasi, audience, peninjau, kata kunci, tanggal berlaku dan revision |
| 4. Katalog aktual | Tarif/durasi treatment, tarif rental/deposit serta tarif/minimum/durasi laundry dibaca dari data aktif |
| 5. Pengarahan web | Named route dan APP_URL, tautan grup laktasi, produk rental aktif, pemeriksaan layanan tutup dan kemampuan portal |
| 6. Pengujian lokal | Suite otomatis, simulasi browser beberapa giliran, formulir knowledge, backup dan pemeriksaan data |

Pengamanan Paket A tetap berlaku. Tidak ada jalur baru yang membuat booking/order, mengubah profil, menerima pembayaran, menyetujui refund, atau membatalkan transaksi dari AI.

Jawaban informasi dirangkai secara lokal dari katalog, panduan operasional aplikasi dan FAQ yang diterbitkan. Jalur ini tidak memanggil provider AI eksternal. Pertanyaan yang tidak memiliki informasi terverifikasi menghasilkan klarifikasi atau petunjuk bantuan admin.

## 2. Contoh perilaku

| Pesan | Hasil |
| --- | --- |
| Halo | Menu empat layanan, cek status melalui admin, dan pilihan admin |
| 3 setelah menu utama | Submenu rental |
| 3 setelah submenu rental | Tautan pemesanan rental |
| Harga pijat bayi | Treatment serta harga/durasi dari kelompok spa aktif |
| Mau konsultasi ASI | Form khusus kelompok lactation |
| Laundry baju kena ASI | Domain laundry |
| Persiapan laktasi | FAQ terbit laktasi/general yang relevan; jika belum ada, panduan dasar atau admin |
| Harga stroller | Tarif rental aktif dan deposit; keputusan periode/stok tetap di web |
| Mau laundry, ketika publik/outlet belum siap | Bantuan admin, tanpa menyalakan pengaturan layanan |
| Refund laundry | Bantuan admin; tidak menjanjikan portal refund laundry |
| Perpanjang rental | Petunjuk portal pribadi dan persetujuan, tanpa menerbitkan token |
| Sudah transfer | Penjelasan verifikasi manual; tidak mengubah status pembayaran |
| Cek kode booking/order | Handoff admin; lookup privat tetap Paket C |
| Bayi demam boleh dipijat dan konsultasi ASI? | Batasan medis dan handoff, meskipun ada beberapa domain |

Angka tanpa konteks tidak otomatis menjadi layanan. Rating feedback yang sah dikonsumsi oleh alurnya; ketika menu aktif, angka menu tidak sekaligus menjadi rating. Teks eksplisit seperti “rating 4” tetap dapat digunakan.

## 3. Pengelolaan knowledge

Halaman lokal:

- http://127.0.0.1:8000/admin/ai/knowledge-base
- http://127.0.0.1:8000/admin/ai/auto-replies
- http://127.0.0.1:8000/admin/ai/simulator
- http://127.0.0.1:8000/admin/ai/logs

Alur penggunaan:

1. Tambah/edit FAQ dan pilih satu atau beberapa cakupan layanan.
2. Pilih pembaca publik pelanggan atau internal saja.
3. Isi kata kunci dan masa berlaku bila diperlukan.
4. Simpan sebagai draft untuk menyiapkan materi.
5. Owner/manager meninjau dan memilih Terbit untuk FAQ yang boleh dipakai pelanggan.
6. Uji pertanyaan pada Simulator. Kirim “Halo”, lalu pilihan angka pada giliran berikutnya untuk mencoba menu.

Admin dapat menyiapkan draft. Penerbitan dan pengaktifan kembali konten terbit yang nonaktif memerlukan owner/manager. Field peninjau ditetapkan server.

Hanya konten aktif, terbit, publik, sesuai domain dan masih berlaku yang masuk retrieval. Knowledge umum harus diberi cakupan general secara eksplisit. Dokumen internal tidak menjadi fallback publik.

Harga bukan isi FAQ statis: tarif dibaca dari katalog. Penerbitan menolak pola instruksi/kredensial, klaim konfirmasi transaksi dan nominal Rupiah. Tautan di konten diganti petunjuk menu; URL pemesanan dibangun aplikasi. Pemeriksaan ini melengkapi pembatasan server, bukan jaminan bahwa seluruh konten buruk dapat dideteksi otomatis.

Upload knowledge tetap di disk lokal privat. Chunk UTF-8 tidak lagi memotong byte karakter/emoji.

### Data awal lokal

- Satu knowledge lama tetap utuh dan ditandai needs_review; tidak otomatis dijadikan informasi umum.
- Lima draft baru: pemesanan spa, pemesanan laktasi, rental/pengembalian, hasil timbang laundry, pembayaran manual.
- Tidak ada konten lama yang otomatis diterbitkan.
- Panduan prosedur dasar, menu, katalog dan pengarahan web langsung tersedia dari aplikasi.
- Jawaban bisnis/FAQ tambahan tersedia setelah kontennya ditinjau dan diterbitkan.

Perintah persiapan draft yang idempoten:

~~~powershell
& 'C:\xampp\php\php.exe' artisan ai:prepare-domain-knowledge --env=baileys-local
~~~

Template/rule global lama tetap tersimpan untuk peninjauan. Field tersebut belum menggantikan knowledge terbit pada alur baru. Dashboard menjelaskan sumber jawaban yang berlaku.

## 4. Konsistensi konteks dan pengiriman

- Konteks disimpan satu row per percakapan dengan revision, menu, domain, TTL dan pesan terakhir.
- Perubahan konteks dilakukan bersama lock percakapan/pesan dalam transaksi.
- Hasil yang dihitung dari revision lama tidak dapat menimpa state lebih baru.
- Preview/simulator memakai konteks sesi admin yang terpisah; tidak membuat customer atau konteks percakapan pelanggan.
- Izin pengiriman memuat bukti sumber publik: fingerprint direktori/katalog dan hash knowledge yang dipakai.
- Worker membaca ulang sumber sebelum send dan retry.
- Tarif berubah, layanan ditutup, knowledge dicabut/kedaluwarsa, takeover, atau konteks berubah menyebabkan jawaban ditahan.
- Monitoring tetap membedakan skipped, failed dan unknown.
- Masa berlaku sumber adalah tambahan pada gate Paket A; bukan izin untuk transaksi.

Uji state yang ditambahkan adalah simulasi terkontrol pada SQLite. Uji paralel dua proses terhadap engine database produksi tetap bagian stabilisasi/deployment.

## 5. Komponen dan migrasi

Komponen utama:

- AiDomain, AiDomainRouter dan AiConversationOrchestrator.
- AiConversationContext dan tabel ai_conversation_contexts.
- AiServiceDirectory untuk fakta publik serta tujuan web.
- AiPublicContent dan AiReplyEvidence.
- KnowledgeBase/KnowledgeDomain, retrieval, controller dan form publikasi.
- Integrasi AiService, GenerateAiReplyJob, policy send/retry, simulator dan feedback.

Migrasi lokal:

~~~text
2026_11_01_000004_add_ai_domain_knowledge.php
~~~

Migrasi menambahkan scope/publikasi knowledge dan konteks percakapan. Policy revision dinaikkan agar jawaban sementara dari versi sebelumnya tidak ikut terkirim setelah perubahan perilaku.

## 6. Pengujian dan bukti

Hasil akhir:

| Suite | Test | Assertion | Hasil |
| --- | --- | --- | --- |
| Seluruh runtime aktif tanpa legacy-ai | 618 | 5.546 | Lulus, 0 failure/error |
| AiServiceAssistantTest, bagian dari runtime aktif | 47 | 185 | Lulus |
| AiInteractionPolicyTest, bagian dari runtime aktif | 42 | 249 | Lulus |
| Kompatibilitas historis legacy-ai | 116 | 608 | 5 kegagalan baseline yang sama, 0 error |

Pint dan pemeriksaan sintaks 26 file PHP yang disentuh lulus. Pemeriksaan whitespace Git juga lulus. Tidak ada aset JavaScript/CSS baru yang memerlukan build.

Pengujian juga memastikan paket mingguan/bulanan rental bernilai nol atau belum dikonfigurasi tidak ditawarkan sebagai paket gratis.

Perintah:

~~~powershell
& 'C:\xampp\php\php.exe' vendor/phpunit/phpunit/phpunit --exclude-group=legacy-ai
& 'C:\xampp\php\php.exe' vendor/phpunit/phpunit/phpunit --group=legacy-ai
~~~

Suite legacy tetap menyimpan lima kegagalan baseline Paket A. Test double retrieval disesuaikan dengan parameter domain opsional; tidak ada test lama yang dihapus untuk menyembunyikan kegagalan.

Bukti lokal:

- storage/logs/ai-package-b-runtime-final.xml
- storage/logs/ai-package-b-focus.xml
- storage/logs/ai-package-b-legacy.xml
- storage/logs/ai-package-b-files.json
- storage/logs/ai-package-b-pint.txt
- storage/logs/ai-package-b-before.json dan ai-package-b-after.json
- storage/logs/ai-package-b-knowledge-audit.json
- storage/logs/ai-package-b-menu-final.png
- storage/logs/ai-package-b-knowledge.png
- storage/logs/ai-package-b-knowledge-form.png

Browser lokal memverifikasi menu utama → rental → pesan melalui web, daftar draft/needs_review, serta field cakupan dan publikasi. Simulator ditinggalkan pada contoh menu utama.

## 7. Perlindungan data dan batas pekerjaan

Backup sebelum perubahan:

~~~text
storage/app/backups/ai-package-b-before-20261101-181536.sqlite
~~~

Integrity check backup: ok. Seluruh kolom asli pada customer, booking, pembayaran booking, rental, laundry, pesan, reminder, setting dan job identik dengan backup. Konten serta kolom asli knowledge lama juga identik.

Jumlah data bisnis tetap: 18 pelanggan, 4 booking, 2 payment booking, 9 rental, 4 laundry, 12 pesan termasuk 8 outgoing. Job tetap 0. Penambahan yang disengaja adalah schema, lima draft knowledge dan log simulasi.

Tidak ada worker, gateway atau scheduler yang dinyalakan. Tidak ada pesan WhatsApp nyata atau pemanggilan provider berbayar dalam pengujian. Flag pemesanan laundry/rental tidak diaktifkan oleh paket ini.

Pengujian menggunakan SQLite lokal dan fake HTTP. MySQL/MariaDB VPS, koneksi nomor WhatsApp nyata dan deployment belum dikerjakan. Source proyek lain di VPS tidak disentuh.

Berikutnya adalah Paket C: identitas pengirim serta lookup status berdasarkan kode, nomor dan pemilik yang terverifikasi.
