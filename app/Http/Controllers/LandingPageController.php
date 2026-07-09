<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\AvailabilitySlot;
use App\Models\Customer;
use App\Models\LandingPageSetting;
use App\Models\Service;
use App\Support\AvailabilitySlotStatus;
use App\Services\CRM\BookingService;
use App\Support\BookingStatus;
use App\Support\CustomerStatus;
use App\Support\PaymentStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class LandingPageController extends Controller
{
    public function show(Request $request): View
    {
        return $this->renderLanding($request->boolean('preview'));
    }

    public function bookingPage(Request $request): View
    {
        $page = LandingPageSetting::query()->where('slug', 'home')->first();

        return view('landing.booking', [
            'services' => Service::query()->with(['activeAddOns.addonService'])->where('is_active', true)->orderBy('name')->get(),
            'branches' => Branch::query()->orderBy('name')->get(),
            'availabilitySlots' => $this->availableBookingSlots(),
            'bookingContent' => $this->bookingPageContent($page),
            'booking' => $this->pendingSessionBooking($request),
        ]);
    }

    public function booking(Request $request, BookingService $bookingService): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'whatsapp_number' => ['required', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'baby_name' => ['nullable', 'string', 'max:150'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'service_id' => ['required', 'exists:services,id'],
            'availability_slot_id' => ['required', 'exists:availability_slots,id'],
            'booking_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'addons' => ['nullable', 'array'],
            'addons.*' => ['integer', 'exists:service_addons,id'],
        ]);
        try {
            $slot = $this->validatedAvailableSlot($data);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['booking' => $exception->getMessage()])->withInput();
        }
        $data['booking_date'] = $slot->slot_date->toDateString();
        $data['start_time'] = substr((string) $slot->start_time, 0, 5);
        $data['branch_id'] = $slot->branch_id ?: ($data['branch_id'] ?: null);

        $rawWhatsappNumber = $data['whatsapp_number'];
        $phone = preg_replace('/\D+/', '', $rawWhatsappNumber);
        if (is_string($phone) && str_starts_with($phone, '0')) {
            $phone = '62'.substr($phone, 1);
        }

        $customer = Customer::updateOrCreate(
            ['whatsapp_number' => $phone ?: $data['phone']],
            [
                'branch_id' => $data['branch_id'] ?: null,
                'name' => $data['name'],
                'phone' => $rawWhatsappNumber,
                'email' => $data['email'] ?? null,
                'baby_name' => $data['baby_name'] ?? null,
                'status' => CustomerStatus::LEAD,
                'last_interaction_at' => now(),
                'notes' => trim('Lead dari landing page booking online.'.PHP_EOL.($data['notes'] ?? '')),
            ]
        );

        try {
            $booking = $bookingService->create([
                'customer_id' => $customer->id,
                'branch_id' => $data['branch_id'] ?: null,
                'service_id' => $data['service_id'],
                'availability_slot_id' => $slot->id,
                'booking_date' => $data['booking_date'],
                'start_time' => $data['start_time'],
                'status' => BookingStatus::PENDING_CONFIRMATION,
                'payment_status' => PaymentStatus::UNPAID,
                'source' => 'landing_page',
                'notes' => $data['notes'] ?? null,
                'addons' => $data['addons'] ?? [],
            ], null);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['booking' => $exception->getMessage()])->withInput();
        }

        $request->session()->put('landing_booking_id', $booking->id);

        return redirect()->route('landing.booking-page')->with('booking_status', 'Request booking diterima. Admin Gayatri akan konfirmasi via WhatsApp. Selama belum dikonfirmasi, Bunda masih bisa mengubah jadwal di halaman ini.');
    }

    public function reschedule(Request $request, BookingService $bookingService): RedirectResponse
    {
        $booking = $this->pendingSessionBooking($request);

        if (! $booking || $booking->status !== BookingStatus::PENDING_CONFIRMATION) {
            return redirect()->route('landing.booking-page')->withErrors(['booking' => 'Request booking tidak ditemukan atau sudah dikonfirmasi admin. Silakan buat request baru jika perlu.']);
        }

        $data = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'service_id' => ['required', 'exists:services,id'],
            'availability_slot_id' => ['required', 'exists:availability_slots,id'],
            'booking_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'addons' => ['nullable', 'array'],
            'addons.*' => ['integer', 'exists:service_addons,id'],
        ]);
        try {
            $slot = $this->validatedAvailableSlot($data, $booking);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['booking' => $exception->getMessage()])->withInput();
        }
        $data['booking_date'] = $slot->slot_date->toDateString();
        $data['start_time'] = substr((string) $slot->start_time, 0, 5);
        $data['branch_id'] = $slot->branch_id ?: ($data['branch_id'] ?: null);

        try {
            $bookingService->update($booking, [
                'customer_id' => $booking->customer_id,
                'branch_id' => $data['branch_id'] ?: null,
                'service_id' => $data['service_id'],
                'therapist_id' => $booking->therapist_id,
                'promo_id' => $booking->promo_id,
                'availability_slot_id' => $slot->id,
                'booking_date' => $data['booking_date'],
                'start_time' => $data['start_time'],
                'status' => BookingStatus::PENDING_CONFIRMATION,
                'payment_status' => $booking->payment_status,
                'notes' => trim(($booking->notes ? $booking->notes.PHP_EOL : '').'Perubahan jadwal dari landing page: '.($data['notes'] ?? '-')),
                'addons' => $data['addons'] ?? [],
            ]);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['booking' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('landing.booking-page')->with('booking_status', 'Jadwal berhasil diperbarui. Admin akan melihat perubahan terbaru dan mengonfirmasi via WhatsApp.');
    }

    public function renderLanding(bool $includeDraft = false): View
    {
        $page = LandingPageSetting::query()
            ->with(['sections' => fn ($query) => $query->when(! $includeDraft, fn ($inner) => $inner->where('is_active', true)), 'media' => fn ($query) => $query->where('is_active', true)])
            ->where('slug', 'home')
            ->when(! $includeDraft, fn ($query) => $query->where('is_published', true))
            ->firstOrFail();

        return view('landing.show', [
            'page' => $page,
            'sections' => $page->sections,
            'services' => Service::query()->where('is_active', true)->orderBy('name')->get(),
            'branches' => Branch::query()->orderBy('name')->get(),
        ]);
    }

    private function pendingSessionBooking(Request $request): ?Booking
    {
        $bookingId = $request->session()->get('landing_booking_id');
        if (! $bookingId) {
            return null;
        }

        $booking = Booking::query()
            ->with(['customer', 'service', 'branch', 'addOns'])
            ->whereKey($bookingId)
            ->first();

        if (! $booking || $booking->status !== BookingStatus::PENDING_CONFIRMATION) {
            return $booking;
        }

        return $booking;
    }

    private function bookingPageContent(?LandingPageSetting $page): array
    {
        $defaults = [
            'meta_title' => 'Booking Online - Gayatri Mom & Baby SPA',
            'meta_description' => 'Booking online Gayatri Mom & Baby SPA. Pilih layanan, tanggal, dan jam, lalu admin akan mengonfirmasi melalui WhatsApp.',
            'nav_back_label' => 'Kembali ke Landing Page',
            'eyebrow' => 'Booking Online',
            'headline' => 'Reservasi treatment lembut untuk Bunda dan si kecil.',
            'lead' => 'Pilih layanan, tanggal, dan jam favorit. Tim Gayatri akan mengecek slot lalu mengirim konfirmasi melalui WhatsApp.',
            'hero_badge_1_title' => 'Pending dulu',
            'hero_badge_1_text' => 'Request masuk CRM dengan status menunggu konfirmasi.',
            'hero_badge_2_title' => 'Bisa ubah jadwal',
            'hero_badge_2_text' => 'Selama admin belum approve, jadwal bisa diubah dari session ini.',
            'hero_badge_3_title' => 'Konfirmasi WA',
            'hero_badge_3_text' => 'Admin akan menghubungi nomor WhatsApp yang Bunda isi.',
            'status_badge' => 'Status Booking',
            'empty_status_title' => 'Belum Ada Request Aktif',
            'empty_status_text' => 'Isi form booking. Setelah submit, detail request akan muncul di sini selama session browser masih aktif.',
            'pending_status_title' => 'Menunggu Konfirmasi Admin',
            'processed_status_title' => 'Sudah Diproses Admin',
            'pending_note' => 'Bunda masih bisa mengubah layanan, tanggal, atau jam sebelum admin mengonfirmasi.',
            'processed_note' => 'Booking sudah diproses admin, perubahan jadwal perlu melalui WhatsApp/admin.',
            'step_1_title' => 'Isi nomor WhatsApp',
            'step_1_text' => 'Nomor ini disimpan ke CRM karena WAHA tidak selalu bisa mengambil nomor yang sesuai otomatis.',
            'step_2_title' => 'Pilih jadwal',
            'step_2_text' => 'Pilih layanan, cabang, tanggal, dan jam treatment.',
            'step_3_title' => 'Admin cek slot',
            'step_3_text' => 'Status awal pending_confirmation.',
            'step_4_title' => 'Konfirmasi',
            'step_4_text' => 'Admin menghubungi via WhatsApp yang Bunda isi.',
            'reschedule_eyebrow' => 'Ubah Jadwal',
            'reschedule_title' => 'Update request sebelum dikonfirmasi',
            'reschedule_button' => 'Simpan Perubahan Jadwal',
            'form_eyebrow' => 'Form Booking',
            'form_title' => 'Buat request booking baru',
            'submit_button' => 'Kirim Request Booking',
            'footer_note' => 'Gayatri Mom & Baby SPA akan menjaga data booking Bunda hanya untuk kebutuhan reservasi dan follow-up layanan.',
            'hero_image_url' => 'https://images.unsplash.com/photo-1544161515-4ab6ce6db874?auto=format&fit=crop&w=1200&q=80',
            'floating_1' => 'Private room',
            'floating_2' => 'Certified therapist',
            'floating_3' => 'Soft gold ambience',
        ];

        return array_replace($defaults, data_get($page?->page_settings ?? [], 'booking_page', []));
    }

    private function availableBookingSlots()
    {
        return AvailabilitySlot::query()
            ->with(['service', 'branch', 'therapist'])
            ->where('status', AvailabilitySlotStatus::AVAILABLE)
            ->whereColumn('booked_count', '<', 'capacity')
            ->whereDate('slot_date', '>=', now()->toDateString())
            ->whereHas('service', fn ($query) => $query->where('is_active', true))
            ->orderBy('slot_date')
            ->orderBy('start_time')
            ->get();
    }

    private function validatedAvailableSlot(array $data, ?Booking $currentBooking = null): AvailabilitySlot
    {
        $slot = AvailabilitySlot::query()
            ->whereKey($data['availability_slot_id'])
            ->where('status', AvailabilitySlotStatus::AVAILABLE)
            ->whereDate('slot_date', '>=', now()->toDateString())
            ->first();

        if (! $slot) {
            throw new InvalidArgumentException('Slot jadwal tidak tersedia. Silakan pilih tanggal dan jam dari jadwal yang tersedia.');
        }

        if ($slot->service_id !== (int) $data['service_id']) {
            throw new InvalidArgumentException('Slot jadwal tidak sesuai dengan layanan yang dipilih.');
        }

        if (! empty($data['branch_id']) && $slot->branch_id !== null && $slot->branch_id !== (int) $data['branch_id']) {
            throw new InvalidArgumentException('Slot jadwal tidak sesuai dengan cabang yang dipilih.');
        }

        $sameSlot = $currentBooking && $currentBooking->availability_slot_id === $slot->id;
        if (($slot->capacity - $slot->booked_count) <= 0 && ! $sameSlot) {
            throw new InvalidArgumentException('Slot jadwal sudah penuh.');
        }

        return $slot;
    }
}
