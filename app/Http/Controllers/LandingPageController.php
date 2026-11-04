<?php

namespace App\Http\Controllers;

use App\Models\AvailabilitySlot;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\LandingPageSetting;
use App\Models\RentalProduct;
use App\Models\Service;
use App\Services\CRM\BookingService;
use App\Services\CRM\HomeService;
use App\Services\CRM\RentalSettings;
use App\Services\Web\LandingContent;
use App\Services\Web\LandingPresentation;
use App\Services\Web\PublicCatalogue;
use App\Services\Web\PublicSeo;
use App\Support\AvailabilitySlotStatus;
use App\Support\BookingStatus;
use App\Support\CustomerPhone;
use App\Support\CustomerStatus;
use App\Support\PaymentStatus;
use App\Support\ServiceGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class LandingPageController extends Controller
{
    public function show(Request $request): View|Response
    {
        if ($request->boolean('preview')) {
            abort_unless($request->user()?->status === 'active' && $request->user()->hasRole(['owner', 'manager', 'admin']), 403);

            return response($this->renderLanding(true))->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return $this->renderLanding($request->boolean('preview'));
    }

    public function bookingPage(Request $request): View
    {
        $page = LandingPageSetting::query()->where('slug', 'home')->first();
        $serviceGroup = $request->input('service_group', ServiceGroup::SPA);
        abort_unless(is_string($serviceGroup) && array_key_exists($serviceGroup, ServiceGroup::LABELS), 404);
        $booking = $this->pendingSessionBooking($request, $serviceGroup);
        $content = $this->bookingPageContent($page);
        if ($serviceGroup === ServiceGroup::LACTATION) {
            $content = array_replace($content, [
                'meta_title' => 'Laktasi & Konsultasi ASI - Gayatri',
                'meta_description' => 'Buat janji layanan laktasi dan konsultasi ASI Gayatri. Pilih layanan serta jadwal yang tersedia.',
                'eyebrow' => 'GAYATRI Â· LAKTASI & KONSULTASI ASI',
                'headline' => 'Pendampingan menyusui untuk Bunda.',
                'lead' => 'Pilih layanan laktasi atau konsultasi ASI pada halaman khusus ini. Tim Gayatri akan mengonfirmasi jadwal dan kebutuhan kunjungan melalui WhatsApp.',
                'hero_badge_1_title' => 'Layanan khusus', 'hero_badge_1_text' => 'Pilihan laktasi dan konsultasi ASI.',
                'hero_badge_2_title' => 'Pilih jadwal', 'hero_badge_2_text' => 'Sesuai ketersediaan layanan.',
                'hero_badge_3_title' => 'Konfirmasi admin', 'hero_badge_3_text' => 'Tim menghubungi melalui WhatsApp.',
                'form_title' => 'Booking Laktasi & Konsultasi ASI',
                'submit_button' => 'Kirim Request Konsultasi',
                'step_1_text' => 'Isi nomor aktif untuk konfirmasi janji layanan.',
                'step_2_text' => 'Pilih layanan laktasi atau konsultasi ASI dan jadwalnya.',
                'step_3_text' => 'Tim memeriksa ketersediaan jadwal.',
                'footer_note' => 'Gayatri menjaga data pemesanan untuk kebutuhan jadwal dan tindak lanjut layanan.',
            ]);
        }

        return view('landing.booking', [
            'serviceGroup' => $serviceGroup,
            'services' => PublicCatalogue::bookableServices()->with(['activeAddOns.addonService'])->where('service_group', $serviceGroup)->orderBy('name')->get(),
            'branches' => PublicCatalogue::branches()->orderBy('name')->get(),
            'availabilitySlots' => $this->availableBookingSlots($booking, $serviceGroup),
            'bookingContent' => $content,
            'booking' => $booking,
            'homeSettings' => app(HomeService::class)->settings(),
        ]);
    }

    public function booking(Request $request, BookingService $bookingService): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'service_group' => ['nullable', Rule::in(array_keys(ServiceGroup::LABELS))],
            ...HomeService::rules(),
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
        $hash = hash('sha256', json_encode($data));
        $previous = $request->session()->get('landing_request', []);
        $booking = ($previous['hash'] ?? '') === $hash && ($previous['until'] ?? 0) > now()->timestamp
            ? Booking::whereKey($previous['id'] ?? 0)->where('source', 'landing_page')->first() : null;
        try {
            if (! $booking) {
                $slot = $this->validatedAvailableSlot($data);
                if (($data['service_mode'] ?? 'clinic') === 'home') {
                    app(HomeService::class)->attributes(array_merge($data, ['therapist_id' => $slot->therapist_id]), Service::findOrFail($data['service_id']));
                }
                $data['booking_date'] = $slot->slot_date->toDateString();
                $data['start_time'] = substr((string) $slot->start_time, 0, 5);
                $data['branch_id'] = $slot->branch_id ?: ($data['branch_id'] ?? null);
                $phone = CustomerPhone::normalize($data['whatsapp_number']);
                $booking = DB::transaction(function () use ($data, $phone, $slot, $bookingService) {
                    $matches = CustomerPhone::matching($phone)->lockForUpdate()->limit(2)->get();
                    if ($matches->count() > 1) {
                        throw new InvalidArgumentException('Permintaan perlu dikonfirmasi langsung kepada admin.');
                    }
                    $customer = $matches->first();
                    if ($customer && in_array($customer->status, ['blacklisted', 'blocked'], true)) {
                        throw new InvalidArgumentException('Permintaan perlu dikonfirmasi langsung kepada admin.');
                    }
                    $customer ??= Customer::create(['whatsapp_number' => $phone, 'branch_id' => $data['branch_id'] ?: null,
                        'name' => $data['name'], 'phone' => $data['whatsapp_number'], 'email' => $data['email'] ?? null,
                        'baby_name' => $data['baby_name'] ?? null, 'status' => CustomerStatus::LEAD,
                        'notes' => 'Lead baru dari formulir publik; kontak belum diverifikasi admin.']);
                    $booking = $bookingService->create(array_merge(Arr::only($data, array_keys(HomeService::rules())), [
                        'customer_id' => $customer->id, 'branch_id' => $data['branch_id'] ?: null, 'service_id' => $data['service_id'],
                        'availability_slot_id' => $slot->id, 'booking_date' => $data['booking_date'], 'start_time' => $data['start_time'],
                        'status' => BookingStatus::PENDING_CONFIRMATION, 'payment_status' => PaymentStatus::UNPAID,
                        'source' => 'landing_page', 'notes' => $data['notes'] ?? null, 'addons' => $data['addons'] ?? [],
                    ]), null);
                    $booking->forceFill(['public_request_data' => Arr::only($data, ['name', 'whatsapp_number', 'email', 'baby_name', 'notes'])])->save();

                    return $booking;
                });
                $request->session()->put('landing_request', ['hash' => $hash, 'id' => $booking->id, 'until' => now()->addMinutes(15)->timestamp]);
            }
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['booking' => $exception->getMessage()])->withInput();
        }

        $request->session()->put('landing_booking_id', $booking->id);
        $request->session()->put('landing_booking_ids.'.$booking->service->service_group, $booking->id);

        $message = $booking->status === BookingStatus::PENDING_CONFIRMATION
            ? 'Permintaan diterima. Admin Gayatri akan mengonfirmasi melalui WhatsApp. Bunda dapat mengubah jadwal di halaman ini selama menunggu.'
            : 'Permintaan ini sudah tercatat. Lihat status terbaru pada ringkasan booking.';

        return redirect()->route('landing.booking-page', $booking->service->service_group === ServiceGroup::LACTATION ? ['service_group' => ServiceGroup::LACTATION] : [])->with('booking_status', $message);
    }

    public function reschedule(Request $request, BookingService $bookingService): RedirectResponse
    {
        $request->validate(['service_group' => ['nullable', Rule::in(array_keys(ServiceGroup::LABELS))]]);
        $booking = $this->pendingSessionBooking($request, $request->input('service_group'));

        if (! $booking || $booking->status !== BookingStatus::PENDING_CONFIRMATION) {
            return redirect()->route('landing.booking-page')->withErrors(['booking' => 'Request booking tidak ditemukan atau sudah dikonfirmasi admin. Silakan buat request baru jika perlu.']);
        }

        $data = $request->validate([
            'service_group' => ['nullable', Rule::in(array_keys(ServiceGroup::LABELS))],
            ...HomeService::rules(),
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
            $bookingService->update($booking, array_merge(Arr::only($data, array_keys(HomeService::rules())), [
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
            ]));
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['booking' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('landing.booking-page', $booking->service->service_group === ServiceGroup::LACTATION ? ['service_group' => ServiceGroup::LACTATION] : [])->with('booking_status', 'Jadwal berhasil diperbarui. Admin akan melihat perubahan terbaru dan mengonfirmasi via WhatsApp.');
    }

    public function renderLanding(bool $includeDraft = false): View
    {
        $page = LandingPageSetting::query()
            ->with(['sections' => fn ($query) => $query->where('is_active', true), 'media' => fn ($query) => $query->where('is_active', true)])
            ->where('slug', 'home')
            ->when(! $includeDraft, fn ($query) => $query->where('is_published', true))
            ->firstOrFail();

        $rentalSettings = app(RentalSettings::class)->get();
        $services = PublicCatalogue::services()->orderBy('name')->get();
        $landing = app(LandingPresentation::class)->forPage($page);

        return view('landing.show', [
            'page' => $page,
            'sections' => $page->sections,
            'rentalProducts' => RentalProduct::query()->where('is_active', true)->whereRaw('UPPER(TRIM(name)) NOT LIKE ?', ['[DEMO]%'])->orderBy('name')->limit(3)->get(),
            'rentalSettings' => $rentalSettings,
            'landing' => $landing,
            'meta' => PublicSeo::home($page, $landing['contact'], $includeDraft),
            'services' => $services,
            'featuredServices' => app(LandingContent::class)->featured($page, $services),
        ]);
    }

    private function pendingSessionBooking(Request $request, ?string $serviceGroup = null): ?Booking
    {
        $bookingId = ($serviceGroup ? $request->session()->get('landing_booking_ids.'.$serviceGroup) : null) ?: $request->session()->get('landing_booking_id');
        if (! $bookingId) {
            return null;
        }

        $booking = Booking::query()
            ->with(['customer', 'service', 'branch', 'addOns'])
            ->whereKey($bookingId)
            ->first();

        if ($booking && $serviceGroup && $booking->service?->service_group !== $serviceGroup) {
            return null;
        }

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
            'hero_badge_1_text' => 'Permintaan diterima untuk diperiksa admin.',
            'hero_badge_2_title' => 'Bisa ubah jadwal',
            'hero_badge_2_text' => 'Selama admin belum approve, jadwal bisa diubah dari session ini.',
            'hero_badge_3_title' => 'Konfirmasi WA',
            'hero_badge_3_text' => 'Admin akan menghubungi nomor WhatsApp yang Bunda isi.',
            'status_badge' => 'Status Booking',
            'empty_status_title' => 'Cara pemesanan',
            'empty_status_text' => 'Lengkapi formulir, lalu tunggu konfirmasi dari tim Gayatri. Rincian permintaan akan tampil setelah dikirim.',
            'pending_status_title' => 'Menunggu Konfirmasi Admin',
            'processed_status_title' => 'Sudah Diproses Admin',
            'pending_note' => 'Bunda masih bisa mengubah layanan, tanggal, atau jam sebelum admin mengonfirmasi.',
            'processed_note' => 'Booking sudah diproses admin, perubahan jadwal perlu melalui WhatsApp/admin.',
            'step_1_title' => 'Isi nomor WhatsApp',
            'step_1_text' => 'Gunakan nomor WhatsApp aktif agar admin dapat mengonfirmasi permintaan dan membantu pengecekan status.',
            'step_2_title' => 'Pilih jadwal',
            'step_2_text' => 'Pilih layanan, cabang, tanggal, dan jam treatment.',
            'step_3_title' => 'Admin cek slot',
            'step_3_text' => 'Permintaan menunggu pemeriksaan dan konfirmasi admin.',
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

    private function availableBookingSlots(?Booking $booking = null, ?string $serviceGroup = null)
    {
        return AvailabilitySlot::query()
            ->with(['service', 'branch', 'therapist'])
            ->where(function ($query) use ($booking) {
                $query->where(fn ($available) => $available->where('status', AvailabilitySlotStatus::AVAILABLE)->whereColumn('booked_count', '<', 'capacity'));
                if ($booking?->status === BookingStatus::PENDING_CONFIRMATION) {
                    $query->orWhere(fn ($held) => $held->whereKey($booking->availability_slot_id)->where('status', '!=', AvailabilitySlotStatus::BLOCKED));
                }
            })
            ->whereDate('slot_date', '>=', now()->toDateString())
            ->whereIn('service_id', PublicCatalogue::bookableServices()->select('id'))
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhereIn('branch_id', PublicCatalogue::branches()->select('id')))
            ->whereHas('service', fn ($query) => $query->where('is_active', true)->when($serviceGroup, fn ($query) => $query->where('service_group', $serviceGroup)))
            ->orderBy('slot_date')
            ->orderBy('start_time')
            ->get();
    }

    private function validatedAvailableSlot(array $data, ?Booking $currentBooking = null): AvailabilitySlot
    {
        $slot = AvailabilitySlot::query()
            ->whereKey($data['availability_slot_id'])
            ->where(function ($query) use ($currentBooking) {
                $query->where('status', AvailabilitySlotStatus::AVAILABLE);
                if ($currentBooking) {
                    $query->orWhere(fn ($held) => $held->whereKey($currentBooking->availability_slot_id)->where('status', '!=', AvailabilitySlotStatus::BLOCKED));
                }
            })
            ->whereDate('slot_date', '>=', now()->toDateString())
            ->first();

        if (! $slot) {
            throw new InvalidArgumentException('Slot jadwal tidak tersedia. Silakan pilih tanggal dan jam dari jadwal yang tersedia.');
        }

        if (! PublicCatalogue::bookableServices()->whereKey($data['service_id'])->exists()
            || ($slot->branch_id && ! PublicCatalogue::branches()->whereKey($slot->branch_id)->exists())
            || (! empty($data['branch_id']) && ! PublicCatalogue::branches()->whereKey($data['branch_id'])->exists())) {
            throw new InvalidArgumentException('Layanan atau cabang tidak tersedia untuk pemesanan publik.');
        }

        if ($slot->service_id !== (int) $data['service_id']) {
            throw new InvalidArgumentException('Slot jadwal tidak sesuai dengan layanan yang dipilih.');
        }

        if (! empty($data['service_group']) && $slot->service?->service_group !== $data['service_group']) {
            throw new InvalidArgumentException('Layanan tidak sesuai kelompok yang dipilih. Gunakan halaman booking kelompok layanan yang sesuai.');
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
