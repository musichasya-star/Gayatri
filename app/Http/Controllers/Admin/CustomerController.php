<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CustomerRequest;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LaundryOrder;
use App\Services\CRM\CustomerService;
use App\Support\AdminReviewContext;
use App\Support\CustomerPhone;
use App\Support\CustomerStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $customerService) {}

    public function index(Request $request): View
    {
        $request->validate(['search' => ['nullable', 'string', 'max:150'], 'page' => ['nullable', 'integer', 'min:1'], 'status' => ['nullable', Rule::in(CustomerStatus::all())], 'tag' => ['nullable', 'string', 'max:100']]);
        $customers = Customer::query()
            ->with(['branch', 'latestConversation'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');
                $digits = preg_replace('/\D+/', '', (string) $search);

                $query->where(function ($query) use ($search, $digits) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('whatsapp_number', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereHas('conversations', fn ($conversation) => $conversation->where('wa_chat_id', 'like', "%{$search}%"));

                    if ($digits) {
                        $query->orWhere('phone', 'like', "%{$digits}%")
                            ->orWhere('whatsapp_number', 'like', "%{$digits}%");
                    }
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('tag'), fn ($query) => $query->where('tags', 'like', '%'.$request->string('tag').'%'))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.customers.index', [
            'customers' => $customers,
            'statuses' => CustomerStatus::all(),
        ]);
    }

    public function create(): View
    {
        return view('admin.customers.form', [
            'customer' => new Customer(['status' => CustomerStatus::LEAD]),
            'branches' => Branch::orderBy('name')->get(),
            'statuses' => CustomerStatus::all(),
        ]);
    }

    public function store(CustomerRequest $request): RedirectResponse
    {
        $customer = $this->customerService->create($this->validatedData($request), $request->user(), $request);

        return redirect()->route('admin.customers.show', array_merge(['customer' => $customer], AdminReviewContext::carry($request, 'customers')))->with('status', 'Customer berhasil dibuat.');
    }

    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'update_existing' => ['nullable', 'boolean'],
            'confirmed_updates' => ['exclude_unless:update_existing,1', 'required', 'accepted'],
        ]);

        if ($request->boolean('update_existing')) {
            abort_unless($request->user()->hasRole(['owner', 'manager']), 403);
        }
        $skipped = 0;
        $handle = fopen($data['csv_file']->getRealPath(), 'r');

        if ($handle === false) {
            return back()->withErrors(['csv_file' => 'File CSV tidak dapat dibaca.']);
        }

        $header = fgetcsv($handle);

        if (! is_array($header)) {
            fclose($handle);

            return back()->withErrors(['csv_file' => 'Header CSV tidak valid.']);
        }

        $header[0] = ltrim((string) ($header[0] ?? ''), "\xEF\xBB\xBF");
        $normalizedHeader = array_map(fn ($column) => strtolower(trim((string) $column)), $header);
        if (! in_array('name', $normalizedHeader, true) || count(array_unique($normalizedHeader)) !== count($normalizedHeader)) {
            fclose($handle);

            return back()->withErrors(['csv_file' => 'CSV memerlukan header name dan tidak boleh memiliki nama kolom duplikat.']);
        }
        $imported = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $row = array_slice(array_pad($row, count($normalizedHeader), null), 0, count($normalizedHeader));
            $rowData = array_combine($normalizedHeader, $row);

            if (! is_array($rowData) || blank(trim((string) ($rowData['name'] ?? '')))) {
                $skipped++;

                continue;
            }

            $payload = [
                'branch_id' => $this->resolveBranchId($rowData['branch_code'] ?? null),
                'name' => trim((string) $rowData['name']),
                'phone' => $this->nullableString($rowData['phone'] ?? null),
                'whatsapp_number' => $this->nullableString($rowData['whatsapp_number'] ?? null),
                'email' => $this->nullableString($rowData['email'] ?? null),
                'city' => $this->nullableString($rowData['city'] ?? null),
                'status' => in_array(($rowData['status'] ?? ''), CustomerStatus::all(), true) ? $rowData['status'] : CustomerStatus::LEAD,
                'notes' => $this->nullableString($rowData['notes'] ?? null),
                'tags' => collect(explode(',', (string) ($rowData['tags'] ?? '')))
                    ->map(fn (string $tag) => trim($tag))
                    ->filter()
                    ->values()
                    ->all(),
            ];

            if (Validator::make($payload, [
                'name' => ['required', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:30'],
                'email' => ['nullable', 'email', 'max:255'],
                'city' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string', 'max:10000'],
            ])->fails()) {
                $skipped++;

                continue;
            }

            if ($payload['whatsapp_number']) {
                try {
                    $payload['whatsapp_number'] = CustomerPhone::normalize($payload['whatsapp_number']);
                } catch (\InvalidArgumentException) {
                    $skipped++;

                    continue;
                }
            }
            $matches = $payload['whatsapp_number'] ? CustomerPhone::matching($payload['whatsapp_number'])->limit(2)->get() : collect();
            if ($matches->count() > 1) {
                $skipped++;

                continue;
            }
            $existing = $matches->first();
            if ($existing) {
                if (! $request->boolean('update_existing')) {
                    $skipped++;

                    continue;
                }
                $updates = collect($payload)->except('status', 'notes', 'whatsapp_number')->filter(fn ($value) => $value !== null && $value !== '' && $value !== [])->all();
                if ($payload['notes']) {
                    $updates['notes'] = trim(($existing->notes ?? '')."\nCatatan impor: ".$payload['notes']);
                }
                $this->customerService->update($existing, $updates, $request->user(), $request);
            } else {
                $this->customerService->create($payload, $request->user(), $request);
            }

            $imported++;
        }

        fclose($handle);

        return redirect(AdminReviewContext::back($request, 'customers'))->with('status', "Import customer selesai. {$imported} baris diproses; {$skipped} baris dilewati (duplikat tanpa izin pembaruan atau nomor tidak valid).");
    }

    public function export(Request $request): StreamedResponse
    {
        $filename = 'customers-'.now()->format('Ymd-His').'.csv';

        $customers = Customer::query()
            ->with('branch')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('tag'), fn ($query) => $query->where('tags', 'like', '%'.$request->string('tag').'%'))
            ->orderBy('id')
            ->lazyById(200);

        return response()->streamDownload(function () use ($customers) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['name', 'branch_code', 'phone', 'whatsapp_number', 'email', 'city', 'status', 'tags', 'notes']);

            $safe = fn ($value) => preg_match('/^[\s\x00-\x1f]*[=+@\-]|^[\t\r\n]/u', (string) $value) ? "'".$value : $value;
            foreach ($customers as $customer) {
                fputcsv($handle, array_map($safe, [
                    $customer->name,
                    $customer->branch?->code,
                    $customer->phone,
                    $customer->whatsapp_number,
                    $customer->email,
                    $customer->city,
                    $customer->status,
                    is_array($customer->tags) ? implode(', ', $customer->tags) : '',
                    $customer->notes,
                ]));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function show(Customer $customer): View
    {
        $customer->load([
            'branch',
            'conversations.assignedUser',
            'bookings.service',
            'followups.assignedUser',
            'campaignRecipients.campaign',
            'feedback',
        ]);

        return view('admin.customers.show', [
            'customer' => $customer,
            'laundryOrders' => request()->user()->hasRole(['owner', 'manager', 'admin']) ? LaundryOrder::where('customer_id', $customer->id)->latest('received_at')->limit(3)->get() : collect(),
        ]);
    }

    public function edit(Customer $customer): View
    {
        return view('admin.customers.form', [
            'customer' => $customer,
            'branches' => Branch::orderBy('name')->get(),
            'statuses' => CustomerStatus::all(),
        ]);
    }

    public function update(CustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->customerService->update($customer, $this->validatedData($request), $request->user(), $request);

        return redirect()->route('admin.customers.show', array_merge(['customer' => $customer], AdminReviewContext::carry($request, 'customers')))->with('status', 'Customer berhasil diperbarui.');
    }

    public function archive(Request $request, Customer $customer): RedirectResponse
    {
        $this->customerService->archive($customer, $request->user(), $request);

        return redirect(AdminReviewContext::back($request, 'customers'))->with('status', 'Customer berhasil diarsipkan.');
    }

    public function destroy(Request $request, Customer $customer): RedirectResponse
    {
        $this->customerService->deleteWithRelations($customer, $request->user(), $request);

        return redirect(AdminReviewContext::back($request, 'customers'))->with('status', 'Customer dan semua data terkait berhasil dihapus.');
    }

    private function validatedData(CustomerRequest $request): array
    {
        $data = $request->validated();
        $data['tags'] = collect(explode(',', (string) ($data['tags'] ?? '')))
            ->map(fn (string $tag) => trim($tag))
            ->filter()
            ->values()
            ->all();

        return $data;
    }

    private function resolveBranchId(mixed $branchCode): ?int
    {
        $branchCode = $this->nullableString($branchCode);

        if ($branchCode === null) {
            return null;
        }

        return Branch::where('code', $branchCode)->value('id');
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
