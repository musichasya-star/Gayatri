<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_orders', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->index();
            $table->text('portal_token_secret')->nullable();
            $table->string('notification_phone', 30)->nullable();
            $table->timestamp('notification_verified_at')->nullable();
            $table->foreignId('notification_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('notification_contact_version')->default(0);
        });
        Schema::table('rental_units', fn (Blueprint $table) => $table->boolean('is_demo')->default(false)->index());
        DB::table('rental_orders')->where(fn ($q) => $q->where('code', 'like', 'RENT-DEMO-%')->orWhere('notes', 'like', '[DEMO]%')->orWhere('notes', 'like', '[DEMO LOKAL]%')
            ->orWhereIn('customer_id', DB::table('customers')->where('name', 'like', '[DEMO]%')->select('id')))->update(['is_demo' => true]);
        DB::table('rental_units')->where('code', 'like', 'DEMO-%')->update(['is_demo' => true]);
    }

    public function down(): void
    {
        Schema::table('rental_units', fn (Blueprint $table) => $table->dropColumn('is_demo'));
        Schema::table('rental_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('notification_verified_by');
            $table->dropColumn(['is_demo', 'portal_token_secret', 'notification_phone', 'notification_verified_at', 'notification_contact_version']);
        });
    }
};
