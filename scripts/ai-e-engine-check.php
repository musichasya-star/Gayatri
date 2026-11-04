<?php

// Standalone local engine verification. No workspace .env, queue worker, or real gateway is used.
require __DIR__.'/../vendor/autoload.php';

use App\Jobs\GenerateAiReplyJob;
use App\Jobs\ProcessIncomingWhatsAppMessageJob;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Service;
use App\Models\WhatsAppSession;
use App\Services\AI\AiInteractionPolicy;
use App\Services\CRM\CatalogueImport;
use App\Services\WhatsApp\BaileysService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

$root = realpath(__DIR__.'/..');
$reportPrefix = 'ai-package-e';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--report-prefix=')) {
        $reportPrefix = substr($argument, 16);
        if (! preg_match('/^(?:ai-package-e|catalog-import)$/D', $reportPrefix)) {
            throw new RuntimeException('Unexpected report prefix.');
        }
    }
}
$worker = $argv[1] ?? null;
$driver = in_array('--sqlite', $argv, true) ? 'sqlite' : 'mysql';
$dbName = $worker === 'worker' ? ($argv[2] ?? '') : 'gayatri_ai_e_'.bin2hex(random_bytes(6));
if (! preg_match('/^gayatri_ai_e_[a-f0-9]{12}(?:_upgrade)?$/D', $dbName)) {
    throw new RuntimeException('Unexpected sandbox database name.');
}
$runDir = $root.'/storage/app/ai-e-validation/'.$dbName;
if ($worker !== 'worker' && ! is_dir($runDir)) {
    mkdir($runDir, 0700, true);
}
if (! is_dir($runDir) || ! str_starts_with(realpath($runDir), $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'ai-e-validation'.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Unexpected sandbox directory.');
}
$admin = null;
if ($driver === 'mysql') {
    $admin = new PDO('mysql:host=127.0.0.1;port=33079;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $info = $admin->query('SELECT @@port AS port, @@datadir AS dir, VERSION() AS version')->fetch(PDO::FETCH_ASSOC);
    if ((int) $info['port'] !== 33079 || realpath($info['dir']) !== realpath($root.'/storage/app/ai-e-mariadb')) {
        throw new RuntimeException('Refusing an engine outside the dedicated local datadir/port.');
    }
    if ($worker !== 'worker') {
        $admin->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
}
$settings = ['APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('e', 32)),
    'APP_CONFIG_CACHE' => $runDir.'/no-cached-config.php', 'APP_URL' => 'http://localhost',
    'DB_CONNECTION' => $driver, 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '33079', 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '',
    'DB_URL' => '', 'DB_DATABASE' => $driver === 'sqlite' ? $runDir.'/engine.sqlite' : $dbName,
    'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'AI_PROVIDER' => 'local',
    'WHATSAPP_GATEWAY' => 'baileys', 'LOG_CHANNEL' => 'single', 'LOG_LEVEL' => 'emergency'];
foreach ($settings as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
if ($driver === 'sqlite') {
    touch($settings['DB_DATABASE']);
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();
Queue::fake();
config(['whatsapp.provider' => 'baileys', 'baileys.default_session' => 'isolated-e']);

function check(bool $condition, string $label): void
{
    if (! $condition) {
        throw new RuntimeException('Validation failed: '.$label);
    }
}

if ($worker === 'worker') {
    $number = (int) ($argv[3] ?? 0);
    $operation = $argv[4] ?? 'generate';
    file_put_contents($runDir.'/ready-'.$number, 'ready');
    $deadline = microtime(true) + 15;
    while (! file_exists($runDir.'/go') && microtime(true) < $deadline) {
        usleep(10000);
    }
    check(file_exists($runDir.'/go'), 'parallel barrier');
    if ($operation === 'takeover') {
        DB::transaction(function () use ($runDir) {
            $conversation = Conversation::lockForUpdate()->findOrFail(1);
            file_put_contents($runDir.'/locked', 'locked');
            usleep(350000);
            $conversation->update(['ai_enabled' => false, 'status' => 'human_handled']);
        });
    } elseif (in_array($operation, ['incoming', 'incoming_after_takeover_lock'], true)) {
        if ($operation === 'incoming_after_takeover_lock') {
            $deadline = microtime(true) + 10;
            while (! file_exists($runDir.'/locked') && microtime(true) < $deadline) {
                usleep(10000);
            }
            check(file_exists($runDir.'/locked'), 'takeover lock before incoming');
        }
        (new ProcessIncomingWhatsAppMessageJob(['session' => 'isolated-e', 'payload' => ['from' => '6280000000982@c.us',
            'id' => $operation === 'incoming' ? 'e-parallel-incoming' : 'e-takeover-incoming', 'body' => 'Harga pijat QA', 'timestamp' => now()->timestamp]]))->handle();
    } elseif ($operation === 'send') {
        config(['baileys.base_url' => 'http://gateway-e.test', 'baileys.typing_enabled' => false]);
        Http::fake(function ($request) use ($runDir) {
            check(str_contains($request->url(), 'gateway-e.test') && str_contains($request->url(), 'sendText'), 'only a fake gateway send');
            file_put_contents($runDir.'/fake-sends.txt', "accepted\n", FILE_APPEND | LOCK_EX);
            usleep(250000);

            return Http::response(['id' => 'simulated-e-accepted'], 200);
        });
        $job = unserialize(file_get_contents($runDir.'/send-job.fixture'), ['allowed_classes' => [SendWhatsAppMessageJob::class]]);
        check($job instanceof SendWhatsAppMessageJob, 'fixture send job');
        $job->handle(app(BaileysService::class));
    } else {
        if ($operation === 'after_takeover_lock') {
            $deadline = microtime(true) + 10;
            while (! file_exists($runDir.'/locked') && microtime(true) < $deadline) {
                usleep(10000);
            }
            check(file_exists($runDir.'/locked'), 'takeover acquired row lock');
        }
        app()->call([new GenerateAiReplyJob(1, 1), 'handle']);
    }
    $jobs = Queue::pushed(SendWhatsAppMessageJob::class);
    if ($jobs->count() === 1) {
        file_put_contents($runDir.'/send-job.fixture', serialize($jobs->first()));
    }
    file_put_contents($runDir.'/worker-'.$number.'.json', json_encode(['queued' => $jobs->count(), 'incoming_jobs' => Queue::pushed(GenerateAiReplyJob::class)->count()]));
    exit(0);
}

$report = ['driver' => $driver, 'version' => $info['version'] ?? DB::selectOne('select sqlite_version() as version')->version, 'checks' => []];
try {
    // Upgrade a schema from before the AI A–E migrations, preserving historical business rows.
    $oldPaths = array_values(array_filter(glob($root.'/database/migrations/*.php'), fn ($p) => basename($p) < '2026_11_01_000001'));
    check(Artisan::call('migrate', ['--path' => $oldPaths, '--realpath' => true, '--force' => true]) === 0, 'old schema migrations');
    $customer = DB::table('customers')->insertGetId(['name' => '[DEMO] Historical owner', 'whatsapp_number' => '6280000000981', 'status' => 'active']);
    $service = DB::table('services')->insertGetId(['name' => 'Historical treatment', 'service_group' => 'spa', 'price' => 123456, 'duration_minutes' => 60, 'is_active' => true]);
    DB::table('bookings')->insert(['customer_id' => $customer, 'service_id' => $service, 'booking_code' => 'BK-E-HISTORY', 'booking_date' => '2026-10-30',
        'start_time' => '10:00:00', 'end_time' => '11:00:00', 'status' => 'completed', 'payment_status' => 'paid', 'service_price_snapshot' => 123456]);
    DB::table('knowledge_bases')->insert(['title' => 'Old unreviewed information', 'slug' => 'e-history', 'type' => 'faq', 'content' => 'Unreviewed policy', 'status' => 'active']);
    $old = [];
    foreach (['customers', 'services', 'bookings', 'booking_payments', 'rental_orders', 'laundry_orders'] as $table) {
        $old[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }
    check(Artisan::call('migrate', ['--force' => true]) === 0, 'AI upgrade migrations');
    foreach ($old as $table => $rows) {
        foreach ($rows as $row) {
            $after = (array) DB::table($table)->where('id', $row['id'])->first();
            check(array_intersect_key($after, $row) === $row, 'historical row unchanged: '.$table);
        }
    }
    check(DB::table('knowledge_bases')->where('slug', 'e-history')->value('publication_status') === 'needs_review', 'legacy knowledge not automatically published');
    check(DB::table('booking_payments')->count() === 0, 'historical paid is not new cash');
    check(! app(AiInteractionPolicy::class)->allowsLegacyAutomation(), 'legacy transaction automation stays closed');
    $report['checks']['pre_ai_upgrade'] = true;
    $report['checks']['historical_money_and_rows_preserved'] = true;

    // Use a second empty schema/file for fresh migrations and real two-process row-lock checks.
    DB::disconnect();
    if ($driver === 'mysql') {
        $freshName = $dbName.'_upgrade';
        $admin->exec('CREATE DATABASE `'.$freshName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        config(['database.connections.mysql.database' => $freshName]);
    } else {
        $freshFile = $runDir.'/fresh.sqlite';
        touch($freshFile);
        config(['database.connections.sqlite.database' => $freshFile]);
    }
    DB::purge();
    check(Artisan::call('migrate', ['--force' => true]) === 0, 'fresh migrations');
    $report['checks']['fresh_migration'] = true;
    if ($reportPrefix === 'catalog-import') {
        $import = app(CatalogueImport::class);
        $counts = $import->apply();
        check($counts['services_created'] === 263 && $counts['rentals_created'] === 20 && $counts['retail_created'] === 9 && $counts['laundry_created'] === 11, 'complete catalogue import');
        check(Service::bookable()->count() === 63, 'nullable JSON booking eligibility');
        check($import->apply()['services_created'] === 0 && DB::table('rental_units')->count() === 0, 'idempotent import without invented stock');
        $report['checks']['catalogue_import_and_bookable_scope'] = true;
    }
    $customer = Customer::create(['name' => '[DEMO] Engine E', 'whatsapp_number' => '6280000000982', 'status' => 'active']);
    $service = Service::create(['name' => 'Pijat QA', 'service_group' => 'spa', 'price' => 100000, 'duration_minutes' => 60, 'is_active' => true]);
    $session = WhatsAppSession::create(['session_name' => 'isolated-e', 'status' => 'WORKING']);
    $conversation = Conversation::create(['customer_id' => $customer->id, 'whatsapp_session_id' => $session->id, 'wa_chat_id' => '6280000000982@c.us', 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true]);
    $source = Message::create(['conversation_id' => $conversation->id, 'customer_id' => $customer->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'message_type' => 'text', 'content' => 'Harga pijat QA', 'sent_at' => now()]);
    if ($driver === 'mysql') {
        // Workers use the same explicitly guarded test schema, with a shared barrier.
        $workerDir = $root.'/storage/app/ai-e-validation/'.$freshName;
        mkdir($workerDir, 0700);
        $runPair = function (array $operations) use ($root, $freshName, $workerDir) {
            foreach (glob($workerDir.'/*') as $file) {
                if (is_file($file) && basename($file) !== 'send-job.fixture') {
                    unlink($file);
                }
            }
            $processes = [];
            foreach ($operations as $i => $operation) {
                $processes[] = proc_open([PHP_BINARY, __FILE__, 'worker', $freshName, (string) $i, $operation], [0 => ['pipe', 'r'], 1 => ['file', $workerDir.'/output-'.$i.'.txt', 'w'], 2 => ['file', $workerDir.'/error-'.$i.'.txt', 'w']], $pipes, $root);
                check(is_resource(end($processes)), 'worker started');
            }
            $deadline = microtime(true) + 15;
            while (count(glob($workerDir.'/ready-*')) !== 2 && microtime(true) < $deadline) {
                usleep(20000);
            }
            check(count(glob($workerDir.'/ready-*')) === 2, 'both workers ready');
            touch($workerDir.'/go');
            foreach ($processes as $process) {
                check(proc_close($process) === 0, 'parallel worker exited successfully');
            }

            return array_sum(array_map(fn ($path) => json_decode(file_get_contents($path), true)['queued'], glob($workerDir.'/worker-*.json')));
        };
        $queued = $runPair(['generate', 'generate']);
        check($queued === 1 && DB::table('ai_logs')->count() === 1, 'same source creates one decision/dispatch across two workers');
        check((int) DB::table('ai_conversation_contexts')->value('revision') === 1, 'context committed once');
        $report['checks']['parallel_duplicate_source_one_dispatch'] = true;
        $runPair(['send', 'send']);
        check(count(file($workerDir.'/fake-sends.txt', FILE_IGNORE_NEW_LINES)) === 1, 'parallel send reaches fake gateway once');
        check(DB::table('messages')->where('direction', 'outgoing')->count() === 1
            && DB::table('messages')->where('direction', 'outgoing')->where('delivery_status', 'sent')->where('send_attempts', 1)->count() === 1, 'one accepted outgoing after parallel replay');
        $report['checks']['parallel_send_one_fake_gateway_acceptance'] = true;
        DB::table('messages')->where('id', 1)->update(['ai_reply_dispatched_at' => null]);
        DB::table('ai_conversation_contexts')->delete();
        DB::table('ai_logs')->delete();
        $conversation->refresh()->update(['status' => 'open', 'ai_enabled' => true]);
        $queued = $runPair(['takeover', 'after_takeover_lock']);
        check($queued === 0 && DB::table('ai_logs')->count() === 0 && ! $conversation->fresh()->ai_enabled, 'concurrent takeover suppresses reply');
        $report['checks']['parallel_takeover_blocks_generation'] = true;
        $conversation->refresh()->update(['status' => 'open', 'ai_enabled' => true]);
        $runPair(['incoming', 'incoming']);
        $incomingJobs = array_sum(array_map(fn ($file) => json_decode(file_get_contents($file), true)['incoming_jobs'], glob($workerDir.'/worker-*.json')));
        check($incomingJobs === 1 && DB::table('messages')->where('wa_message_id', 'e-parallel-incoming')->count() === 1
            && $conversation->fresh()->unread_count === 1, 'parallel webhook one incoming/unread/job');
        $report['checks']['parallel_webhook_one_incoming_and_job'] = true;
        $runPair(['takeover', 'incoming_after_takeover_lock']);
        check($conversation->fresh()->status === 'human_handled' && ! $conversation->fresh()->ai_enabled, 'incoming cannot reopen concurrent human takeover');
        $report['checks']['parallel_incoming_preserves_takeover'] = true;
    } else {
        app()->call([new GenerateAiReplyJob($conversation->id, $source->id), 'handle']);
        app()->call([new GenerateAiReplyJob($conversation->id, $source->id), 'handle']);
        check(Queue::pushed(SendWhatsAppMessageJob::class)->count() === 1, 'sequential source replay');
        $report['checks']['sequential_replay'] = true;
    }
    check(DB::table('jobs')->count() === 0, 'no real jobs');
    $report['checks']['no_real_gateway_or_queue'] = true;
    $report['pass'] = true;
} catch (Throwable $error) {
    $report['pass'] = false;
    $report['error_class'] = get_class($error);
    $report['error'] = preg_replace('/password=[^\s]+/i', 'password=[hidden]', $error->getMessage());
} finally {
    DB::disconnect();
    if ($admin) {
        // Names were generated and validated above; these schemas contain only fixtures created by this run.
        $admin->exec('DROP DATABASE IF EXISTS `'.$dbName.'_upgrade`');
        $admin->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
    }
}
file_put_contents($root.'/storage/logs/'.$reportPrefix.'-engine-'.$driver.'.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($report['pass'] ? 0 : 1);
