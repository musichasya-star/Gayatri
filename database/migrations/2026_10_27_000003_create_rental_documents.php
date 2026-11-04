<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_documents', function (Blueprint $table) {
            $table->id();
            // Never cascade away an issued financial document or its ledger entry.
            $table->foreignId('rental_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('rental_payment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->string('number', 100)->unique();
            $table->unsignedInteger('version')->nullable();
            $table->char('fingerprint', 64);
            $table->json('snapshot');
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at')->index();
            $table->unique(['rental_order_id', 'type', 'fingerprint'], 'rental_documents_snapshot_unique');
            $table->unique(['rental_order_id', 'type', 'version'], 'rental_documents_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_documents');
    }
};
