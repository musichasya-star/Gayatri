<?php

namespace Tests\Feature;

use App\Models\AiLog;
use App\Models\AiPersona;
use App\Models\Branch;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Services\AI\AiGuardrailService;
use App\Services\AI\AiService;
use App\Services\AI\KnowledgeRetrievalService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_and_activate_single_persona(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'active']);
        AiPersona::create([
            'name' => 'Old Persona',
            'slug' => 'old-persona',
            'prompt' => 'Prompt lama',
            'tone' => 'old',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->post(route('admin.ai.personas.store'), [
                'name' => 'Gayatri Assistant Test',
                'prompt' => 'Jawab hanya berdasarkan knowledge base Gayatri.',
                'tone' => 'warm-professional',
                'language' => 'id',
                'fallback_style' => 'human-handoff',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.ai.personas.index'));

        $this->assertSame(1, AiPersona::where('is_active', true)->count());
        $this->assertDatabaseHas('ai_personas', [
            'name' => 'Gayatri Assistant Test',
            'is_active' => true,
        ]);
    }

    public function test_owner_can_create_knowledge_base_and_chunks_are_generated(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'active']);

        $this->actingAs($owner)
            ->post(route('admin.ai.knowledge.store'), [
                'title' => 'FAQ Baby Spa',
                'type' => 'faq',
                'content' => 'Baby spa tersedia pukul 09.00 sampai 18.00. Booking wajib memilih jadwal terlebih dahulu.',
                'status' => 'active',
            ])
            ->assertRedirect(route('admin.ai.knowledge.index'));

        $knowledge = KnowledgeBase::where('title', 'FAQ Baby Spa')->firstOrFail();

        $this->assertTrue($knowledge->chunks()->exists());
    }

    public function test_owner_can_create_knowledge_base_from_uploaded_file(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'active']);
        $file = UploadedFile::fake()->createWithContent('faq.md', 'Baby spa tersedia dari jam 09.00 sampai 18.00.');

        $this->actingAs($owner)
            ->post(route('admin.ai.knowledge.store'), [
                'title' => 'Upload FAQ Baby Spa',
                'type' => 'faq',
                'status' => 'active',
                'file' => $file,
            ])
            ->assertRedirect(route('admin.ai.knowledge.index'));

        $knowledge = KnowledgeBase::where('title', 'Upload FAQ Baby Spa')->firstOrFail();

        $this->assertNotNull($knowledge->source_path);
        $this->assertStringContainsString('Baby spa tersedia', $knowledge->content);
        $this->assertTrue($knowledge->chunks()->exists());
    }

    public function test_knowledge_base_upload_rejects_unsupported_file_type(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'active']);
        $file = UploadedFile::fake()->create('payload.exe', 10, 'application/octet-stream');

        $this->actingAs($owner)
            ->post(route('admin.ai.knowledge.store'), [
                'title' => 'Upload Berbahaya',
                'type' => 'faq',
                'status' => 'active',
                'file' => $file,
            ])
            ->assertSessionHasErrors('file');
    }

    public function test_simulator_generates_ai_log_for_safe_question(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        AiPersona::create([
            'name' => 'Gayatri Assistant',
            'slug' => 'gayatri-assistant',
            'prompt' => 'Jawab customer dengan ramah.',
            'tone' => 'warm',
            'is_active' => true,
        ]);
        KnowledgeBase::create([
            'title' => 'Jadwal Baby Spa',
            'slug' => 'jadwal-baby-spa',
            'type' => 'faq',
            'content' => 'Baby spa tersedia pukul 09.00 sampai 18.00.',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.ai.simulator.test'), [
                'message' => 'Mau tanya jadwal baby spa',
            ])
            ->assertOk()
            ->assertSee('Hasil Simulator')
            ->assertSee('success');

        $this->assertDatabaseHas('ai_logs', [
            'status' => 'success',
            'fallback_reason' => null,
        ]);
    }

    public function test_simulator_escalates_medical_question(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        AiPersona::create([
            'name' => 'Gayatri Assistant',
            'slug' => 'gayatri-assistant',
            'prompt' => 'Jawab customer dengan ramah.',
            'tone' => 'warm',
            'is_active' => true,
        ]);
        KnowledgeBase::create([
            'title' => 'Baby Spa',
            'slug' => 'baby-spa',
            'type' => 'faq',
            'content' => 'Baby spa adalah layanan relaksasi bayi.',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.ai.simulator.test'), [
                'message' => 'Bayi saya demam, boleh dipijat tidak?',
            ])
            ->assertOk()
            ->assertSee('medical');

        $this->assertDatabaseHas('ai_logs', [
            'status' => 'escalated',
            'fallback_reason' => 'medical',
        ]);
    }

    public function test_ai_service_falls_back_when_provider_errors(): void
    {
        $persona = AiPersona::create([
            'name' => 'Gayatri Assistant',
            'slug' => 'gayatri-assistant',
            'prompt' => 'Jawab customer dengan ramah.',
            'tone' => 'warm',
            'is_active' => true,
        ]);

        $service = new AiService(
            new class extends KnowledgeRetrievalService
            {
                public function retrieve(string $query, int $limit = 5): Collection
                {
                    throw new \RuntimeException('Provider down');
                }
            },
            app(AiGuardrailService::class),
        );

        $result = $service->simulate('Mau tanya jadwal baby spa', $persona);

        $this->assertSame('escalated', $result['status']);
        $this->assertSame('provider_error', $result['fallback_reason']);
        $this->assertDatabaseHas('ai_logs', [
            'status' => 'escalated',
            'fallback_reason' => 'provider_error',
        ]);
    }

    public function test_ai_booking_and_schedule_replies_are_contextual_not_repetitive_knowledge_dump(): void
    {
        AiPersona::create([
            'name' => 'Gayatri Assistant',
            'slug' => 'gayatri-assistant',
            'prompt' => 'Jawab customer dengan ramah.',
            'tone' => 'warm',
            'is_active' => true,
        ]);
        KnowledgeBase::create([
            'title' => 'Layanan dan Jadwal Dasar',
            'slug' => 'layanan-dan-jadwal-dasar',
            'type' => 'faq',
            'content' => "Gayatri menyediakan baby spa, mom massage, dan konsultasi booking dasar.\nJam operasional 09.00-18.00.\nUntuk kondisi bayi yang sedang demam, sakit, atau memiliki keluhan kesehatan, sarankan Bunda berkonsultasi dengan dokter sebelum mengambil layanan relaksasi.",
            'status' => 'active',
        ]);

        $booking = app(AiService::class)->simulate('Saya ingin booking');
        $schedule = app(AiService::class)->simulate('Hari apa yang ready?');

        $this->assertStringContainsString('booking', strtolower($booking['reply']));
        $this->assertStringContainsString('09.00-18.00', $schedule['reply']);
        $this->assertStringNotContainsString('dokter', strtolower($booking['reply']));
        $this->assertStringNotContainsString('keluhan kesehatan', strtolower($schedule['reply']));
        $this->assertStringNotContainsString('berikut informasi dari Gayatri', $booking['reply']);
    }

    public function test_ai_address_reply_uses_branch_address_without_dumping_unrelated_knowledge(): void
    {
        config()->set('ai.provider', 'local');
        AiPersona::create([
            'name' => 'Gayatri Assistant',
            'slug' => 'gayatri-assistant',
            'prompt' => 'Jawab customer dengan ramah.',
            'tone' => 'warm',
            'is_active' => true,
        ]);
        Branch::create([
            'name' => 'Gayatri Mom & Baby SPA Jakarta',
            'code' => 'JKT',
            'address' => 'Jl. Mawar No. 10',
            'city' => 'Jakarta Selatan',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
        ]);
        KnowledgeBase::create([
            'title' => 'Layanan dan Jadwal Dasar',
            'slug' => 'layanan-dan-jadwal-dasar',
            'type' => 'faq',
            'content' => "Gayatri menyediakan baby spa, mom massage, dan konsultasi booking dasar.\nJam operasional 09.00-18.00.\nUntuk kondisi bayi yang sedang demam, sakit, atau memiliki keluhan kesehatan, sarankan Bunda berkonsultasi dengan dokter sebelum mengambil layanan relaksasi.",
            'status' => 'active',
        ]);

        $result = app(AiService::class)->simulate('Dimana alamat Gayatri?');

        $this->assertStringContainsString('Jl. Mawar No. 10', $result['reply']);
        $this->assertStringNotContainsString('demam', strtolower($result['reply']));
        $this->assertStringNotContainsString('berikut informasi dari Gayatri', $result['reply']);
    }

    public function test_admin_can_view_ai_logs_with_simple_pagination_and_clear_logs(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        for ($i = 1; $i <= 25; $i++) {
            AiLog::create([
                'prompt' => 'Prompt '.$i,
                'response' => 'Response '.$i,
                'status' => 'success',
                'confidence' => 0.9,
                'sources' => [],
            ]);
        }

        $this->actingAs($admin)
            ->get(route('admin.ai.logs.index'))
            ->assertOk()
            ->assertSee('Clear Log')
            ->assertSee('Sebelumnya')
            ->assertSee('Berikutnya')
            ->assertSee('Halaman 1 / 2')
            ->assertDontSee('<svg', false);

        $this->actingAs($admin)
            ->post(route('admin.ai.logs.clear'))
            ->assertRedirect(route('admin.ai.logs.index'));

        $this->assertDatabaseCount('ai_logs', 0);
    }
}
