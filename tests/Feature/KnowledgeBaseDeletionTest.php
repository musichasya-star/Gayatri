<?php

namespace Tests\Feature;

use App\Models\AiLog;
use App\Models\AuditLog;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Services\AI\AiReplyEvidence;
use App\Services\AI\KnowledgeBaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KnowledgeBaseDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function knowledge(array $attributes = []): KnowledgeBase
    {
        $knowledge = KnowledgeBase::create(array_replace(['title' => 'Persiapan kunjungan', 'slug' => (string) Str::uuid(),
            'type' => 'faq', 'content' => 'Materi privat untuk pengujian penghapusan.', 'status' => 'active',
            'publication_status' => 'published', 'audience' => 'customer_public', 'revision' => 2], $attributes));
        $knowledge->domains()->create(['domain' => 'spa']);
        app(KnowledgeBaseService::class)->syncChunks($knowledge);

        return $knowledge;
    }

    public static function managers(): array
    {
        return [['owner'], ['manager'], ['admin']];
    }

    #[DataProvider('managers')]
    public function test_authorized_deletion_removes_only_selected_material_and_preserves_history(string $role): void
    {
        $actor = User::factory()->create(['role' => $role, 'status' => 'active']);
        $knowledge = $this->knowledge(['source_path' => 'knowledge-base/upload.txt']);
        Storage::disk('local')->put($knowledge->source_path, $knowledge->content);
        $other = $this->knowledge();
        $log = AiLog::create(['knowledge_base_id' => $knowledge->id, 'prompt' => 'Contoh pertanyaan', 'response' => 'Jawaban historis', 'status' => 'success']);
        $evidence = ['knowledge' => [$knowledge->id => app(AiReplyEvidence::class)->knowledgeHash($knowledge)]];
        $policyRevision = DB::table('ai_interaction_policies')->where('id', 1)->value('revision');

        $this->actingAs($actor)->delete(route('admin.ai.knowledge.destroy', $knowledge), ['revision' => 2, 'confirmed' => '1',
            '_list' => 'knowledge', '_filters' => ['domain' => 'spa', 'publication_status' => 'published']])
            ->assertRedirect(route('admin.ai.knowledge.index', ['domain' => 'spa', 'publication_status' => 'published']))
            ->assertSessionHas('status', 'Knowledge base berhasil dihapus.');

        $this->assertModelMissing($knowledge);
        $this->assertModelExists($other);
        $this->assertDatabaseMissing('knowledge_chunks', ['knowledge_base_id' => $knowledge->id]);
        $this->assertSame(0, $knowledge->domains()->count());
        Storage::disk('local')->assertMissing('knowledge-base/upload.txt');
        $this->assertSame('Jawaban historis', $log->fresh()->response);
        $this->assertNull($log->fresh()->knowledge_base_id);
        $this->assertSame('knowledge_changed', app(AiReplyEvidence::class)->problem($evidence, 0));
        $this->assertSame($policyRevision + 1, DB::table('ai_interaction_policies')->where('id', 1)->value('revision'));
        $audit = AuditLog::where('action', 'ai.knowledge.delete')->sole();
        $this->assertSame($actor->id, $audit->user_id);
        $this->assertSame($knowledge->id, $audit->auditable_id);
        $this->assertStringNotContainsString($knowledge->content, $audit->toJson());
    }

    public function test_guests_and_other_roles_cannot_delete_material(): void
    {
        $knowledge = $this->knowledge();
        $url = route('admin.ai.knowledge.destroy', $knowledge);
        $this->get(route('admin.ai.knowledge.confirm-destroy', $knowledge))->assertRedirect(route('login'));
        $this->delete($url, ['revision' => 2, 'confirmed' => 1])->assertRedirect(route('login'));
        foreach (['sales', 'therapist'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']))
                ->delete($url, ['revision' => 2, 'confirmed' => 1])->assertForbidden();
            $this->get(route('admin.ai.knowledge.confirm-destroy', $knowledge))->assertForbidden();
        }
        $this->assertModelExists($knowledge);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ai.knowledge.delete']);
    }

    public function test_confirmation_and_current_revision_are_required(): void
    {
        $knowledge = $this->knowledge(['source_path' => 'knowledge-base/upload.txt']);
        Storage::disk('local')->put($knowledge->source_path, 'Sumber tetap tersedia');
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $url = route('admin.ai.knowledge.destroy', $knowledge);
        $this->delete($url, ['revision' => 2])->assertSessionHasErrors('confirmed');
        $this->delete($url, ['revision' => 2, 'confirmed' => 0])->assertSessionHasErrors('confirmed');
        $this->delete($url, ['confirmed' => 1])->assertSessionHasErrors('revision');
        $this->from(route('admin.ai.knowledge.index'))->delete($url, ['revision' => 1, 'confirmed' => 1])->assertSessionHasErrors('revision');
        $this->get(route('admin.ai.knowledge.index'))->assertOk()->assertSee('Materi sudah berubah. Muat ulang daftar sebelum menghapus.');
        $this->assertModelExists($knowledge);
        Storage::disk('local')->assertExists($knowledge->source_path);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ai.knowledge.delete']);
    }

    public function test_delete_preserves_shared_sources_and_files_outside_the_knowledge_folder(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner', 'status' => 'active']));
        Storage::disk('local')->put('knowledge-base/shared.txt', 'Sumber bersama');
        $knowledge = $this->knowledge(['source_path' => 'knowledge-base/shared.txt']);
        $other = $this->knowledge(['source_path' => 'knowledge-base/shared.txt']);
        $this->delete(route('admin.ai.knowledge.destroy', $knowledge), ['revision' => 2, 'confirmed' => 1])->assertSessionHasNoErrors();
        Storage::disk('local')->assertExists('knowledge-base/shared.txt');
        $this->assertModelExists($other);
        Storage::disk('local')->put('private/customer.txt', 'Berkas CRM');
        foreach (['private/customer.txt', 'knowledge-base/../private/customer.txt'] as $path) {
            $knowledge = $this->knowledge(['source_path' => $path]);
            $this->delete(route('admin.ai.knowledge.destroy', $knowledge), ['revision' => 2, 'confirmed' => 1])->assertSessionHasNoErrors();
            Storage::disk('local')->assertExists('private/customer.txt');
        }
    }

    public function test_list_shows_delete_confirmation_and_escapes_the_material_title(): void
    {
        $knowledge = $this->knowledge(['title' => 'Materi "khusus" </script><script>alert(1)</script>']);
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']))
            ->get(route('admin.ai.knowledge.index'))->assertOk()
            ->assertSee(route('admin.ai.knowledge.destroy', $knowledge), false)
            ->assertSee(route('admin.ai.knowledge.confirm-destroy', $knowledge), false)
            ->assertSee('Hapus')->assertSee('data-knowledge-delete-dialog', false)
            ->assertSee('aria-labelledby="knowledge-delete-heading"', false)
            ->assertSee('knowledge-delete.js?v='.config('gayatri_frontend.knowledge_delete_version'), false)
            ->assertSee('name="_method" value="DELETE"', false)
            ->assertDontSee('onsubmit=', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    #[DataProvider('managers')]
    public function test_confirmation_page_works_without_javascript_and_does_not_delete_on_get(string $role): void
    {
        $knowledge = $this->knowledge();
        $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']))
            ->get(route('admin.ai.knowledge.confirm-destroy', ['knowledge' => $knowledge, '_list' => 'knowledge', '_filters' => ['domain' => 'spa']]))
            ->assertOk()->assertSee($knowledge->title)->assertSee('Hapus permanen')->assertSee('Batal')
            ->assertSee(route('admin.ai.knowledge.destroy', $knowledge), false)
            ->assertSee('name="revision" value="2"', false)
            ->assertSee(route('admin.ai.knowledge.index', ['domain' => 'spa']), false);
        $this->assertModelExists($knowledge);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ai.knowledge.delete']);
    }

    public function test_repeated_delete_returns_not_found_without_changing_other_material(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner', 'status' => 'active']));
        $knowledge = $this->knowledge();
        $url = route('admin.ai.knowledge.destroy', $knowledge);
        $this->delete($url, ['revision' => 2, 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->delete($url, ['revision' => 2, 'confirmed' => 1])->assertNotFound();
        $this->assertSame(1, AuditLog::where('action', 'ai.knowledge.delete')->count());
    }
}
