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
            $table->string('catalog_code', 60)->nullable()->unique();
            $table->string('catalog_family')->nullable()->index();
            $table->string('variant_label')->nullable();
            $table->string('booking_mode', 16)->default('online');
            $table->json('catalog_metadata')->nullable();
            $table->string('image_path')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable()->default(60)->change();
            $table->decimal('price', 12, 2)->nullable()->default(0)->change();
        });
        Schema::table('service_addons', fn (Blueprint $table) => $table->boolean('requires_confirmation')->default(false));
        Schema::table('rental_products', function (Blueprint $table) {
            $table->decimal('fortnightly_rate', 12, 2)->nullable();
            $table->json('catalog_metadata')->nullable();
            $table->decimal('deposit', 12, 2)->nullable()->default(0)->change();
        });
        Schema::table('laundry_tariffs', function (Blueprint $table) {
            $table->boolean('requires_confirmation')->default(false);
            $table->json('catalog_metadata')->nullable();
            $table->unsignedInteger('minimum_milli')->nullable()->change();
            $table->unsignedInteger('increment_milli')->nullable()->change();
            $table->unsignedInteger('duration_hours')->nullable()->change();
        });
        Schema::create('retail_products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 60)->unique();
            $table->string('name');
            $table->string('category')->default('Produk Pendukung');
            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('compare_at_price')->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->json('catalog_metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::table('ai_interaction_policies')->where('id', 1)->increment('revision');
    }

    public function down(): void
    {
        // Nullable values cannot be safely converted to operational defaults during rollback.
        Schema::dropIfExists('retail_products');
        Schema::table('laundry_tariffs', fn (Blueprint $table) => $table->dropColumn(['requires_confirmation', 'catalog_metadata']));
        Schema::table('rental_products', fn (Blueprint $table) => $table->dropColumn(['fortnightly_rate', 'catalog_metadata']));
        Schema::table('service_addons', fn (Blueprint $table) => $table->dropColumn('requires_confirmation'));
        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique(['catalog_code']);
            $table->dropIndex(['catalog_family']);
            $table->dropColumn(['catalog_code', 'catalog_family', 'variant_label', 'booking_mode', 'catalog_metadata', 'image_path']);
        });
    }
};
