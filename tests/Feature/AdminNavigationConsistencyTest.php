<?php

namespace Tests\Feature;

use App\Models\LaundryTariff;
use App\Models\RentalProduct;
use App\Models\RetailProduct;
use App\Models\Service;
use App\Models\User;
use App\Support\AdminReviewContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class AdminNavigationConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake(['*' => Http::response([], 200)]);
        $this->actingAs(User::factory()->create(['role' => 'owner', 'status' => 'active']));
    }

    private function dom(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new \DOMXPath($dom);
    }

    private function assertQuery(string $url, array $expected): void
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $actual);
        $this->assertEquals($expected, $actual);
    }

    public function test_every_sidebar_destination_renders_without_template_leaks_and_has_one_active_menu(): void
    {
        $start = $this->dom($this->get(route('admin.dashboard'))->assertOk()->getContent());
        $links = [];
        foreach ($start->query('//aside//nav//a[@href]') as $link) {
            $links[$link->getAttribute('href')] = trim($link->textContent);
        }
        $this->assertGreaterThan(40, count($links));
        foreach ($links as $url => $label) {
            $response = $this->get($url)->assertOk();
            $dom = $this->dom($response->getContent());
            $this->assertCount(1, $dom->query('//h1'), $label);
            $active = $dom->query('//aside//a[@aria-current="page"]');
            $this->assertCount(1, $active, $label);
            $this->assertSame($label, trim($active->item(0)->textContent));
            $content = $dom->query('//*[@id="main-content"]')->item(0)->textContent;
            $this->assertDoesNotMatchRegularExpression('/@(if|endif|foreach|endforeach|php|endphp)\b|\$compactNavigation/', $content, $label);
        }
        Queue::assertNothingPushed();
    }

    public function test_secondary_navigation_is_compact_on_reports_and_marks_the_correct_parent_on_forms(): void
    {
        foreach (['admin.laundry.reports', 'admin.rentals.reports'] as $route) {
            $dom = $this->dom($this->get(route($route))->assertOk()->getContent());
            $this->assertCount(1, $dom->query('//nav[contains(@class,"module-nav")]//a[@aria-current="page"]'));
            $this->assertCount(0, $dom->query('//details[contains(@class,"ops-menu")][@open]'));
        }
        foreach (['admin.laundry.requests.settings' => 'Permintaan Laundry', 'admin.laundry.requests.proofs' => 'Bukti Pembayaran Laundry', 'admin.laundry.tariffs.create' => 'Katalog Tarif Laundry', 'admin.rentals.products.create' => 'Katalog Rental'] as $route => $label) {
            $dom = $this->dom($this->get(route($route))->assertOk()->getContent());
            $active = $dom->query('//aside//a[@aria-current="page"]');
            $this->assertCount(1, $active);
            $this->assertSame($label, trim($active->item(0)->textContent));
            $this->assertCount(1, $dom->query('//nav[contains(@class,"module-nav")]//a[@aria-current="page"]'));
        }
    }

    public function test_catalogue_list_edit_cancel_and_save_preserve_search_and_all_publications(): void
    {
        $service = Service::create(['name' => 'Temu Treatment', 'service_group' => 'spa', 'price' => 90000, 'duration_minutes' => 60, 'booking_mode' => 'online', 'is_active' => false]);
        $rental = RentalProduct::create(['sku' => 'NAV-RENT', 'name' => 'Temu Alat', 'category' => 'Pompa', 'daily_rate' => 15000, 'deposit' => 0, 'turnaround_days' => 1, 'is_active' => false]);
        $tariff = LaundryTariff::create(['code' => 'NAV-TARIF', 'name' => 'Temu Laundry', 'audience' => 'baby', 'unit' => 'piece', 'price' => 10000, 'minimum_milli' => 1000, 'increment_milli' => 1000, 'duration_hours' => 24, 'is_active' => false]);
        $retail = RetailProduct::create(['sku' => 'NAV-SHOP', 'name' => 'Temu Minyak', 'category' => 'Minyak', 'price' => 20000, 'is_active' => false]);
        $cases = [
            ['services', 'admin.services', 'service', $service, $service->only(['name', 'service_group', 'price', 'duration_minutes', 'booking_mode', 'is_active']) + ['catalogue_form' => 1]],
            ['rental-products', 'admin.rentals.products', 'product', $rental, $rental->only(['sku', 'name', 'category', 'daily_rate', 'deposit', 'turnaround_days', 'is_active'])],
            ['laundry-tariffs', 'admin.laundry.tariffs', 'tariff', $tariff, $tariff->only(['code', 'name', 'audience', 'unit', 'price', 'duration_hours', 'is_active']) + ['minimum_quantity' => 1, 'rounding_increment' => 1]],
            ['retail', 'admin.retail', 'product', $retail, $retail->only(['sku', 'name', 'category', 'price', 'is_active'])],
        ];
        foreach ($cases as [$module, $base, $parameter, $record, $payload]) {
            foreach (['daily_rate', 'deposit', 'price'] as $field) {
                if (isset($payload[$field])) {
                    $payload[$field] = (int) $payload[$field];
                }
            }
            $filters = ['search' => 'Temu', 'active' => '', 'page' => 1];
            if ($module === 'services') {
                $filters['service_group'] = 'spa';
            }
            $context = ['_list' => $module, '_filters' => $filters];
            $edit = route($base.'.edit', [$parameter => $record] + $context);
            $list = $this->dom($this->get(route($base.'.index', $filters))->assertOk()->getContent());
            $editLink = $list->query('//a[starts-with(@href,"'.route($base.'.edit', $record).'")]')->item(0);
            $this->assertNotNull($editLink);
            $this->assertQuery($editLink->getAttribute('href'), $context);
            $form = $this->get($edit)->assertOk()->assertSee('name="_filters[active]" value=""', false);
            $cancel = $this->dom($form->getContent())->query('//a[starts-with(@href,"'.route($base.'.index').'")][contains(@href,"search=")]')->item(0);
            $this->assertNotNull($cancel);
            $this->assertQuery($cancel->getAttribute('href'), $filters);
            $saved = $this->put(route($base.'.update', $record), $payload + $context)->assertSessionHasNoErrors()->assertRedirect()->assertSessionHas('status');
            $this->assertSame(parse_url(route($base.'.index'), PHP_URL_PATH), parse_url($saved->headers->get('Location'), PHP_URL_PATH));
            $this->assertQuery($saved->headers->get('Location'), $filters);
            $this->get(route($base.'.index', $filters))->assertOk()->assertSee($record->name);
        }
    }

    public function test_return_context_cannot_redirect_outside_the_module_or_include_invalid_filter_shapes(): void
    {
        foreach (['services' => 'admin.services.index', 'rental-products' => 'admin.rentals.products.index', 'laundry-tariffs' => 'admin.laundry.tariffs.index', 'retail' => 'admin.retail.index'] as $module => $route) {
            $request = Request::create('/', 'POST', ['_list' => $module, '_filters' => ['active' => '0', 'search' => 'Temu', 'redirect' => 'https://example.invalid']]);
            $this->assertSame(route($route, ['search' => 'Temu', 'active' => '0']), AdminReviewContext::back($request, $module));
            foreach ([['search' => ['bad']], ['page' => -1], ['active' => 'not-valid']] as $filters) {
                $request = Request::create('/', 'POST', ['_list' => $module, '_filters' => $filters]);
                $this->assertSame(route($route), AdminReviewContext::back($request, $module));
            }
        }
    }

    public function test_laundry_and_rental_feedback_explains_failed_saves_and_success_once(): void
    {
        foreach (['admin.laundry.tariffs.create', 'admin.rentals.products.create'] as $route) {
            $dom = $this->dom($this->withSession(['status' => 'Tersimpan untuk uji navigasi', 'errors' => (new ViewErrorBag)->put('default', new MessageBag(['name' => 'Nama wajib diisi.']))])->get(route($route))->assertOk()->getContent());
            $this->assertCount(1, $dom->query('//*[@role="alert"][contains(@class,"admin-feedback")]'));
            $this->assertCount(1, $dom->query('//*[@role="status"][contains(@class,"admin-feedback")]'));
            $this->assertStringContainsString('Nama wajib diisi.', $dom->query('//*[@role="alert"]')->item(0)->textContent);
        }
    }
}
