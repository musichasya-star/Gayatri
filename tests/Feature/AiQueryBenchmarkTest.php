<?php

namespace Tests\Feature;

use App\Models\AiPersona;
use App\Models\AvailabilitySlot;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Models\Message;
use App\Models\Service;
use App\Models\WhatsAppSession;
use App\Services\AI\AiService;
use App\Services\AI\ConversationFlowService;
use App\Support\AvailabilitySlotStatus;
use App\Support\CustomerStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AiQueryBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private static int $conversationSequence = 1;
    private array $scenarioResults = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenarioResults = [];

        config()->set('ai.provider', 'local');
        config()->set('crm.default_ai_mode', 'auto_reply');
    }

    protected function tearDown(): void
    {
        $this->persistBenchmarkReport();
        parent::tearDown();
    }

    public function test_query_count_booking_flow_before_and_after_optimization(): void
    {
        $baseline = $this->measureBookingFlow('baseline');
        $optimized = $this->measureBookingFlow('optimized');

        $this->printComparison('Booking Flow', $baseline, $optimized);
        $this->recordScenarioResult('Booking Flow', $baseline, $optimized);
        $this->assertQueryRegression('Booking Flow', $baseline, $optimized);

        $this->assertGreaterThan(0, $baseline['total_queries']);
        $this->assertGreaterThan(0, $optimized['total_queries']);
    }

    public function test_query_count_ai_auto_reply_before_and_after_optimization(): void
    {
        $baseline = $this->measureAutoReply('baseline');
        $optimized = $this->measureAutoReply('optimized');

        $this->printComparison('AiAutoReply', $baseline, $optimized);
        $this->recordScenarioResult('AiAutoReply', $baseline, $optimized);
        $this->assertQueryRegression('AiAutoReply', $baseline, $optimized);

        $this->assertGreaterThan(0, $baseline['total_queries']);
        $this->assertGreaterThan(0, $optimized['total_queries']);
    }

    private function measureBookingFlow(string $mode): array
    {
        config()->set('app.query_benchmark_mode', $mode);
        app()->forgetInstance(ConversationFlowService::class);

        Carbon::setTestNow('2026-06-30 10:00:00');

        try {
            [$customer, $conversation] = $this->buildConversation($this->nextPhone());
            $service = Service::create([
                'name' => 'Baby Spa Premium',
                'category' => 'baby-spa',
                'duration_minutes' => 60,
                'price' => 250000,
                'is_active' => true,
            ]);

            AvailabilitySlot::create([
                'service_id' => $service->id,
                'slot_date' => now()->addDay()->toDateString(),
                'start_time' => '14:00:00',
                'end_time' => '15:00:00',
                'capacity' => 1,
                'booked_count' => 0,
                'status' => AvailabilitySlotStatus::AVAILABLE,
            ]);

            $steps = [
                'saya mau booking',
                'Rika',
                '081234567890',
                'Jl. Kenanga No 10',
                'baby spa',
                'besok',
                'jam 2 siang',
            ];

            $metrics = $this->withQueryCount(function () use ($conversation, $customer, $steps): void {
                $flow = app(ConversationFlowService::class);

                foreach ($steps as $step) {
                    $message = $this->incoming($conversation, $customer, $step);
                    $flow->handle($conversation, $message);
                }
            });

            return $metrics;
        } finally {
            Carbon::setTestNow();
        }
    }

    private function measureAutoReply(string $mode): array
    {
        config()->set('app.query_benchmark_mode', $mode);

        $tag = $this->nextPhone();
        $this->seedAutoReplyData($tag);
        [$customer, $conversation] = $this->buildConversation($tag);

        $messages = [
            'Halo, mau tanya jam operasionalnya?',
            'saya mau booking baby spa',
            'jam 2 siang',
        ];

        $metrics = $this->withQueryCount(function () use ($conversation, $customer, $messages): void {
            $aiService = app(AiService::class);

            foreach ($messages as $text) {
                $message = $this->incoming($conversation, $customer, $text);
                $aiService->generateReply($conversation, $message);
            }
        });

        return $metrics;
    }

    private function withQueryCount(callable $callback): array
    {
        $connection = DB::connection();
        $connection->disableQueryLog();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $start = microtime(true);
        $callback();
        $duration = microtime(true) - $start;

        $queries = $connection->getQueryLog();
        $connection->disableQueryLog();

        $types = [];
        foreach ($queries as $query) {
            $firstToken = strtoupper(trim(explode(' ', trim((string) $query['query']))[0]));
            $types[$firstToken] = ($types[$firstToken] ?? 0) + 1;
        }

        return [
            'total_queries' => count($queries),
            'duration_ms' => round($duration * 1000, 2),
            'select_queries' => $types['SELECT'] ?? 0,
            'insert_queries' => $types['INSERT'] ?? 0,
            'update_queries' => $types['UPDATE'] ?? 0,
            'delete_queries' => $types['DELETE'] ?? 0,
            'other_queries' => max(0, count($queries) - (($types['SELECT'] ?? 0) + ($types['INSERT'] ?? 0) + ($types['UPDATE'] ?? 0) + ($types['DELETE'] ?? 0))),
        ];
    }

    private function printComparison(string $scenario, array $baseline, array $optimized): void
    {
        $delta = $optimized['total_queries'] - $baseline['total_queries'];
        $pct = $baseline['total_queries'] > 0
            ? (($delta / $baseline['total_queries']) * 100)
            : 0;

        $direction = $delta <= 0 ? 'down' : 'up';

        fwrite(
            STDOUT,
            sprintf(
                "[%s] baseline=%d queries, optimized=%d queries, delta=%d (%s %0.2f%%), duration=%0.2fms vs %0.2fms\n",
                $scenario,
                $baseline['total_queries'],
                $optimized['total_queries'],
                $delta,
                $direction,
                abs($pct),
                $baseline['duration_ms'],
                $optimized['duration_ms']
            )
        );
    }

    private function recordScenarioResult(string $scenario, array $baseline, array $optimized): void
    {
        $delta = $optimized['total_queries'] - $baseline['total_queries'];
        $pct = $baseline['total_queries'] > 0
            ? (($delta / $baseline['total_queries']) * 100)
            : 0;

        $this->scenarioResults[] = [
            'scenario' => $scenario,
            'baseline' => $baseline,
            'optimized' => $optimized,
            'delta_queries' => $delta,
            'delta_percent' => round($pct, 2),
            'delta_abs_percent' => round(abs($pct), 2),
            'direction' => $delta <= 0 ? 'down' : 'up',
        ];
    }

    private function assertQueryRegression(string $scenario, array $baseline, array $optimized): void
    {
        $maxRegressionPercent = (float) env('AI_QUERY_BENCHMARK_MAX_REGRESSION_PERCENT', -1);
        if ($maxRegressionPercent < 0 || $baseline['total_queries'] === 0) {
            return;
        }

        $delta = $optimized['total_queries'] - $baseline['total_queries'];
        $pct = ($delta / $baseline['total_queries']) * 100;

        $this->assertLessThanOrEqual(
            $maxRegressionPercent,
            $pct,
            sprintf(
                '[AiQueryBenchmark] %s regression too high: baseline=%d, optimized=%d, delta=%0.2f%% (max allowed=%0.2f%%)',
                $scenario,
                $baseline['total_queries'],
                $optimized['total_queries'],
                $pct,
                $maxRegressionPercent
            )
        );
    }

    private function persistBenchmarkReport(): void
    {
        if (empty($this->scenarioResults) || ! $this->shouldPersistBenchmarkReport()) {
            return;
        }

        try {
            $run = [
                'generated_at' => now()->toISOString(),
                'environment' => [
                    'php_version' => PHP_VERSION,
                    'app_env' => app()->environment(),
                ],
                'results' => $this->scenarioResults,
            ];

            $jsonPath = $this->benchmarkReportPath('json');
            $history = $this->readBenchmarkHistory($jsonPath);

            if (! is_array($history)) {
                $history = [];
            }

            $history[] = $run;
            $this->ensureDirectory($jsonPath);
            File::put($jsonPath, json_encode($history, JSON_PRETTY_PRINT));

            $csvPath = $this->benchmarkReportPath('csv');
            $this->writeBenchmarkCsv($csvPath, $history);

            fwrite(STDOUT, '[AiQueryBenchmark] Report saved to: '.$jsonPath."\n");
            fwrite(STDOUT, '[AiQueryBenchmark] CSV saved to: '.$csvPath."\n");
        } catch (\Throwable $exception) {
            fwrite(STDOUT, '[AiQueryBenchmark] Warning: unable to persist report ('.$exception->getMessage().")\n");
        }
    }

    private function shouldPersistBenchmarkReport(): bool
    {
        return filter_var(env('AI_QUERY_BENCHMARK_REPORT', true), FILTER_VALIDATE_BOOLEAN);
    }

    private function benchmarkReportPath(string $format): string
    {
        $defaultPath = storage_path('logs/ai-query-benchmark.'.$format);
        $path = env('AI_QUERY_BENCHMARK_REPORT_'.$format, $defaultPath);

        return is_string($path) && trim($path) !== '' ? $path : $defaultPath;
    }

    private function ensureDirectory(string $path): void
    {
        $directory = dirname($path);
        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true, true);
        }
    }

    private function readBenchmarkHistory(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $payload = json_decode((string) File::get($path), true);

        return is_array($payload) ? $payload : [];
    }

    private function writeBenchmarkCsv(string $path, array $history): void
    {
        $headers = [
            'generated_at',
            'scenario',
            'baseline_total',
            'optimized_total',
            'delta_total',
            'delta_direction',
            'delta_percent',
            'baseline_duration_ms',
            'optimized_duration_ms',
            'select_queries',
            'insert_queries',
            'update_queries',
            'delete_queries',
            'other_queries',
        ];

        $this->ensureDirectory($path);
        $handle = fopen($path, 'w');
        if ($handle === false) {
            return;
        }

        fputcsv($handle, $headers);

        foreach ($history as $entry) {
            if (! is_array($entry) || ! isset($entry['generated_at'], $entry['results']) || ! is_array($entry['results'])) {
                continue;
            }

            foreach ($entry['results'] as $result) {
                if (! is_array($result)) {
                    continue;
                }

                fputcsv($handle, [
                    $entry['generated_at'],
                    $result['scenario'] ?? '',
                    $result['baseline']['total_queries'] ?? 0,
                    $result['optimized']['total_queries'] ?? 0,
                    $result['delta_queries'] ?? 0,
                    $result['direction'] ?? '',
                    $result['delta_percent'] ?? 0,
                    $result['baseline']['duration_ms'] ?? 0,
                    $result['optimized']['duration_ms'] ?? 0,
                    $result['baseline']['select_queries'] ?? 0,
                    $result['baseline']['insert_queries'] ?? 0,
                    $result['baseline']['update_queries'] ?? 0,
                    $result['baseline']['delete_queries'] ?? 0,
                    $result['baseline']['other_queries'] ?? 0,
                ]);
            }
        }

        fclose($handle);
    }

    private function buildConversation(string $phone): array
    {
        $customer = Customer::create([
            'name' => 'Customer '.$phone,
            'phone' => $phone,
            'whatsapp_number' => $phone,
            'status' => CustomerStatus::LEAD,
        ]);

        $session = WhatsAppSession::create(['session_name' => 'default-'.$phone, 'status' => 'working']);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => $phone.'@c.us',
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
        ]);

        return [$customer, $conversation];
    }

    private function seedAutoReplyData(string $tag): void
    {
        AiPersona::create([
            'name' => 'Gayatri Assistant',
            'slug' => 'gayatri-assistant-'.$tag,
            'prompt' => 'Jawab hanya berdasarkan knowledge base Gayatri dan eskalasi pertanyaan medis.',
            'tone' => 'warm-professional',
            'is_active' => true,
        ]);

        $knowledge = KnowledgeBase::create([
            'title' => 'FAQ Baby Spa',
            'slug' => 'faq-baby-spa-'.$tag,
            'type' => 'faq',
            'content' => 'Baby spa tersedia pukul 09.00 sampai 18.00. Booking bisa dibantu admin Gayatri.',
            'status' => 'active',
        ]);

        $knowledge->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Baby spa tersedia pukul 09.00 sampai 18.00.',
            'token_count' => 8,
        ]);

        Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
    }

    private function incoming(Conversation $conversation, Customer $customer, string $content): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'wa_message_id' => 'wamid-bench-'.uniqid((string) static::$conversationSequence),
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => $content,
            'sent_at' => now(),
        ]);
    }

    private function nextPhone(): string
    {
        $phone = str_pad((string) static::$conversationSequence++, 8, '0', STR_PAD_LEFT);

        return '6281777'.$phone;
    }
}
