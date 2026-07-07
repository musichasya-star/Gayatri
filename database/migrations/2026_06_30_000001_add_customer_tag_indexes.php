<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TAGS_JSON_INDEX = 'customers_tags_json_multival';
    private const STATUS_BOOKING_INDEX = 'customers_status_last_booking_idx';

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            if (! $this->mysqlIndexExists(self::TAGS_JSON_INDEX) && $this->supportsMysqlMultiValueIndex()) {
                try {
                    DB::statement("ALTER TABLE `customers` ADD INDEX `" . self::TAGS_JSON_INDEX . "` ((CAST(`tags` AS CHAR(191) ARRAY)))");
                } catch (\Throwable) {
                    // Ignore when DB server version/config does not support expression index.
                }
            }

            if (! $this->indexExists(self::STATUS_BOOKING_INDEX)) {
                DB::statement("ALTER TABLE `customers` ADD INDEX `" . self::STATUS_BOOKING_INDEX . "` (`status`, `last_booking_at`)");
            }
        }

        if ($driver === 'pgsql') {
            if (! $this->indexExists('customers_tags_json_gin', 'public.customers_tags_json_gin')) {
                DB::statement('CREATE INDEX customers_tags_json_gin ON customers USING gin ((tags::jsonb))');
            }
            if (! $this->indexExists('customers_status_last_booking_idx', 'public.customers_status_last_booking_idx')) {
                DB::statement('CREATE INDEX customers_status_last_booking_idx ON customers (status, last_booking_at)');
            }
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            if ($this->mysqlIndexExists(self::TAGS_JSON_INDEX)) {
                DB::statement('DROP INDEX `' . self::TAGS_JSON_INDEX . '` ON `customers`');
            }

            if ($this->indexExists(self::STATUS_BOOKING_INDEX)) {
                DB::statement('DROP INDEX `' . self::STATUS_BOOKING_INDEX . '` ON `customers`');
            }
        }

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS customers_tags_json_gin');
            DB::statement('DROP INDEX IF EXISTS customers_status_last_booking_idx');
        }
    }

    private function supportsMysqlMultiValueIndex(): bool
    {
        $version = DB::selectOne('SELECT VERSION() as version');
        $raw = (string) ($version->version ?? '');
        if (! preg_match('/^(\d+\.\d+\.\d+)/', $raw, $matches)) {
            return false;
        }

        return version_compare($matches[1], '8.0.17', '>=');
    }

    private function indexExists(string $indexName, ?string $qualified = null): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $qualified = $qualified ?? $indexName;
            $result = DB::selectOne('SELECT to_regclass(?) IS NOT NULL AS exists', [$qualified]);

            return (bool) ($result->exists ?? false);
        }

        if ($driver !== 'mysql') {
            return false;
        }

        $result = DB::selectOne(
            "SELECT COUNT(*) AS count FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'customers' AND index_name = ?",
            [$indexName],
        );

        return (int) ($result->count ?? 0) > 0;
    }

    private function mysqlIndexExists(string $indexName): bool
    {
        return $this->indexExists($indexName);
    }
};
