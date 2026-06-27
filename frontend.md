# Tambahan Prompt UI/UX Theme

## Brown Gold Classic Modern — Gayatri Mom & Baby SPA CRM

Ubah konsep visual aplikasi menjadi tema:

**Brown Gold Classic Modern, full soft colour, elegant, premium, warm, dan mobile friendly.**

Aplikasi tetap harus terlihat modern dan profesional, tetapi memiliki nuansa klasik elegan seperti brand premium Mom & Baby SPA.

---

# 1. Theme Direction

Gunakan desain dengan karakter:

* Classic modern
* Premium
* Warm
* Soft luxury
* Elegant
* Clean
* Calm
* Feminine but not too pink
* Cocok untuk spa, mom care, baby care, dan wellness business

Hindari tampilan yang terlalu gelap, terlalu mencolok, neon, atau terlalu ramai.

---

# 2. Color Palette

Gunakan kombinasi warna berikut:

```text
Primary Brown: #7A4E2D
Dark Brown: #4A2C1A
Soft Brown: #A67C52
Gold Accent: #C9A227
Soft Gold: #E8D8A8
Cream Background: #F8F3EA
Ivory: #FFFDF7
Warm Beige: #EFE2D0
Soft Taupe: #D8C3A5
Text Dark: #2F241D
Text Muted: #7B6A5A
Success Soft: #8FAE8B
Warning Soft: #D9A441
Danger Soft: #C97A6A
Info Soft: #8BA6A9
```

Gunakan warna utama:

* Background utama: cream / ivory
* Sidebar: dark brown atau soft brown
* Accent: gold
* Button utama: brown dengan aksen gold
* Card: ivory / soft cream
* Border: soft taupe
* Badge: soft color, tidak mencolok

---

# 3. Visual Style

Gunakan elemen visual:

* Rounded corner besar
* Soft shadow
* Thin border
* Gold accent line
* Icon modern simple
* Card layout premium
* Subtle gradient brown-gold
* Banyak whitespace
* Typography bersih dan elegan
* Skeleton loading lembut
* Smooth hover state
* Mobile friendly layout

Contoh style card:

```text
Background: Ivory / Soft Cream
Border: 1px solid Soft Taupe
Shadow: soft shadow
Radius: 16px - 24px
Accent: thin gold line di atas card atau icon gold
```

---

# 4. Typography

Gunakan font modern dan elegan.

Rekomendasi:

* Heading: Playfair Display / Cormorant Garamond / Libre Baskerville
* Body: Inter / Manrope / Lato / Nunito Sans

Jika memakai Tailwind default, gunakan:

* Heading: font-semibold atau font-serif elegant
* Body: clean sans-serif

Judul halaman harus terlihat premium tetapi tetap mudah dibaca.

---

# 5. Icon Style

Gunakan icon di seluruh menu dan komponen.

Rekomendasi icon:

* Lucide Icons
* Heroicons
* Tabler Icons
* Phosphor Icons

Gunakan icon untuk:

* Sidebar menu
* KPI card
* Status badge
* Button action
* Empty state
* AI recommendation
* WhatsApp connection
* Booking status
* Customer status
* Campaign status
* Approval action

Icon harus:

* Simple line icon
* Tidak terlalu tebal
* Warna brown/gold/soft muted
* Konsisten ukuran

Contoh icon mapping:

```text
Dashboard: LayoutDashboard
Customers: Users
WhatsApp Inbox: MessageCircle
Bookings: CalendarCheck
Follow-Up: PhoneForwarded
Customer Retention: HeartHandshake
Promo: BadgePercent
Campaign: Megaphone
AI Management: Bot
Knowledge Base: BookOpen
AI Data Automation: Workflow
Reports: BarChart3
Settings: Settings
WhatsApp Gateway: QrCode
```

---

# 6. Skeleton Loading

Tambahkan skeleton loading di semua halaman yang memuat data.

Skeleton wajib ada untuk:

* Dashboard KPI card
* Chart loading
* Customer table
* Conversation list
* Chat messages
* Customer sidebar
* Booking calendar
* Booking table
* Follow-up table
* Campaign list
* AI logs
* Knowledge base list
* Pending approval list

Style skeleton:

```text
Background: Soft Taupe / Warm Beige
Animation: pulse
Radius: rounded
Opacity: soft
```

Contoh UX skeleton:

* Saat dashboard loading, tampilkan skeleton card grid.
* Saat inbox loading, tampilkan skeleton list conversation dan skeleton bubble chat.
* Saat table loading, tampilkan skeleton row.
* Saat calendar loading, tampilkan skeleton calendar block.

---

# 7. Mobile Friendly Requirement

Aplikasi harus mobile friendly dan responsive.

## Desktop

* Sidebar fixed di kiri.
* Topbar fixed.
* Dashboard menggunakan grid card.
* Inbox menggunakan 3 kolom.
* Customer detail menggunakan tab.

## Tablet

* Sidebar collapse.
* Inbox menjadi 2 kolom.
* Customer profile menjadi drawer kanan.
* Card grid 2 kolom.

## Mobile

* Sidebar menjadi drawer.
* Topbar compact.
* Tabel berubah menjadi card list.
* Inbox flow:

  1. Conversation list
  2. Chat detail
  3. Customer info sebagai bottom sheet
* Button action sticky di bawah jika penting.
* Form full width.
* Calendar menjadi agenda list.
* Campaign builder menjadi step-by-step vertical.
* KPI card menjadi 1 kolom atau 2 kolom kecil.

Pastikan semua button mudah ditekan di layar mobile.

Minimum touch target:

```text
44px height
```

---

# 8. UI Components Theme

Buat komponen dengan tema Brown Gold Classic Modern.

## Button

Primary button:

```text
Background: Primary Brown
Text: Ivory
Hover: Dark Brown
Accent: Gold border atau gold icon
Radius: rounded-xl
```

Secondary button:

```text
Background: Soft Gold / Cream
Text: Dark Brown
Border: Soft Taupe
```

Danger button:

```text
Background: Soft Red
Text: White / Dark Brown
```

## Badge

Badge harus soft:

```text
Booked: Soft Gold
Completed: Soft Green
Cancelled: Soft Red
Need Follow-Up: Soft Orange
AI Active: Soft Brown / Gold
Human Takeover: Taupe
Hot Lead: Gold
```

## Card

```text
Background: Ivory
Border: Soft Taupe
Shadow: soft
Radius: rounded-2xl
```

## Input

```text
Background: White / Ivory
Border: Soft Taupe
Focus border: Gold
Focus ring: Soft Gold
Radius: rounded-xl
```

## Modal

```text
Background: Ivory
Header accent: Gold line
Rounded: 2xl
Overlay: Brown transparent
```

---

# 9. Specific Page Styling

## Login Page

Gunakan tampilan premium:

* Background cream gradient
* Login card ivory
* Accent gold
* Logo Gayatri
* Ornamen halus berbentuk floral / curved line
* Ilustrasi soft mom & baby optional

## Dashboard

Gunakan KPI card dengan:

* Icon gold dalam circle soft brown
* Thin gold accent line
* Chart card ivory
* Background cream

## WhatsApp Inbox

Gunakan:

* Conversation list card ivory
* Active conversation berwarna soft gold
* Chat bubble incoming: warm beige
* Chat bubble outgoing admin: soft brown
* Chat bubble AI: soft gold
* System note: soft taupe
* Customer sidebar: ivory card

## Booking Calendar

Gunakan warna status lembut:

* Confirmed: gold
* Scheduled: soft brown
* Completed: soft green
* Cancelled: soft red
* Rescheduled: soft orange

## AI Management

Gunakan nuansa smart-premium:

* Icon AI warna gold
* Card knowledge base ivory
* Guardrail alert soft warning
* Confidence badge brown-gold
* Approval card dengan border gold

---

# 10. Animation dan Interaction

Tambahkan interaksi halus:

* Hover card naik sedikit
* Button transition smooth
* Sidebar collapse animation
* Skeleton pulse
* Modal fade in
* Drawer slide
* Toast notification
* Badge transition

Jangan berlebihan. Animasi harus lembut dan profesional.

---

# 11. Empty State dengan Icon

Setiap empty state harus memiliki icon.

Contoh:

```text
Belum ada customer.
Customer baru akan otomatis muncul saat ada chat WhatsApp masuk.
[Icon: Users]
```

```text
WhatsApp belum terhubung.
Klik Start Session lalu scan QR Code.
[Icon: QrCode]
```

```text
Belum ada approval AI.
Semua data otomatis dari AI akan muncul di sini.
[Icon: Workflow]
```

---

# 12. Final Instruction untuk AI Agent

Bangun UI/UX frontend aplikasi CRM Gayatri Mom & Baby SPA dengan tema **Brown Gold Classic Modern**.

Pastikan:

1. Semua halaman menggunakan warna brown, gold, cream, ivory, dan soft neutral.
2. Semua menu memiliki icon.
3. Semua data loading memiliki skeleton loading.
4. Semua halaman responsive dan mobile friendly.
5. Tabel berubah menjadi card list di mobile.
6. Inbox nyaman digunakan di desktop dan mobile.
7. Tampilan terasa premium, soft, modern, dan cocok untuk Mom & Baby SPA.
8. Komponen dibuat reusable.
9. Gunakan microcopy Bahasa Indonesia.
10. Jangan menggunakan warna neon atau style terlalu corporate.
