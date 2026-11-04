<?php

namespace App\Services\CRM;

use App\Jobs\SendBirthdayGreetingJob;
use App\Models\BirthdayGreeting;
use App\Models\Customer;
use App\Services\AI\AiChatCustomization;
use App\Services\AppSettingService;
use App\Support\CustomerStatus;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BirthdayGreetingService
{
    public const SETTING_KEY = 'crm.birthday_greetings';

    public const TIMEZONE = 'Asia/Jakarta';

    public function __construct(private readonly AppSettingService $settings, private readonly AiChatCustomization $chatCustomization) {}

    public function defaults(): array
    {
        return [
            'enabled' => false,
            'include_customer' => true,
            'include_baby' => false,
            'send_time' => '09:00',
            'leap_day' => 'feb28',
            'customer_template' => 'Selamat ulang tahun, {nama_customer}! 🎂 Semoga selalu sehat, bahagia, dan penuh berkah. Salam hangat dari {nama_bisnis}.',
            'baby_template' => 'Selamat ulang tahun ke-{usia} untuk {nama_bayi}! 🎂 Semoga tumbuh sehat, ceria, dan penuh kebahagiaan. Salam hangat untuk keluarga {nama_customer} dari {nama_bisnis}.',
        ];
    }

    public function settings(): array
    {
        $stored = json_decode((string) $this->settings->get(self::SETTING_KEY, ''), true);

        return array_replace($this->defaults(), is_array($stored) ? $stored : []);
    }

    public function now(): Carbon
    {
        return Carbon::now(self::TIMEZONE);
    }

    public function canDispatch(?array $settings = null): bool
    {
        $settings ??= $this->settings();

        return $settings['enabled'] && $this->now()->format('H:i') >= $settings['send_time'];
    }

    public function dateField(string $type): string
    {
        return $type === 'baby' ? 'baby_birth_date' : 'birth_date';
    }

    public function occursOn(Customer $customer, string $type, CarbonInterface $date, array $settings): bool
    {
        $birthday = $customer->{$this->dateField($type)};
        if (! $birthday || $birthday->year >= $date->year) {
            return false;
        }
        $monthDay = $birthday->format('m-d');
        if ($monthDay === '02-29' && ! $date->isLeapYear()) {
            $monthDay = $settings['leap_day'] === 'mar1' ? '03-01' : '02-28';
        }

        return $monthDay === $date->format('m-d');
    }

    public function recipient(Customer $customer): ?string
    {
        if (! in_array($customer->status, [CustomerStatus::ACTIVE, CustomerStatus::LEAD], true)) {
            return null;
        }
        // Preserve existing LID conversations rather than inventing a phone number from a LID.
        $conversation = $customer->conversations->sortByDesc('last_message_at')->first(
            fn ($conversation) => preg_match('/^\d+(?::\d+)?@(c\.us|s\.whatsapp\.net|lid)$/', (string) $conversation->wa_chat_id)
        );
        if ($conversation && str_ends_with($conversation->wa_chat_id, '@lid')) {
            return $conversation->wa_chat_id;
        }
        if ($customer->normalized_whatsapp_number) {
            return $customer->normalized_whatsapp_number.'@c.us';
        }

        return null;
    }

    public function occurrences(CarbonInterface $date, ?array $settings = null): Collection
    {
        $settings ??= $this->settings();
        $types = collect(['customer', 'baby'])->filter(fn ($type) => $settings['include_'.$type]);
        if ($types->isEmpty()) {
            return collect();
        }
        $includeLeap = ! $date->isLeapYear() && $date->format('m-d') === ($settings['leap_day'] === 'mar1' ? '03-01' : '02-28');
        $customers = Customer::with('conversations')->where(function ($query) use ($types, $date, $includeLeap) {
            foreach ($types as $type) {
                $field = $this->dateField($type);
                $query->orWhere(function ($birthday) use ($field, $date, $includeLeap) {
                    $birthday->whereYear($field, '<', $date->year)->where(function ($day) use ($field, $date, $includeLeap) {
                        $day->where(function ($normal) use ($field, $date) {
                            $normal->whereMonth($field, $date->month)->whereDay($field, $date->day);
                        });
                        if ($includeLeap) {
                            $day->orWhere(fn ($leap) => $leap->whereMonth($field, 2)->whereDay($field, 29));
                        }
                    });
                });
            }
        })->orderBy('name')->get();
        $result = collect();
        foreach ($customers as $customer) {
            foreach ($types as $type) {
                if ($this->occursOn($customer, $type, $date, $settings)) {
                    $result->push([
                        'customer' => $customer, 'type' => $type, 'date' => $date->copy(),
                        'name' => $type === 'baby' ? ($customer->baby_name ?: 'Si kecil') : $customer->name,
                        'age' => $date->year - $customer->{$this->dateField($type)}->year,
                        'chat_id' => $this->recipient($customer),
                    ]);
                }
            }
        }

        return $result;
    }

    public function render(Customer $customer, string $type, array $settings, ?CarbonInterface $date = null): string
    {
        $date ??= $this->now();
        $branding = $this->chatCustomization->settings();
        $birthday = $customer->{$this->dateField($type)};

        return trim(strtr($settings[$type.'_template'], [
            '{nama_customer}' => $customer->name,
            '{nama_bayi}' => $customer->baby_name ?: 'Si kecil',
            '{usia}' => $birthday ? (string) max(0, $date->year - $birthday->year) : '-',
            '{nama_bisnis}' => $branding['business_name'],
            '{nama_asisten}' => $branding['assistant_name'],
            '{sapaan}' => $branding['salutation'],
        ]));
    }

    public function dispatchDue(): int
    {
        $this->settings->set('crm.birthday_scheduler_last_check', $this->now()->toIso8601String());
        $settings = $this->settings();
        if (! $this->canDispatch($settings)) {
            return 0;
        }
        $today = $this->now();
        $count = 0;
        foreach ($this->occurrences($today, $settings) as $occasion) {
            if (! $occasion['chat_id']) {
                continue;
            }
            $created = DB::transaction(function () use ($occasion, $today, $settings, $count) {
                $greeting = BirthdayGreeting::firstOrCreate([
                    'customer_id' => $occasion['customer']->id,
                    'birthday_type' => $occasion['type'],
                    'year' => $today->year,
                ], [
                    'birthday_on' => $today->toDateString(),
                    'scheduled_at' => $today->copy()->setTimeFromTimeString($settings['send_time'])->setTimezone(config('app.timezone')),
                    'message' => $this->render($occasion['customer'], $occasion['type'], $settings, $today),
                    'chat_id' => $occasion['chat_id'],
                    'status' => 'queued',
                ]);
                if (! $greeting->wasRecentlyCreated) {
                    return false;
                }
                // On the default database queue, greeting and job are committed atomically.
                $dispatch = SendBirthdayGreetingJob::dispatch($greeting->id)->delay(now()->addSeconds($count * 10));
                if (config('queue.connections.'.config('queue.default').'.driver') !== 'database') {
                    $dispatch->afterCommit();
                }

                return true;
            });
            if ($created) {
                $count++;
            }
        }

        return $count;
    }
}
