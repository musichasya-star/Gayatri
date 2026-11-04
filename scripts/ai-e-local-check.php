<?php

use App\Services\AI\AiInformationReply;
use App\Services\AI\AiInteractionPolicy;
use App\Services\AI\AiKnowledgeReadiness;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Read-only local readiness/latency report; do not run with a default or production environment.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
if (! $app->environment('local', 'baileys-local') || $db->getDriverName() !== 'sqlite'
    || realpath($db->getDatabaseName()) !== realpath(storage_path('app/baileys-local.sqlite'))) {
    throw new RuntimeException('This report requires the dedicated local SQLite database.');
}
$db->statement('PRAGMA query_only=ON');
Http::preventStrayRequests();
$queries = 0;
DB::listen(function () use (&$queries) {
    $queries++;
});
$policy = app(AiInteractionPolicy::class)->snapshot();
$cases = [
    ['Halo min mau tanya', 'main_menu'],
    ['layanannya apa aja?', 'main_menu'],
    ['brp harga stroler dan cara bayarnya?', 'pricing'],
    ['gmn kelengkapan alat rental?', 'faq'],
    ['Apa persiapan pijat spa?', 'faq'],
    ['Apa persiapan konsultasi laktasi?', 'faq'],
    ['Bagaimana pewangi laundry?', 'faq'],
    ['Bukan rental, maksudnya laundry', 'domain_menu'],
    ['Saya mau booking pijat bayi', 'web_order'],
    ['Admin dong', 'human_handoff'],
];
$latencies = [];
$queryCounts = [];
$examples = [];
for ($round = 0; $round < 5; $round++) {
    foreach ($cases as [$question, $expected]) {
        $queries = 0;
        $start = hrtime(true);
        $answer = app(AiInformationReply::class)->respond($question, $policy, ['mode' => 'simulator', 'include_demo' => true]);
        $latencies[] = (hrtime(true) - $start) / 1e6;
        $queryCounts[] = $queries;
        if (($answer['routing']['intent'] ?? '') !== $expected) {
            throw new RuntimeException('Unexpected route for public evaluation case.');
        }
        if ($round === 0) {
            $examples[] = ['question' => $question, 'domain' => $answer['routing']['domain'], 'intent' => $answer['routing']['intent'],
                'reason' => $answer['routing']['reason_code'], 'answer' => $answer['reply'], 'sources' => $answer['sources']];
        }
    }
}
sort($latencies);
sort($queryCounts);
$report = ['environment' => $app->environment(), 'read_only' => true, 'calls' => count($latencies),
    'latency_ms' => ['median' => round($latencies[24], 2), 'p95' => round($latencies[47], 2), 'max' => round(max($latencies), 2)],
    'queries' => ['median' => $queryCounts[24], 'p95' => $queryCounts[47], 'max' => max($queryCounts)],
    'knowledge' => app(AiKnowledgeReadiness::class)->report(),
    'legacy' => ['active_flows' => $db->table('conversation_flows')->where('status', 'active')->count(),
        'held_flows' => $db->table('conversation_flows')->where('status', 'held')->count(),
        'pending_approvals' => $db->table('ai_automation_approvals')->where('status', 'pending')->count()],
    'queues' => ['jobs' => $db->table('jobs')->count(), 'failed_jobs' => $db->table('failed_jobs')->count()],
    'notes' => 'Pengukuran lokal satu proses, tanpa provider AI/jaringan/gateway. Demo diikutkan hanya untuk evaluasi FAQ; bukan ukuran beban produksi.',
    'examples' => $examples];
file_put_contents(storage_path('logs/ai-package-e-local-report.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo json_encode(array_diff_key($report, ['examples' => true, 'knowledge' => true]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
