<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->text('failed_reason')->nullable()->after('failed_at');
            $table->unsignedInteger('retry_count')->default(0)->after('failed_reason');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['failed_reason', 'retry_count']);
        });
    }
};
