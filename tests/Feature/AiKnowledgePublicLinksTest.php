<?php

namespace Tests\Feature;

use App\Models\AiLog;
use App\Models\KnowledgeBase;
use App\Services\AI\AiPublicContent;
use App\Services\AI\AiReplyEvidence;
use App\Services\AI\AiService;
use App\Services\AI\KnowledgeBaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiKnowledgePublicLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://gayatri-kediri.web.id']);
    }

    private function website(): KnowledgeBase
    {
        $kb = KnowledgeBase::create(['title' => 'alamat website gayatri', 'slug' => 'alamat-website-gayatri', 'type' => 'text',
            'content' => 'alamat website gayatri dapat diakses dihttps://www.gayatri-kediri.web.id/',
            'status' => 'active', 'publication_status' => 'published', 'audience' => 'customer_public', 'is_demo' => false,
            'meta' => ['keywords' => ['website', 'link website', 'web', 'situs']]]);
        $kb->domains()->create(['domain' => 'general']);
        app(KnowledgeBaseService::class)->syncChunks($kb);

        return $kb;
    }

    public static function websiteQuestions(): array
    {
        return [['alamat web gayatri apa'], ['websitenya dimana?'], ['situs gayatri apa?'], ['link website Gayatri'], ['URL Gayatri apa?']];
    }

    #[DataProvider('websiteQuestions')]
    public function test_official_website_is_returned_from_knowledge_instead_of_a_menu_placeholder(string $question): void
    {
        $this->website();
        $answer = app(AiService::class)->simulate($question, null, ['domain' => 'rental', 'menu' => 'conversation']);
        $this->assertSame('general', $answer['routing']['domain']);
        $this->assertSame(['alamat-website-gayatri'], $answer['sources']);
        $this->assertStringContainsString('di https://www.gayatri-kediri.web.id/', $answer['reply']);
        $this->assertStringNotContainsString('tautan tersedia melalui menu', $answer['reply']);
        Http::assertNothingSent();
    }

    public function test_provider_receives_the_actual_approved_url_and_returns_a_direct_answer(): void
    {
        $kb = $this->website();
        config(['ai.provider' => 'openai', 'ai.model' => 'gpt-6-luna', 'ai.api_key' => 'fake-test-key']);
        Http::fake(['*chat/completions' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode([
            'reply' => 'Website Gayatri bisa dibuka di https://www.gayatri-kediri.web.id/', 'used_facts' => ['answer', 'kb_'.$kb->id],
        ])]]]])]);
        $answer = app(AiService::class)->simulate('alamat web gayatri apa');
        $this->assertSame('grounded_ai', $answer['reply_source']);
        $this->assertStringContainsString('https://www.gayatri-kediri.web.id/', $answer['reply']);
        Http::assertSent(function ($request) {
            $packet = json_decode($request['messages'][1]['content'], true);

            return str_contains($packet['public_facts']['answer'], 'https://www.gayatri-kediri.web.id/')
                && ! str_contains(json_encode($packet['public_facts']), 'tautan tersedia melalui menu');
        });
        $kb->update(['content' => 'Website baru perlu ditinjau.']);
        $this->assertSame('knowledge_changed', app(AiReplyEvidence::class)->problem($answer['evidence'], 0));
    }

    public function test_vague_model_reply_is_replaced_with_the_grounded_answer_containing_the_requested_link(): void
    {
        $this->website();
        config(['ai.provider' => 'openai', 'ai.model' => 'gpt-6-luna', 'ai.api_key' => 'fake-test-key']);
        Http::fake(['*' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode([
            'reply' => 'Alamat web Gayatri bisa diakses melalui tautan pada menu layanan.', 'used_facts' => ['answer'],
        ])]]]])]);
        $answer = app(AiService::class)->simulate('alamat web gayatri apa');
        $this->assertStringContainsString('https://www.gayatri-kediri.web.id/', $answer['reply']);
        $this->assertSame('unverified_output', AiLog::find($answer['log_id'])->meta['generation']['fallback_code']);
    }

    public function test_company_name_does_not_pull_physical_address_into_a_website_answer(): void
    {
        $this->website();
        $address = KnowledgeBase::create(['title' => 'alamat gayatri', 'slug' => 'alamat-gayatri', 'type' => 'text',
            'content' => 'alamat gayatri di Jalan Pengujian No.260, Kediri 64116. Owner Gayatri adalah Pengelola.',
            'status' => 'active', 'publication_status' => 'published', 'audience' => 'customer_public',
            'meta' => ['keywords' => ['alamat', 'gayatri', 'kantor']]]);
        $address->domains()->create(['domain' => 'general']);
        app(KnowledgeBaseService::class)->syncChunks($address);
        $result = app(AiService::class)->simulate('alamat web gayatri apa');
        $this->assertSame(['alamat-website-gayatri'], $result['sources']);
        $this->assertStringNotContainsString('No.260', $result['reply']);
        $this->assertStringContainsString('https://www.gayatri-kediri.web.id/', $result['reply']);
    }

    public function test_website_answer_can_omit_unrequested_numbers_from_the_same_source(): void
    {
        $kb = $this->website();
        $kb->update(['content' => $kb->content.' Kantor di Jalan Pengujian No.260, Kediri 64116.']);
        app(KnowledgeBaseService::class)->syncChunks($kb);
        config(['ai.provider' => 'openai', 'ai.model' => 'gpt-6-luna', 'ai.api_key' => 'fake-test-key']);
        Http::fake(['*' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode([
            'reply' => 'Website Gayatri: https://www.gayatri-kediri.web.id/', 'used_facts' => ['answer'],
        ])]]]])]);
        $result = app(AiService::class)->simulate('alamat web gayatri apa');
        $this->assertSame('grounded_ai', $result['reply_source']);
        $this->assertStringNotContainsString('260', $result['reply']);
        $this->assertStringNotContainsString('64116', $result['reply']);
    }

    public static function publicUrls(): array
    {
        $base = 'https://gayatri-kediri.web.id';

        return [
            [$base, true], ['https://www.gayatri-kediri.web.id/', true], [$base.'/booking?service_id=79', true],
            [$base.'/layanan?group=lactation', true], [$base.'/rental/products/3', true], [$base.'/laundry', true],
            [$base.'/login', false], [$base.'/admin/ai/knowledge-base', false], [$base.'/rental/portal/access/private-token', false],
            [$base.'/laundry/portal/11111111-1111-4111-8111-111111111111', false], [$base.'/rental/orders/RENT-PRIVATE', false],
            [$base.'/storage/private/customer.pdf', false], [$base.'/?token=private-value', false], [$base.'/?signature=private-value', false],
            [$base.'/layanan?search=token%3Dprivate-value', false], [$base.'/layanan?search[]=private', false],
            [$base.'/%252e%252e/admin', false], ['https://person:password@gayatri-kediri.web.id/', false],
            ['https://gayatri-kediri.web.id:3000/', false], ['https://gayatri-kediri.web.id.evil.example/', false],
            ['https://evil.example/', false], ['http://gayatri-kediri.web.id/', false],
        ];
    }

    #[DataProvider('publicUrls')]
    public function test_cleaner_keeps_only_official_public_routes_and_never_fetches_them(string $url, bool $allowed): void
    {
        $cleaner = app(AiPublicContent::class);
        $result = $cleaner->clean('Lihat '.$url);
        if ($allowed) {
            $this->assertStringContainsString($url, $result);
        } else {
            $this->assertStringNotContainsString($url, $result);
            $this->assertStringContainsString('[tautan tidak tersedia untuk jawaban otomatis]', $result);
        }
        Http::assertNothingSent();
    }

    public function test_other_public_facts_and_url_punctuation_are_preserved(): void
    {
        $text = 'Buka Senin–Minggu pukul 09.00–18.00. Website: https://gayatri-kediri.web.id/.';
        $this->assertSame($text, app(AiPublicContent::class)->clean($text));
        $this->assertSame('Lihat https://gayatri-kediri.web.id/booking?service_id=79',
            app(AiPublicContent::class)->clean('Lihat https://gayatri-kediri.web.id/booking?service_id=79'));
    }

    public function test_length_limit_never_turns_a_public_url_into_a_different_product_link(): void
    {
        $url = 'https://gayatri-kediri.web.id/rental/products/123';
        $text = 'Lihat alat ini: '.$url;
        $answer = app(AiPublicContent::class)->clean($text, strlen($text) - 1);
        $this->assertStringNotContainsString('https://', $answer);
        $this->assertSame('Lihat alat ini:...', $answer);
    }
}
