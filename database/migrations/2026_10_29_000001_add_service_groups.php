<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('service_group', 30)->default('spa')->index();
        });
        DB::table('services')->select('id', 'name', 'category')->orderBy('id')->chunkById(200, function ($services) {
            foreach ($services as $service) {
                if (preg_match('/\b(laktasi|lactation|asi|menyusui|breastfeeding)\b/iu', $service->name.' '.$service->category)) {
                    DB::table('services')->where('id', $service->id)->update(['service_group' => 'lactation']);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex(['service_group']);
            $table->dropColumn('service_group');
        });
    }
};
