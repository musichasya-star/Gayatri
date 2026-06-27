<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CustomerRequest;
use App\Models\Branch;
use App\Models\Customer;
use App\Services\CRM\CustomerService;
use App\Support\CustomerStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $customerService) {}

    public function index(Request $request): View
    {
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

        return redirect()->route('admin.customers.show', $customer)->with('status', 'Customer berhasil dibuat.');
    }

    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $handle = fopen($data['csv_file']->getRealPath(), 'r');

        if ($handle === false) {
            return back()->withErrors(['csv_file' => 'File CSV tidak dapat dibaca.']);
        }

        $header = fgetcsv($handle);

        if (! is_array($header)) {
            fclose($handle);

            return back()->withErrors(['csv_file' => 'Header CSV tidak valid.']);
        }

        $normalizedHeader = array_map(fn ($column) => strtolower(trim((string) $column)), $header);
        $imported = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $row = array_pad($row, count($normalizedHeader), null);
            $rowData = array_combine($normalizedHeader, $row);

            if (! is_array($rowData) || blank(trim((string) ($rowData['name'] ?? '')))) {
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

            Customer::updateOrCreate(
                ['whatsapp_number' => $payload['whatsapp_number'] ?: '__csv__'.md5($payload['name'].'|'.($payload['phone'] ?? ''))],
                array_merge($payload, [
                    'whatsapp_number' => $payload['whatsapp_number'],
                ])
            );

            $imported++;
        }

        fclose($handle);

        return redirect()->route('admin.customers.index')->with('status', "Import customer selesai. {$imported} baris diproses.");
    }

    public function export(Request $request): StreamedResponse
    {
        $filename = 'customers-'.now()->format('Ymd-His').'.csv';

        $customers = Customer::query()
            ->with('branch')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('tag'), fn ($query) => $query->where('tags', 'like', '%'.$request->string('tag').'%'))
            ->orderBy('name')
            ->get();

        return response()->streamDownload(function () use ($customers) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['name', 'branch_code', 'phone', 'whatsapp_number', 'email', 'city', 'status', 'tags', 'notes']);

            foreach ($customers as $customer) {
                fputcsv($handle, [
                    $customer->name,
                    $customer->branch?->code,
                    $customer->phone,
                    $customer->whatsapp_number,
                    $customer->email,
                    $customer->city,
                    $customer->status,
                    is_array($customer->tags) ? implode(', ', $customer->tags) : '',
                    $customer->notes,
                ]);
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

        return redirect()->route('admin.customers.show', $customer)->with('status', 'Customer berhasil diperbarui.');
    }

    public function archive(Request $request, Customer $customer): RedirectResponse
    {
        $this->customerService->archive($customer, $request->user(), $request);

        return redirect()->route('admin.customers.index')->with('status', 'Customer berhasil diarsipkan.');
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
