<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_addons', function (Blueprint $table) {
            if (! Schema::hasColumn('service_addons', 'addon_name')) {
                $table->string('addon_name')->nullable()->after('addon_service_id');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE service_addons MODIFY addon_service_id BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        Schema::table('service_addons', function (Blueprint $table) {
            if (Schema::hasColumn('service_addons', 'addon_name')) {
                $table->dropColumn('addon_name');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE service_addons MODIFY addon_service_id BIGINT UNSIGNED NOT NULL');
        }
    }
};
