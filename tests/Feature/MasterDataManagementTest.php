<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Therapist;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Support\CustomerStatus;
use App\Support\UserRole;
use App\Support\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class MasterDataManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_can_view_customer_index_but_cannot_access_branch_management(): void
    {
        $sales = User::factory()->create([
            'role' => UserRole::SALES,
            'status' => UserStatus::ACTIVE,
        ]);

        $this->actingAs($sales)
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertSee('Customer');

        $this->actingAs($sales)
            ->get(route('admin.branches.index'))
            ->assertForbidden();
    }

    public function test_admin_can_create_and_update_customer_with_audit_log(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);

        $branch = Branch::create([
            'name' => 'Cabang Bandung',
            'code' => 'BDG',
            'city' => 'Bandung',
            'timezone' => 'Asia/Jakarta',
            'status' => UserStatus::ACTIVE,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.customers.store'), [
                'branch_id' => $branch->id,
                'name' => 'Bunda Naya',
                'phone' => '081200000111',
                'whatsapp_number' => '6281200000111',
                'email' => 'naya@example.test',
                'city' => 'Bandung',
                'status' => CustomerStatus::ACTIVE,
                'tags' => 'lead-baru, baby-spa',
                'notes' => 'Suka treatment sore hari.',
            ])
            ->assertRedirect();

        $customer = Customer::where('whatsapp_number', '6281200000111')->firstOrFail();

        $this->assertSame(['lead-baru', 'baby-spa'], $customer->tags);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'customer.created',
            'auditable_type' => Customer::class,
            'auditable_id' => $customer->id,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.customers.update', $customer), [
                'branch_id' => $branch->id,
                'name' => 'Bunda Naya Update',
                'phone' => '081200000222',
                'whatsapp_number' => '6281200000111',
                'email' => 'naya@example.test',
                'city' => 'Cimahi',
                'status' => CustomerStatus::LEAD,
                'tags' => 'followup, warm-lead',
                'notes' => 'Minta dihubungi lagi besok.',
            ])
            ->assertRedirect(route('admin.customers.show', $customer));

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'Bunda Naya Update',
            'city' => 'Cimahi',
            'status' => CustomerStatus::LEAD,
        ]);

        $this->assertSame(2, AuditLog::where('auditable_type', Customer::class)->where('auditable_id', $customer->id)->count());
    }

    public function test_customer_index_formats_whatsapp_numbers_and_uses_simple_pagination(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        Customer::create([
            'name' => 'Bunda Nomor Valid',
            'phone' => '081234500001',
            'whatsapp_number' => '6281234500001',
            'status' => CustomerStatus::ACTIVE,
            'tags' => ['baby-spa'],
        ]);
        $lidCustomer = Customer::create([
            'name' => 'Bunda LID',
            'phone' => null,
            'whatsapp_number' => '1234567890',
            'status' => CustomerStatus::LEAD,
        ]);
        Conversation::create([
            'customer_id' => $lidCustomer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '1234567890@lid',
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
            'last_message_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertSee('+62 812 3450 0001')
            ->assertSee('6281234500001')
            ->assertSee('081234500001')
            ->assertSee('WhatsApp ID: 1234567890')
            ->assertSee('Sebelumnya')
            ->assertSee('Berikutnya')
            ->assertDontSee('<svg', false);
    }

    public function test_admin_can_create_branch_service_and_therapist(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);

        $therapistUser = User::factory()->create([
            'role' => UserRole::THERAPIST,
            'status' => UserStatus::ACTIVE,
            'email' => 'therapist.master@example.test',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.branches.store'), [
                'name' => 'Cabang Depok',
                'code' => 'DPK',
                'phone' => '0214000001',
                'address' => 'Jl. Melati No. 1',
                'city' => 'Depok',
                'timezone' => 'Asia/Jakarta',
                'status' => UserStatus::ACTIVE,
            ])
            ->assertRedirect(route('admin.branches.index'));

        $branch = Branch::where('code', 'DPK')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.services.store'), [
                'branch_id' => $branch->id,
                'name' => 'Mom Relax Massage',
                'category' => 'mom-care',
                'duration_minutes' => 90,
                'price' => 375000,
                'is_active' => 1,
                'description' => 'Perawatan relaksasi ibu.',
            ])
            ->assertRedirect(route('admin.services.index'));

        $service = Service::where('name', 'Mom Relax Massage')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.therapists.store'), [
                'user_id' => $therapistUser->id,
                'branch_id' => $branch->id,
                'name' => 'Rani Terapis',
                'phone' => '081300000333',
                'specialization' => 'Postnatal Massage',
                'status' => UserStatus::ACTIVE,
                'notes' => 'Terapis senior.',
            ])
            ->assertRedirect(route('admin.therapists.index'));

        $therapist = Therapist::where('name', 'Rani Terapis')->firstOrFail();

        $this->assertSame($branch->id, $service->branch_id);
        $this->assertSame($branch->id, $therapist->branch_id);
        $this->assertSame($therapistUser->id, $therapist->user_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'branch.created',
            'auditable_type' => Branch::class,
            'auditable_id' => $branch->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'service.created',
            'auditable_type' => Service::class,
            'auditable_id' => $service->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'therapist.created',
            'auditable_type' => Therapist::class,
            'auditable_id' => $therapist->id,
        ]);
    }

    public function test_admin_can_import_and_export_customers_csv(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);

        Branch::create([
            'name' => 'Cabang Bogor',
            'code' => 'BGR',
            'city' => 'Bogor',
            'timezone' => 'Asia/Jakarta',
            'status' => UserStatus::ACTIVE,
        ]);

        $csv = <<<'CSV'
name,branch_code,phone,whatsapp_number,email,city,status,tags,notes
Bunda Kila,BGR,081200000444,6281200000444,kila@example.test,Bogor,active,"lead, baby-spa",Import pertama
CSV;

        $file = UploadedFile::fake()->createWithContent('customers.csv', $csv);

        $this->actingAs($admin)
            ->post(route('admin.customers.import'), [
                'csv_file' => $file,
            ])
            ->assertRedirect(route('admin.customers.index'));

        $this->assertDatabaseHas('customers', [
            'name' => 'Bunda Kila',
            'whatsapp_number' => '6281200000444',
            'status' => CustomerStatus::ACTIVE,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.customers.export'));
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertStringContainsString('Bunda Kila', $content);
        $this->assertStringContainsString('branch_code', $content);
    }

    public function test_admin_can_archive_master_data_and_write_audit_logs(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);

        $branch = Branch::create([
            'name' => 'Cabang Solo',
            'code' => 'SLO',
            'city' => 'Solo',
            'timezone' => 'Asia/Jakarta',
            'status' => UserStatus::ACTIVE,
        ]);

        $service = Service::create([
            'branch_id' => $branch->id,
            'name' => 'Baby Massage',
            'duration_minutes' => 60,
            'price' => 200000,
            'is_active' => true,
        ]);

        $therapist = Therapist::create([
            'branch_id' => $branch->id,
            'name' => 'Dina Terapis',
            'status' => UserStatus::ACTIVE,
        ]);

        $customer = Customer::create([
            'branch_id' => $branch->id,
            'name' => 'Bunda Rara',
            'whatsapp_number' => '6281200000555',
            'status' => CustomerStatus::ACTIVE,
        ]);

        $this->actingAs($admin)->post(route('admin.branches.archive', $branch))->assertRedirect(route('admin.branches.index'));
        $this->actingAs($admin)->post(route('admin.services.archive', $service))->assertRedirect(route('admin.services.index'));
        $this->actingAs($admin)->post(route('admin.therapists.archive', $therapist))->assertRedirect(route('admin.therapists.index'));
        $this->actingAs($admin)->post(route('admin.customers.archive', $customer))->assertRedirect(route('admin.customers.index'));

        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'status' => UserStatus::INACTIVE]);
        $this->assertDatabaseHas('services', ['id' => $service->id, 'is_active' => 0]);
        $this->assertDatabaseHas('therapists', ['id' => $therapist->id, 'status' => UserStatus::INACTIVE]);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => CustomerStatus::INACTIVE]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'branch.archived', 'auditable_type' => Branch::class, 'auditable_id' => $branch->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'service.archived', 'auditable_type' => Service::class, 'auditable_id' => $service->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'therapist.archived', 'auditable_type' => Therapist::class, 'auditable_id' => $therapist->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.archived', 'auditable_type' => Customer::class, 'auditable_id' => $customer->id]);
    }
}
