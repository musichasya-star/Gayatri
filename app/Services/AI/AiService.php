<?php

namespace App\Services\AI;

use App\Models\AiLog;
use App\Models\AiPersona;
use App\Models\AvailabilitySlot;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Conversation;
use App\Models\KnowledgeBase;
use App\Models\Message;
use App\Models\Promo;
use App\Models\Service;
use App\Support\BookingStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class AiService
{
    public function __construct(
        private readonly KnowledgeRetrievalService $knowledgeRetrieval,
        private readonly AiGuardrailService $guardrail,
    ) {}

    public function simulate(string $message, ?AiPersona $persona = null): array
    {
        $persona ??= AiPersona::where('is_active', true)->first();

        return $this->generate($message, $persona, ['mode' => 'simulator']);
    }

    public function generateReply(Conversation $conversation, Message $message): array
    {
        return $this->generate((string) $message->content, AiPersona::where('is_active', true)->first(), [
            'mode' => 'auto_reply',
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'customer_id' => $conversation->customer_id,
        ]);
    }

    private function generate(string $message, ?AiPersona $persona, array $context): array
    {
        try {
            $knowledge = $this->knowledgeRetrieval->retrieve($message);
            $text = Str::lower($message);
            $extraction = $this->extractionForContext($message, $context);
            $bookingContext = $this->bookingContextForMessage($context['message_id'] ?? null) ?: $this->bookingContext($message);
            $promoIntent = Str::contains($text, ['promo', 'diskon', 'voucher', 'voucer']);
            $conversationalIntent = $this->isConversationalMessage($message);
            $bookingLookupIntent = ($extraction['intent'] ?? null) === 'booking_lookup_request';
            $bookingIntent = Str::contains($text, ['booking', 'reservasi', 'pesan jadwal', 'mau daftar', 'pesan baby spa', 'pesan treatment', 'pesan layanan', 'batalkan', 'batal booking', 'batal reservasi', 'cancel booking']) || $bookingContext !== [] || $bookingLookupIntent;
            $scheduleIntent = Str::contains($text, ['ready', 'tersedia', 'kosong', 'ada jadwal', 'hari apa', 'kapan bisa', 'jadwal']);
            $serviceInquiryIntent = Str::contains($text, ['layanan', 'treatment', 'jasa', 'paket', 'baby spa', 'mom massage', 'massage', 'pijat', 'spa bayi']);
            $operationalIntent = $this->isOperationalInquiry($message);
            $guardrail = $this->guardrail->check($message, $knowledge->isNotEmpty() || $promoIntent || $conversationalIntent || $bookingIntent || $scheduleIntent || $serviceInquiryIntent || $operationalIntent);

            if (! $persona) {
                return $this->logAndReturn($message, null, $knowledge->first(), 'Mohon maaf Bunda, AI Gayatri belum dikonfigurasi. Saya bantu teruskan ke admin ya.', 0.0, 'escalated', 'missing_persona', $knowledge->pluck('slug')->all(), $context);
            }

            if (! $guardrail['allowed']) {
                return $this->logAndReturn($message, $persona, $knowledge->first(), $guardrail['reply'], $guardrail['confidence'], 'escalated', $guardrail['reason'], $knowledge->pluck('slug')->all(), $context);
            }

            if ($this->isThanks($message) && ! $this->isAffirmation($message) && ! $this->isRejection($message)) {
                $context['reply_source'] = 'local';

                return $this->logAndReturn($message, $persona, $knowledge->first(), $this->thanksReply(), 0.9, 'success', null, $knowledge->pluck('slug')->all(), $context);
            }

            if ($this->isRejection($message) && (! empty($bookingContext['availability_slot_id']) || ($bookingContext['action'] ?? null) === 'cancel_booking')) {
                $context['reply_source'] = 'local';
                $reply = ($bookingContext['action'] ?? null) === 'cancel_booking'
                    ? 'Baik Bunda, pembatalan tidak kami lanjutkan. Jadwal reservasi tetap tercatat di sistem.'
                    : 'Baik Bunda, reservasi tidak kami lanjutkan. Jika ingin pilih jadwal lain, Bunda bisa kirim format booking kembali ya.';

                return $this->logAndReturn($message, $persona, $knowledge->first(), $reply, 0.9, 'success', null, $knowledge->pluck('slug')->all(), $context);
            }

            if ($this->isAffirmation($message) && (! empty($bookingContext['availability_slot_id']) || ($bookingContext['action'] ?? null) === 'cancel_booking')) {
                $context['reply_source'] = 'local';
                $reply = ! empty($bookingContext['missing_fields'])
                    ? $this->bookingTemplateReply($bookingContext['missing_fields'])
                    : $this->bookingConfirmedActionReply($bookingContext);

                return $this->logAndReturn($message, $persona, $knowledge->first(), $reply, 0.9, 'success', null, $knowledge->pluck('slug')->all(), $context);
            }

            if (($bookingContext['action'] ?? null) === 'clarify_booking_or_reschedule') {
                $context['reply_source'] = 'local';

                return $this->logAndReturn($message, $persona, $knowledge->first(), $this->bookingClarificationReply($bookingContext), 0.9, 'success', null, $knowledge->pluck('slug')->all(), $context);
            }

            if ($bookingLookupIntent) {
                $context['reply_source'] = 'local';

                return $this->logAndReturn($message, $persona, $knowledge->first(), $this->bookingLookupReply($context['customer_id'] ?? null, $extraction['booking']['booking_date'] ?? null, $context['conversation_id'] ?? null), 0.92, 'success', null, $knowledge->pluck('slug')->all(), $context);
            }

            if ($promoIntent) {
                $context['reply_source'] = 'local';

                return $this->logAndReturn($message, $persona, $knowledge->first(), $this->promoReply(), 0.9, 'success', null, $knowledge->pluck('slug')->all(), $context);
            }

            if ($operationalIntent) {
                $context['reply_source'] = 'local';
                $knowledgeText = $knowledge->map(fn ($item) => trim($item->title.': '.$item->content))->implode("\n");

                return $this->logAndReturn($message, $persona, $knowledge->first(), $this->operationalHoursReply($knowledgeText), 0.9, 'success', null, $knowledge->pluck('slug')->all(), $context);
            }

            if (! empty($bookingContext['availability_slot_id']) && empty($bookingContext['missing_fields'])) {
                $context['reply_source'] = 'local';

                return $this->logAndReturn($message, $persona, $knowledge->first(), $this->bookingConfirmationReply($bookingContext), 0.9, 'success', null, $knowledge->pluck('slug')->all(), $context);
            }

            if ($scheduleIntent && ! Str::contains($text, ['booking', 'reservasi', 'pesan jadwal', 'mau daftar', 'ubah jadwal', 'ganti jadwal', 'reschedule', 'pindah jam', 'pindah tanggal', 'ganti menjadi', 'ubah menjadi', 'menjadi jam', 'jadi jam'])) {
                $context['reply_source'] = 'local';

                return $this->logAndReturn($message, $persona, $knowledge->first(), $this->buildLocalReply($message, $persona, ''), 0.88, 'success', null, $knowledge->pluck('slug')->all(), $context);
            }

            if ($serviceInquiryIntent && ! $bookingIntent && (Str::contains($text, ['pijat', 'massage', 'layanan', 'treatment', 'jasa', 'paket']) || $knowledge->isEmpty())) {
                $context['reply_source'] = 'local';

                return $this->logAndReturn($message, $persona, $knowledge->first(), $this->buildLocalReply($message, $persona, ''), 0.88, 'success', null, $knowledge->pluck('slug')->all(), $context);
            }

            if ($bookingContext !== [] && ! empty($bookingContext['missing_fields'])) {
                $context['reply_source'] = 'local';

                if ($this->isRejection($message)) {
                    return $this->logAndReturn($message, $persona, $knowledge->first(), 'Baik Bunda, proses reservasi tidak saya lanjutkan. Kalau nanti ingin booking lagi, Bunda bisa chat kami kapan saja ya.', 0.88, 'success', null, $knowledge->pluck('slug')->all(), $context);
                }

                return $this->logAndReturn($message, $persona, $knowledge->first(), $this->bookingMissingFieldsReply($bookingContext['missing_fields']), 0.88, 'success', null, $knowledge->pluck('slug')->all(), $context);
            }

            $knowledgeText = $knowledge->map(fn ($item) => trim($item->title.': '.$item->content))->implode("\n");
            $providerReply = $this->buildProviderReply($message, $persona, $knowledgeText);
            $reply = $providerReply ?: $this->buildLocalReply($message, $persona, $knowledgeText);
            $context['reply_source'] = $providerReply ? 'provider' : 'local';

            return $this->logAndReturn($message, $persona, $knowledge->first(), $reply, 0.85, 'success', null, $knowledge->pluck('slug')->all(), $context);
        } catch (Throwable) {
            return $this->logAndReturn($message, $persona, null, 'Mohon maaf Bunda, sistem  sedang mengalami kendala. Saya teruskan ke admin agar dibantu manual ya.', 0.0, 'escalated', 'provider_error', [], $context);
        }
    }

    private function buildLocalReply(string $message, ?AiPersona $persona, string $knowledgeText): string
    {
        $text = Str::of($message)->lower()->squish()->toString();

        if ($this->isGreeting($message)) {
            return $this->greetingReply($message).' Bunda, terima kasih sudah menghubungi Gayatri. Ada yang bisa kami bantu hari ini? Bunda bisa tanya jadwal, layanan baby spa, booking, atau promo yang sedang aktif.';
        }

        if ($this->isThanks($message)) {
            return $this->thanksReply();
        }

        if (Str::contains($text, ['booking', 'reservasi', 'pesan jadwal', 'mau daftar', 'pesan baby spa', 'pesan treatment', 'pesan layanan', 'ubah jadwal', 'ganti jadwal', 'reschedule', 'pindah jam', 'pindah tanggal', 'batalkan', 'batal booking', 'batal reservasi', 'cancel booking'])) {
            $bookingContext = $this->bookingContext($message);

            if (($bookingContext['slot_status'] ?? null) === 'full' && ! empty($bookingContext['alternative_slots'])) {
                return 'Slot yang Bunda pilih sudah penuh. Alternatif yang masih tersedia: '.$this->formatAlternativeSlots($bookingContext['alternative_slots']).'. Bunda mau pilih jam yang mana?';
            }

            if (! empty($bookingContext['availability_slot_id'])) {
                if (! empty($bookingContext['missing_fields'])) {
                    return $this->bookingTemplateReply($bookingContext['missing_fields']);
                }

                return $this->bookingConfirmationReply($bookingContext);
            }

            if (! empty($bookingContext['missing_fields'])) {
                return $this->bookingTemplateReply($bookingContext['missing_fields']);
            }

            return 'Baik Bunda, saya bantu proses booking secara bertahap ya. Boleh tuliskan nama reservasi dulu?';
        }

        if (Str::contains($text, ['ready', 'tersedia', 'kosong', 'ada jadwal', 'hari apa', 'kapan bisa'])) {
            $slots = $this->availableSlotSummaries();

            if ($slots !== '') {
                return 'Slot yang tersedia terdekat: '.$slots.'. Bunda mau pilih salah satu jam tersebut?';
            }

            return $this->variant($message, [
                'Untuk saat ini belum ada slot tersedia yang tercatat di sistem. Jam operasional Gayatri 09.00-18.00; Bunda ingin cek untuk hari apa?',
                'Belum ada slot ready yang terinput, Bunda. Kami operasional 09.00-18.00; boleh sebutkan rencana harinya?',
                'Data slot belum tersedia di sistem. Jam operasional 09.00-18.00; Bunda ingin weekday atau weekend?',
            ]);
        }

        if (Str::contains($text, ['layanan', 'treatment', 'jasa', 'paket', 'baby spa', 'mom massage', 'massage', 'pijat', 'spa bayi'])) {
            $services = $this->activeServicesText();

            return $this->variant($message, [
                'Untuk layanan, yang tersedia saat ini: '.$services.'. Kalau Bunda mencari pijat, pilihan yang paling dekat biasanya Mom Massage atau Baby Spa sesuai kebutuhan. Bunda ingin untuk bayi atau untuk Bunda?',
                'Bisa Bunda. Saat ini layanan yang tersedia: '.$services.'. Bunda ingin saya bantu arahkan layanan yang cocok atau sekalian cek jadwal?',
                'Kami bisa bantu info layanan ya Bunda. Pilihan aktif saat ini: '.$services.'. Untuk pijat, Bunda bisa pilih layanan massage yang tersedia atau ceritakan kebutuhannya dulu.',
            ]);
        }

        if (Str::contains($text, ['alamat', 'lokasi', 'dimana', 'di mana', 'maps', 'map', 'cabang'])) {
            $branches = $this->activeBranchSummaries();

            if ($branches !== '') {
                return 'Alamat Gayatri: '.$branches.'. Jika Bunda ingin datang, kami bisa bantu cek jadwal dulu supaya tidak menunggu lama.';
            }

            return 'Untuk alamat cabang, datanya belum tersedia di sistem chat ini, Bunda. Boleh sebutkan area atau cabang yang ingin dituju?';
        }

        $summary = $this->summarizeKnowledge($knowledgeText);

        if (Str::contains($text, ['promo', 'diskon', 'voucher', 'voucer'])) {
            return $this->promoReply();
        }

        if ($summary !== '') {
            return $this->variant($message, [
                'Baik Bunda, ini info singkatnya: '.$summary.' Kalau ingin lanjut, Bunda bisa sebutkan layanan dan rencana harinya ya.',
                'Bisa Bunda. '.$summary.' Bunda ingin tanya detail layanan atau langsung cek jadwal?',
                'Untuk informasi Gayatri: '.$summary.' Jika ada kebutuhan khusus, boleh cerita sedikit agar kami bantu arahkan.',
            ]);
        }

        return 'Baik Bunda, boleh dijelaskan sedikit kebutuhannya? Kami bisa bantu info layanan, jadwal, booking, atau promo Gayatri.';
    }

    private function buildProviderReply(string $message, AiPersona $persona, string $knowledgeText): ?string
    {
        $provider = (string) config('ai.provider', 'local');

        if ($provider === 'local' || blank(config('ai.api_key'))) {
            return null;
        }

        $model = (string) config('ai.model');
        $messages = $this->providerMessages($message, $persona, $knowledgeText);

        try {
            $response = match ($provider) {
                'openrouter' => $this->chatCompletions('https://openrouter.ai/api/v1/chat/completions', $model, $messages),
                'openai' => $this->chatCompletions('https://api.openai.com/v1/chat/completions', $model, $messages),
                'deepseek' => $this->chatCompletions('https://api.deepseek.com/chat/completions', $model, $messages),
                'gemini' => $this->geminiGenerate($model, $messages),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }

        return $response ? $this->extractProviderReply($provider, $response) : null;
    }

    private function promoReply(): string
    {
        $promos = Promo::query()
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('start_at')->orWhere('start_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('end_at')->orWhere('end_at', '>=', now());
            })
            ->where(function ($query) {
                $query->whereNull('quota')->orWhereColumn('used_count', '<', 'quota');
            })
            ->orderBy('end_at')
            ->limit(3)
            ->get();

        $promoText = $promos->isEmpty()
            ? 'Saat ini belum ada promo aktif yang bisa kami tawarkan.'
            : 'Promo aktif saat ini: '.$promos->map(fn (Promo $promo) => trim($promo->title.($promo->code ? ' kode '.$promo->code : '').($promo->type === 'percent' ? ' diskon '.(float) $promo->value.'%' : ' potongan Rp'.number_format((float) $promo->value, 0, ',', '.'))))->implode(', ').'.';

        return trim($promoText.' Jika Bunda tertarik, kami bisa bantu cek jadwal dan layanan yang cocok ya.');
    }

    private function operationalHoursReply(string $knowledgeText): string
    {
        $line = $this->operationalHoursLine($knowledgeText) ?: $this->operationalHoursLine(
            KnowledgeBase::query()
                ->where('status', 'active')
                ->where(function ($query) {
                    $query->whereNull('valid_from')->orWhere('valid_from', '<=', now());
                })
                ->where(function ($query) {
                    $query->whereNull('valid_until')->orWhere('valid_until', '>=', now());
                })
                ->pluck('content')
                ->implode("\n")
        );

        if (! $line) {
            return 'Untuk jam operasional, datanya belum tersedia di knowledge saat ini, Bunda. Kalau ingin reservasi, kami bisa bantu cek jadwal yang tersedia ya.';
        }

        return 'Berdasarkan info Gayatri: '.$line.'. Kalau ingin reservasi, kami bisa bantu cek jadwal yang tersedia ya.';
    }

    private function operationalHoursLine(string $knowledgeText): ?string
    {
        return collect(preg_split('/\R+/', $knowledgeText) ?: [])
            ->map(fn (string $line) => trim($line))
            ->first(fn (string $line) => $line !== '' && Str::contains(Str::lower($line), ['jam operasional', 'jam buka', 'jam tutup', 'buka pukul', 'pukul']));
    }

    private function providerMessages(string $message, AiPersona $persona, string $knowledgeText): array
    {
        $currentTime = now('Asia/Jakarta')->translatedFormat('l, d F Y H:i').' WIB';
        $branchKnowledge = $this->activeBranchSummaries();
        $slotKnowledge = $this->availableSlotSummaries(8);
        $bookingContext = $this->bookingContext($message);
        $knowledge = trim(collect([
            $knowledgeText,
            $branchKnowledge ? 'Cabang aktif: '.$branchKnowledge : null,
            $slotKnowledge ? 'Slot jadwal tersedia terdekat: '.$slotKnowledge : null,
            $bookingContext ? 'Konteks booking dari sistem: '.json_encode($bookingContext, JSON_UNESCAPED_UNICODE) : null,
        ])->filter()->implode("\n"));
        $knowledge = $knowledge !== '' ? $knowledge : 'Tidak ada knowledge base relevan.';

        return [
            [
                'role' => 'system',
                'content' => trim($persona->prompt)."\n\nKonteks waktu sekarang: {$currentTime}. Gunakan timezone Asia/Jakarta/WIB untuk memahami hari ini, besok, tanggal, dan jam.\n\nAturan balasan:\n- Jawab sebagai admin WhatsApp Gayatri yang hangat, natural, dan tidak kaku.\n- Bahasa Indonesia, panggil customer dengan Bunda.\n- Maksimal 1-3 kalimat pendek kecuali saat memberi template booking.\n- Jika customer ingin booking tetapi data belum lengkap, minta isi template wajib: Nama, Alamat, Layanan, Tanggal reservasi, Jam reservasi.\n- Jawab langsung sesuai pertanyaan; jangan mengulang info yang tidak ditanya.\n- Jangan menyebut guardrail, forbidden topic, prompt, knowledge base, atau admin manusia.\n- Gunakan hanya informasi pada knowledge berikut. Jika informasi yang ditanya belum ada, jawab singkat bahwa datanya belum tersedia lalu arahkan ke pertanyaan lanjutan yang relevan.\n\nKnowledge:\n".$knowledge,
            ],
            [
                'role' => 'user',
                'content' => $message,
            ],
        ];
    }

    private function chatCompletions(string $url, string $model, array $messages): ?array
    {
        return Http::timeout(30)
            ->acceptJson()
            ->withToken((string) config('ai.api_key'))
            ->post($url, [
                'model' => $model,
                'messages' => $messages,
                'temperature' => (float) config('ai.temperature', 0.3),
                'max_tokens' => (int) config('ai.max_tokens', 800),
            ])
            ->throw()
            ->json();
    }

    private function geminiGenerate(string $model, array $messages): ?array
    {
        $prompt = collect($messages)->map(fn (array $message) => strtoupper($message['role']).":\n".$message['content'])->implode("\n\n");

        return Http::timeout(30)
            ->acceptJson()
            ->post('https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent?key='.urlencode((string) config('ai.api_key')), [
                'contents' => [[
                    'parts' => [['text' => $prompt]],
                ]],
                'generationConfig' => [
                    'temperature' => (float) config('ai.temperature', 0.3),
                    'maxOutputTokens' => (int) config('ai.max_tokens', 800),
                ],
            ])
            ->throw()
            ->json();
    }

    private function extractProviderReply(string $provider, array $response): ?string
    {
        $reply = $provider === 'gemini'
            ? data_get($response, 'candidates.0.content.parts.0.text')
            : data_get($response, 'choices.0.message.content');

        if (! is_string($reply) || trim($reply) === '') {
            return null;
        }

        return trim($reply);
    }

    private function summarizeKnowledge(string $knowledgeText): string
    {
        $lines = collect(preg_split('/\R+/', $knowledgeText) ?: [])
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->reject(fn (string $line) => Str::contains(Str::lower($line), ['demam', 'sakit', 'keluhan kesehatan', 'dokter']))
            ->take(2)
            ->implode(' ');

        return Str::limit(preg_replace('/\s+/', ' ', $lines), 220);
    }

    private function activeBranchSummaries(): string
    {
        return Branch::query()
            ->where('status', 'active')
            ->whereNotNull('address')
            ->get(['name', 'address', 'city'])
            ->map(fn (Branch $branch) => trim($branch->name.': '.$branch->address.($branch->city ? ', '.$branch->city : '')))
            ->filter()
            ->implode('; ');
    }

    private function bookingContext(string $message): array
    {
        $result = app(AiDataExtractionService::class)->extract($message);

        if (! in_array($result['intent'] ?? null, ['booking_request', 'booking_reschedule_request', 'booking_cancel_request', 'booking_clarification_required'], true)) {
            return [];
        }

        return array_filter($result['booking'] + ['missing_fields' => $result['missing_fields'] ?? []], fn ($value) => $value !== null && $value !== [] && $value !== '');
    }

    private function extractionForContext(string $message, array $context): array
    {
        if (! empty($context['message_id']) && ($model = Message::find($context['message_id']))) {
            return app(AiDataExtractionService::class)->extractForMessage($model);
        }

        return app(AiDataExtractionService::class)->extract($message);
    }

    private function bookingContextForMessage(?int $messageId): array
    {
        if (! $messageId) {
            return [];
        }

        $message = Message::find($messageId);
        if (! $message) {
            return [];
        }

        $result = app(AiDataExtractionService::class)->extractForMessage($message);

        if (! in_array($result['intent'] ?? null, ['booking_request', 'booking_reschedule_request', 'booking_cancel_request', 'booking_clarification_required'], true)) {
            return [];
        }

        return array_filter(($result['booking'] ?? []) + ['missing_fields' => $result['missing_fields'] ?? []], fn ($value) => $value !== null && $value !== [] && $value !== '');
    }

    private function humanMissingFields(array $missingFields): string
    {
        $labels = collect($missingFields)->map(fn (string $field) => match ($field) {
            'name' => 'nama Bunda',
            'address' => 'alamat lengkap',
            'service_id' => 'layanan yang diinginkan',
            'booking_date' => 'hari atau tanggal kunjungan',
            'start_time' => 'perkiraan jam',
            'availability_slot_id' => 'slot yang tersedia',
            'booking_id' => 'booking aktif',
            default => $field,
        })->values();

        return $labels->implode(', ');
    }

    private function bookingTemplateReply(array $missingFields): string
    {
        return 'Bisa Bunda. Agar booking bisa masuk ke sistem, mohon isi format ini ya:'
            ."\nNama:"
            ."\nAlamat:"
            ."\nLayanan:"
            ."\nTanggal reservasi:"
            ."\nJam reservasi:"
            ."\nData yang masih kurang: ".$this->humanMissingFields($missingFields).'.';
    }

    private function bookingMissingFieldsReply(array $missingFields): string
    {
        return 'Baik Bunda, data booking sebelumnya sudah saya catat. Tinggal lengkapi: '.$this->humanMissingFields($missingFields).'.';
    }

    private function bookingConfirmationReply(array $bookingContext): string
    {
        $isReschedule = ($bookingContext['action'] ?? null) === 'reschedule_booking';
        $isCancel = ($bookingContext['action'] ?? null) === 'cancel_booking';

        if ($isCancel) {
            return 'Baik Bunda, saya temukan reservasi yang akan dibatalkan. Mohon cek dulu detailnya ya:'
                ."\nKode booking: ".($bookingContext['booking_code'] ?? '-')
                ."\nLayanan: ".($bookingContext['service_name'] ?? '-')
                ."\nTanggal: ".($bookingContext['booking_date'] ?? '-')
                ."\nJam: ".substr((string) ($bookingContext['start_time'] ?? ''), 0, 5)
                ."\nStatus saat ini: ".($bookingContext['booking_status'] ?? '-')
                ."\n\nKalau sudah sesuai, Bunda bisa balas Iya batalkan. Kalau tidak jadi dibatalkan, balas Tidak ya.";
        }

        return ($isReschedule ? 'Siap Bunda, saya sudah catat permintaan ubah jadwalnya. Mohon cek kembali detail berikut:' : 'Siap Bunda, data reservasinya sudah lengkap. Mohon cek kembali detail berikut:')
            ."\nLayanan: ".($bookingContext['service_name'] ?? '-')
            .($isReschedule ? "\nJadwal lama: ".($bookingContext['current_booking_date'] ?? '-').' '.substr((string) ($bookingContext['current_start_time'] ?? ''), 0, 5) : '')
            ."\nTanggal: ".($bookingContext['booking_date'] ?? '-')
            ."\nJam: ".substr((string) ($bookingContext['start_time'] ?? ''), 0, 5)
            ."\n\nJika detailnya sudah benar, balas Iya lanjutkan agar kami proses. Kalau belum sesuai atau batal, balas Tidak ya.";
    }

    private function bookingClarificationReply(array $bookingContext): string
    {
        return 'Bunda masih punya reservasi aktif yang tercatat:'
            ."\nKode booking: ".($bookingContext['active_booking_code'] ?? '-')
            ."\nLayanan: ".($bookingContext['active_service_name'] ?? '-')
            ."\nTanggal: ".($bookingContext['active_booking_date'] ?? '-')
            ."\nJam: ".substr((string) ($bookingContext['active_start_time'] ?? ''), 0, 5)
            ."\n\nBunda ingin membuat reservasi baru atau mengubah jadwal reservasi yang sudah ada?"
            ."\nBalas: Booking baru / Ubah jadwal.";
    }

    private function bookingLookupReply(?int $customerId, ?string $bookingDate = null, ?int $conversationId = null): string
    {
        if (! $customerId && ! $conversationId) {
            return 'Untuk cek data reservasi, mohon gunakan nomor WhatsApp yang terdaftar saat booking ya, Bunda.';
        }

        $bookings = Booking::query()
            ->with(['service', 'branch', 'therapist'])
            ->where(function ($query) use ($customerId, $conversationId) {
                if ($customerId) {
                    $query->where('customer_id', $customerId);
                }

                if ($conversationId) {
                    $method = $customerId ? 'orWhere' : 'where';
                    $query->{$method}('conversation_id', $conversationId);
                }
            })
            ->whereIn('status', BookingStatus::active())
            ->when($bookingDate, fn ($query) => $query->whereDate('booking_date', $bookingDate))
            ->when(! $bookingDate, function ($query) {
                $query->where(function ($query) {
                    $query->whereDate('booking_date', '>=', now()->toDateString())
                        ->orWhereNull('booking_date');
                });
            })
            ->orderBy('booking_date')
            ->orderBy('start_time')
            ->limit(3)
            ->get();

        if ($bookings->isEmpty()) {
            $dateText = $bookingDate ? ' untuk tanggal '.Carbon::parse($bookingDate)->translatedFormat('d F Y') : '';

            return 'Saya belum menemukan reservasi aktif'.$dateText.' atas nomor WhatsApp ini, Bunda. Jika Bunda ingin booking baru, boleh kirim layanan, tanggal, dan jam yang diinginkan ya.';
        }

        $details = $bookings->map(function (Booking $booking) {
            return 'Kode: '.$booking->booking_code
                ."\nLayanan: ".($booking->service?->name ?: '-')
                ."\nTanggal: ".($booking->booking_date?->translatedFormat('d F Y') ?: '-')
                ."\nJam: ".substr((string) $booking->start_time, 0, 5)
                ."\nStatus: ".$booking->status
                .($booking->branch?->name ? "\nCabang: ".$booking->branch->name : '')
                .($booking->therapist?->name ? "\nTherapist: ".$booking->therapist->name : '');
        })->implode("\n\n");

        return 'Saya cekkan ya Bunda. Reservasi aktif yang tercatat:'."\n".$details;
    }

    private function bookingConfirmedActionReply(array $bookingContext): string
    {
        if (($bookingContext['action'] ?? null) === 'cancel_booking') {
            return ($bookingContext['booking_status'] ?? null) === 'confirmed'
                ? 'Siap Bunda, permintaan pembatalan booking '.$bookingContext['booking_code'].' kami ajukan dulu untuk persetujuan admin. Mohon tunggu konfirmasi dari tim Gayatri ya.'
                : 'Siap Bunda, booking '.$bookingContext['booking_code'].' kami batalkan dari sistem.';
        }

        return 'Siap Bunda, '.(($bookingContext['action'] ?? null) === 'reschedule_booking' ? 'perubahan jadwal' : 'slot').' '.$bookingContext['service_name'].' pada '.$bookingContext['booking_date'].' pukul '.substr((string) $bookingContext['start_time'], 0, 5).' kami proses. Mohon tunggu konfirmasi dari tim Gayatri ya.';
    }

    private function formatAlternativeSlots(array $slots): string
    {
        return collect($slots)
            ->map(fn (array $slot) => ($slot['slot_date'] ?? '').' pukul '.($slot['start_time'] ?? ''))
            ->filter()
            ->implode(', ');
    }

    private function availableSlotSummaries(int $limit = 5): string
    {
        return AvailabilitySlot::query()
            ->with(['service', 'branch', 'therapist'])
            ->where('status', 'available')
            ->whereDate('slot_date', '>=', now()->toDateString())
            ->whereColumn('booked_count', '<', 'capacity')
            ->orderBy('slot_date')
            ->orderBy('start_time')
            ->limit($limit)
            ->get()
            ->map(function (AvailabilitySlot $slot) {
                return trim(($slot->service?->name ?: 'Treatment').' '.$slot->slot_date?->format('d M').' '.substr((string) $slot->start_time, 0, 5).($slot->therapist?->name ? ' dengan '.$slot->therapist->name : ''));
            })
            ->filter()
            ->implode(', ');
    }

    private function activeServicesText(int $limit = 5): string
    {
        return Service::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->limit($limit)
            ->pluck('name')
            ->implode(', ') ?: 'Baby Spa Premium';
    }

    private function variant(string $message, array $options): string
    {
        return $options[abs(crc32(Str::lower($message))) % count($options)];
    }

    private function isConversationalMessage(string $message): bool
    {
        $text = Str::of($message)->lower()->squish()->toString();

        return $this->isGreeting($message)
            || $this->isThanks($message)
            || Str::contains($text, ['kamu namanya siapa', 'nama kamu siapa', 'siapa kamu', 'kamu siapa']);
    }

    private function isOperationalInquiry(string $message): bool
    {
        $text = Str::of($message)->lower()->squish()->toString();

        return Str::contains($text, ['jam buka', 'jam tutup', 'jam operasional', 'operasional jam', 'buka jam', 'tutup jam']);
    }

    private function isGreeting(string $message): bool
    {
        $text = Str::of($message)->lower()->squish()->toString();

        return Str::contains($text, [
            'halo', 'hallo', 'hai', 'hi', 'pagi', 'siang', 'sore', 'malam', 'selamat pagi', 'selamat siang', 'selamat sore', 'selamat malam', 'assalamualaikum', 'assalamu alaikum',
        ]);
    }

    private function isThanks(string $message): bool
    {
        return Str::contains(Str::lower($message), ['terima kasih', 'makasih', 'thanks', 'thank you']);
    }

    private function thanksReply(): string
    {
        return 'Sama-sama Bunda. Jika ada yang ingin ditanyakan lagi seputar layanan Gayatri, jadwal, atau booking, kami siap bantu ya.';
    }

    private function isAffirmation(string $message): bool
    {
        $message = Str::of($message)->lower()->squish()->toString();

        return in_array($message, ['iya', 'iya lanjutkan', 'ya', 'ya saya', 'saya mau', 'lanjut', 'lanjutkan', 'boleh', 'ok', 'oke', 'setuju', 'baik proses', 'proses', 'sudah benar', 'benar'], true)
            || Str::startsWith($message, ['iya ', 'oke ', 'ok ', 'lanjutkan ', 'baik proses', 'sudah benar']);
    }

    private function isRejection(string $message): bool
    {
        return in_array(trim(Str::lower($message)), ['tidak', 'tidak jadi', 'batal', 'jangan'], true);
    }

    private function greetingReply(string $message): string
    {
        $text = Str::of($message)->lower()->squish()->toString();

        if (Str::contains($text, ['pagi'])) {
            return 'Selamat pagi';
        }

        if (Str::contains($text, ['siang'])) {
            return 'Selamat siang';
        }

        if (Str::contains($text, ['sore'])) {
            return 'Selamat sore';
        }

        if (Str::contains($text, ['malam'])) {
            return 'Selamat malam';
        }

        if (Str::contains($text, ['assalamualaikum', 'assalamu alaikum'])) {
            return 'Waalaikumsalam';
        }

        return 'Halo';
    }

    private function logAndReturn(string $prompt, ?AiPersona $persona, $knowledge, string $reply, float $confidence, string $status, ?string $fallbackReason, array $sources, array $context): array
    {
        $log = AiLog::create([
            'conversation_id' => $context['conversation_id'] ?? null,
            'message_id' => $context['message_id'] ?? null,
            'customer_id' => $context['customer_id'] ?? null,
            'persona_id' => $persona?->id,
            'knowledge_base_id' => $knowledge?->id,
            'prompt' => $prompt,
            'response' => $reply,
            'confidence' => $confidence,
            'status' => $status,
            'fallback_reason' => $fallbackReason,
            'sources' => $sources,
            'meta' => [
                'mode' => $context['mode'] ?? 'unknown',
                'reply_source' => $context['reply_source'] ?? 'system',
                'provider' => config('ai.provider', 'local'),
                'model' => config('ai.model'),
            ],
        ]);

        return [
            'reply' => $reply,
            'confidence' => $confidence,
            'status' => $status,
            'fallback_reason' => $fallbackReason,
            'sources' => $sources,
            'log_id' => $log->id,
        ];
    }
}
