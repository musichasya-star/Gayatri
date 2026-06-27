<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBase;
use App\Services\AI\KnowledgeBaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class KnowledgeBaseController extends Controller
{
    public function __construct(private readonly KnowledgeBaseService $knowledgeBaseService) {}

    public function index(Request $request): View
    {
        $knowledgeBases = KnowledgeBase::query()
            ->withCount('chunks')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');
                $query->where('title', 'like', "%{$search}%")->orWhere('content', 'like', "%{$search}%");
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.ai.knowledge.index', compact('knowledgeBases'));
    }

    public function create(): View
    {
        return view('admin.ai.knowledge.form', [
            'knowledge' => new KnowledgeBase(['type' => 'text', 'status' => 'active']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedData($request);
        $data['slug'] = $this->knowledgeBaseService->makeSlug($data['title']);
        $data = $this->applyUploadedFile($request, $data);

        $knowledge = KnowledgeBase::create($data);
        $this->knowledgeBaseService->syncChunks($knowledge);

        return redirect()->route('admin.ai.knowledge.index')->with('status', 'Knowledge base berhasil dibuat.');
    }

    public function edit(KnowledgeBase $knowledge): View
    {
        return view('admin.ai.knowledge.form', compact('knowledge'));
    }

    public function update(Request $request, KnowledgeBase $knowledge): RedirectResponse
    {
        $data = $this->validatedData($request);
        $data['slug'] = $this->knowledgeBaseService->makeSlug($data['title'], $knowledge->id);
        $data = $this->applyUploadedFile($request, $data, $knowledge);

        $knowledge->update($data);
        $this->knowledgeBaseService->syncChunks($knowledge);

        return redirect()->route('admin.ai.knowledge.index')->with('status', 'Knowledge base berhasil diperbarui.');
    }

    public function toggle(KnowledgeBase $knowledge): RedirectResponse
    {
        $knowledge->update(['status' => $knowledge->status === 'active' ? 'inactive' : 'active']);

        return back()->with('status', 'Status knowledge base berhasil diubah.');
    }

    private function validatedData(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:60'],
            'content' => ['nullable', 'required_without:file', 'string'],
            'file' => ['nullable', 'file', 'max:5120', 'mimes:txt,md,csv,json'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);
    }

    private function applyUploadedFile(Request $request, array $data, ?KnowledgeBase $knowledge = null): array
    {
        if (! $request->hasFile('file')) {
            return $data;
        }

        $file = $request->file('file');
        $path = $file->store('knowledge-base');
        $content = file_get_contents($file->getRealPath());

        $data['source_path'] = $path;
        $data['mime_type'] = $file->getClientMimeType();
        $data['content'] = trim((string) $content) ?: ($data['content'] ?? $knowledge?->content);

        return $data;
    }
}
