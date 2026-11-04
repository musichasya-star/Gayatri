<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_payments', fn (Blueprint $table) => $table->uuid('entry_key')->nullable()->unique());
    }

    public function down(): void
    {
        Schema::table('rental_payments', function (Blueprint $table) {
            $table->dropUnique(['entry_key']);
            $table->dropColumn('entry_key');
        });
    }
};
