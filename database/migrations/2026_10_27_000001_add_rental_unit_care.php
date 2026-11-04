<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_products', function (Blueprint $table) {
            $table->json('inspection_checklist')->nullable();
        });
        Schema::table('rental_units', function (Blueprint $table) {
            $table->date('next_maintenance_at')->nullable()->index();
            $table->unsignedInteger('care_version')->default(0);
        });
        Schema::table('rental_items', function (Blueprint $table) {
            $table->json('inspection_checklist')->nullable();
            $table->json('checkout_checklist')->nullable();
            $table->timestamp('checkout_inspected_at')->nullable();
            $table->foreignId('checkout_inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('checkout_photo_path')->nullable();
            $table->string('checkout_state_hash', 64)->nullable();
        });
        Schema::create('rental_care_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('rental_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('entry_key')->unique();
            $table->string('kind', 30);
            $table->string('status_before', 20)->nullable();
            $table->string('status_after', 20);
            $table->json('checklist')->nullable();
            $table->text('notes');
            $table->string('photo_path')->nullable();
            $table->unsignedBigInteger('cost')->default(0);
            $table->string('vendor', 150)->nullable();
            $table->date('next_maintenance_at')->nullable();
            $table->timestamp('performed_at')->index();
            $table->timestamps();
            $table->index(['rental_unit_id', 'performed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_care_records');
        Schema::table('rental_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checkout_inspected_by');
            $table->dropColumn(['inspection_checklist', 'checkout_checklist', 'checkout_inspected_at', 'checkout_photo_path', 'checkout_state_hash']);
        });
        Schema::table('rental_units', fn (Blueprint $table) => $table->dropColumn(['next_maintenance_at', 'care_version']));
        Schema::table('rental_products', fn (Blueprint $table) => $table->dropColumn('inspection_checklist'));
    }
};
