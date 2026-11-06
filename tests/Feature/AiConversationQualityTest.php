<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Models\LaundryTariff;
use App\Models\Message;
use App\Models\RentalProduct;
use App\Models\Service;
use App\Services\AI\AiConversationContext;
use App\Services\AI\AiDomainRouter;
use App\Services\AI\AiService;
use App\Services\AI\KnowledgeBaseService;
use App\Services\AppSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiConversationQualityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['app.url' => 'http://localhost']);
    }

    private function catalogs(): void
    {
        Service::create(['name' => 'Pijat Bayi', 'service_group' => 'spa', 'price' => 100000, 'duration_minutes' => 60, 'is_active' => true]);
        Service::create(['name' => 'Konsultasi ASI', 'service_group' => 'lactation', 'price' => 150000, 'duration_minutes' => 45, 'is_active' => true]);
        RentalProduct::create(['sku' => 'STROLLER-QA', 'name' => 'Stroller Ringan', 'category' => 'Stroller', 'daily_rate' => 30000, 'deposit' => 100000, 'is_active' => true]);
        RentalProduct::create(['sku' => 'CHAIR-QA', 'name' => 'High Chair', 'category' => 'Kursi', 'daily_rate' => 25000, 'deposit' => 75000, 'is_active' => true]);
        LaundryTariff::create(['code' => 'QA-KG', 'name' => 'Cuci Lipat', 'audience' => 'both', 'unit' => 'kg', 'price' => 9000,
            'minimum_milli' => 1000, 'increment_milli' => 1000, 'duration_hours' => 24, 'is_active' => true]);
        app(AppSettingService::class)->set('laundry.public', json_encode(['enabled' => true]));
        app(AppSettingService::class)->set('laundry.outlet', json_encode(['accepting_orders' => true, 'address' => 'Outlet pengujian', 'whatsapp_number' => '628000000000']));
    }

    private function faq(string $domain, string $topic, string $content): void
    {
        $kb = KnowledgeBase::create(['title' => $topic, 'slug' => $domain.'-'.str_replace(' ', '-', $topic), 'type' => 'faq', 'content' => $content,
            'status' => 'active', 'publication_status' => 'published', 'audience' => 'customer_public']);
        $kb->domains()->create(['domain' => $domain]);
        app(KnowledgeBaseService::class)->syncChunks($kb);
    }

    public static function conversationalMessages(): array
    {
        return [
            ['brp hrg stroler?', 'rental', 'pricing'],
            ['mau pesen pijat bayi', 'spa', 'web_order'],
            ['pgn konsul ASI', 'lactation', 'web_order'],
            ['hrga londry brp min?', 'laundry', 'pricing'],
            ['gmn kebersihan stroller?', 'rental', 'faq'],
            ['bukan rental, mau tanya pijat', 'spa', 'domain_menu'],
            ['bkn spa, pgn konsul ASI', 'lactation', 'web_order'],
            ['batal tanya laundry, harga rental brp?', 'rental', 'pricing'],
            ['laundry noda asi gmn?', 'laundry', 'faq'],
            ['admin dong', null, 'human_handoff'],
            ['boleh dibantu admin?', null, 'human_handoff'],
            ['komplain laundry dan rental', null, 'human_handoff'],
            ['Bayi demam mau pijat dan laundry', null, 'medical'],
            ['mau rental dan laundry', null, 'clarify'],
            ['sewa pompa ASI berapa?', 'rental', 'pricing'],
            ['bukan rental, maksudnya laundry', 'laundry', 'domain_menu'],
            ['saya pilih rental saja', 'rental', 'domain_menu'],
            ['halo min mau tanya', null, 'greeting'],
            ['layanannya apa aja?', null, 'main_menu'],
        ];
    }

    #[DataProvider('conversationalMessages')]
    public function test_natural_messages_resolve_without_losing_safety_priority(string $message, ?string $domain, string $intent): void
    {
        $result = app(AiDomainRouter::class)->route($message);
        $this->assertSame($domain, $result['domain']);
        $this->assertSame($intent, $result['intent']);
    }

    public function test_combined_price_hygiene_and_payment_questions_all_receive_grounded_answers(): void
    {
        $this->catalogs();
        $this->faq('rental', 'Kebersihan perawatan rental', 'Unit diperiksa dan catatan perawatannya dapat ditanyakan kepada admin.');
        $answer = app(AiService::class)->simulate('Harga stroller berapa, kebersihannya gmn dan cara bayarnya?');
        foreach (['Rp30.000', 'Unit diperiksa', 'Pembayaran masih diperiksa admin', 'belum berarti', 'portal pribadi'] as $fragment) {
            $this->assertStringContainsString($fragment, $answer['reply']);
        }
        $this->assertSame('multi_intent_answer', $answer['routing']['reason_code']);
        $this->assertSame(['pricing', 'payment_help', 'faq'], $answer['routing']['answered_intents']);
        $this->assertStringNotContainsString('High Chair', $answer['reply']);
        $this->assertSame(1, substr_count($answer['reply'], 'http://localhost/rental'));
        $this->assertDatabaseCount('rental_orders', 0);
        $this->assertDatabaseCount('rental_payments', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_preparation_question_in_casual_language_finds_its_source_without_inventing_policy(): void
    {
        $this->faq('spa', 'Persiapan pijat', 'Siapkan perlengkapan pribadi sesuai petunjuk admin.');
        $reply = app(AiService::class)->simulate('Mau pijat bayi, apa yg perlu dibawa min?');
        $this->assertStringContainsString('Siapkan perlengkapan pribadi', $reply['reply']);
        $this->assertNotEmpty($reply['sources']);
        $this->assertStringNotContainsString('pasti aman', $reply['reply']);
    }

    public function test_catalog_focus_survives_short_followup_but_resets_on_domain_switch_or_all_catalog_request(): void
    {
        $this->catalogs();
        $ai = app(AiService::class);
        $first = $ai->simulate('Harga stroller?');
        $this->assertStringNotContainsString('High Chair', $first['reply']);
        $state = $first['next_context'];
        $followup = $ai->simulate('yang tadi brp?', null, $state);
        $this->assertStringContainsString('Rp30.000', $followup['reply']);
        $this->assertStringNotContainsString('High Chair', $followup['reply']);
        $all = $ai->simulate('lihat semua katalog', null, $state);
        $this->assertStringContainsString('High Chair', $all['reply']);
        $changed = $ai->simulate('harga konsultasi ASI', null, $state);
        $this->assertStringContainsString('Rp150.000', $changed['reply']);
        $this->assertStringNotContainsString('Stroller', $changed['reply']);
        $this->assertSame('lactation', $changed['next_context']['domain']);
    }

    public function test_removed_or_ambiguous_product_is_never_silently_selected(): void
    {
        $this->catalogs();
        RentalProduct::create(['sku' => 'SECOND', 'name' => 'Stroller Besar', 'category' => 'Stroller', 'daily_rate' => 40000, 'deposit' => 100000, 'is_active' => true]);
        $answer = app(AiService::class)->simulate('harga stroller');
        $this->assertNull($answer['next_context']['catalog_item_id']);
        $this->assertStringContainsString('Stroller Ringan', $answer['reply']);
        $this->assertStringContainsString('Stroller Besar', $answer['reply']);
        $this->assertStringNotContainsString('High Chair', $answer['reply']);
        $chosen = app(AiService::class)->simulate('Stroller Ringan', null, $answer['next_context']);
        $this->assertStringContainsString('Rp30.000', $chosen['reply']);
        $this->assertStringNotContainsString('Stroller Besar', $chosen['reply']);
        $product = RentalProduct::where('sku', 'STROLLER-QA')->first();
        $product->update(['is_active' => false]);
        $answer = app(AiService::class)->simulate('yang tadi brp?', null, ['domain' => 'rental', 'catalog_item_id' => $product->id]);
        $this->assertStringNotContainsString('Stroller Ringan', $answer['reply']);
    }

    public function test_explicit_domain_switch_exits_pending_lookup_without_using_private_state(): void
    {
        $this->catalogs();
        $answer = app(AiService::class)->simulate('harga laundry', null, ['domain' => 'spa', 'menu' => 'lookup',
            'lookup' => ['step' => 'phone', 'code' => 'BK-PRIVATE', 'expires_at' => now()->addMinute()->timestamp]]);
        $this->assertSame('laundry', $answer['routing']['domain']);
        $this->assertStringContainsString('Rp9.000', $answer['reply']);
        $this->assertArrayNotHasKey('lookup', $answer['evidence']);
        $this->assertStringNotContainsString('BK-PRIVATE', $answer['reply']);
    }

    public function test_multi_question_with_transaction_code_stays_on_verified_lookup_path(): void
    {
        $this->catalogs();
        $answer = app(AiService::class)->simulate('harga stroller dan cek status RENT-SECRET-1 08123456789');
        $this->assertSame('lookup_identity_unverified', $answer['fallback_reason']);
        $this->assertStringNotContainsString('Rp30.000', $answer['reply']);
        $this->assertStringNotContainsString('08123456789', $answer['reply']);
        $this->assertDatabaseCount('ai_lookup_attempts', 0);
    }

    public function test_lactation_treatment_name_uses_its_catalog_group(): void
    {
        Service::create(['name' => 'Pijat Laktasi', 'service_group' => 'lactation', 'price' => 170000, 'duration_minutes' => 45, 'is_active' => true]);
        $answer = app(AiService::class)->simulate('harga pijat laktasi?');
        $this->assertSame('lactation', $answer['routing']['domain']);
        $this->assertStringContainsString('Rp170.000', $answer['reply']);
        $mixed = app(AiDomainRouter::class)->route('pijat laktasi dan laundry');
        $this->assertSame('clarify', $mixed['intent']);
    }

    public function test_catalog_focus_is_committed_without_customer_text_and_expires_with_control_revision(): void
    {
        $this->catalogs();
        $customer = Customer::create(['name' => 'Penguji Konteks', 'whatsapp_number' => '6280000000851', 'status' => 'active']);
        $conversation = Conversation::create(['customer_id' => $customer->id, 'wa_chat_id' => '6280000000851@c.us', 'status' => 'open', 'ai_enabled' => true, 'channel' => 'whatsapp']);
        $source = Message::create(['conversation_id' => $conversation->id, 'customer_id' => $customer->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'message_type' => 'text', 'content' => 'harga stroller ringan']);
        $result = app(AiService::class)->generateReply($conversation, $source);
        DB::transaction(fn () => app(AiConversationContext::class)->commit($conversation, $source, $result['next_context']));
        $state = app(AiConversationContext::class)->read($conversation);
        $this->assertSame(RentalProduct::where('sku', 'STROLLER-QA')->value('id'), $state['catalog_item_id']);
        $this->assertArrayNotHasKey('message', $state);
        $conversation->update(['ai_enabled' => false]);
        $this->assertArrayNotHasKey('catalog_item_id', app(AiConversationContext::class)->read($conversation->fresh()));
        $this->travel(31)->minutes();
        $this->assertArrayNotHasKey('catalog_item_id', app(AiConversationContext::class)->read($conversation->fresh()));
    }
}
