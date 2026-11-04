<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBase;
use App\Services\AI\AiInteractionPolicy;
use App\Services\AI\AiKnowledgeReadiness;
use App\Services\AI\AiPublicContent;
use App\Services\AI\KnowledgeBaseService;
use App\Services\CRM\AuditLogService;
use App\Support\AdminReviewContext;
use App\Support\AiDomain;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class KnowledgeBaseController extends Controller
{
    public function __construct(private readonly KnowledgeBaseService $knowledgeBaseService) {}

    public function index(Request $request): View
    {
        $request->validate(['search' => ['nullable', 'string', 'max:150'], 'domain' => ['nullable', Rule::in(array_keys(AiDomain::LABELS))], 'publication_status' => ['nullable', 'in:draft,needs_review,published,archived'], 'status' => ['nullable', 'in:active,inactive'], 'demo' => ['nullable', 'in:real,demo'], 'page' => ['nullable', 'integer', 'min:1']]);
        $knowledgeBases = KnowledgeBase::query()
            ->withCount('chunks')->with('domains')
            ->when($request->filled('demo'), fn ($q) => $q->where('is_demo', $request->input('demo') === 'demo'))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');
                $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")->orWhere('content', 'like', "%{$search}%"));
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('domain'), fn ($query) => $query->whereHas('domains', fn ($d) => $d->where('domain', $request->string('domain'))))
            ->when($request->filled('publication_status'), fn ($query) => $query->where('publication_status', $request->string('publication_status')))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $overview = ['published' => KnowledgeBase::where('is_demo', false)->where('publication_status', 'published')->count(),
            'draft' => KnowledgeBase::where('is_demo', false)->where('publication_status', 'draft')->count(),
            'needs_review' => KnowledgeBase::where('is_demo', false)->where('publication_status', 'needs_review')->count(), 'demo' => KnowledgeBase::where('is_demo', true)->count()];

        $readiness = app(AiKnowledgeReadiness::class)->report();

        return view('admin.ai.knowledge.index', compact('knowledgeBases', 'overview', 'readiness'));
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

        $this->saveKnowledge($request, new KnowledgeBase, $data);

        return redirect(AdminReviewContext::back($request, 'knowledge'))->with('status', 'Knowledge base berhasil dibuat.');
    }

    public function edit(KnowledgeBase $knowledge): View
    {
        return view('admin.ai.knowledge.form', compact('knowledge'));
    }

    public function update(Request $request, KnowledgeBase $knowledge): RedirectResponse
    {
        if (! app(AiInteractionPolicy::class)->allowsLegacyAutomation()) {
            $request->validate(['revision' => 'required|integer|min:1']);
        }
        $data = $this->validatedData($request);
        $data['slug'] = $this->knowledgeBaseService->makeSlug($data['title'], $knowledge->id);
        $data = $this->applyUploadedFile($request, $data, $knowledge);

        $this->saveKnowledge($request, $knowledge, $data);

        return redirect(AdminReviewContext::back($request, 'knowledge'))->with('status', 'Knowledge base berhasil diperbarui.');
    }

    public function toggle(Request $request, KnowledgeBase $knowledge): RedirectResponse
    {
        if (! app(AiInteractionPolicy::class)->allowsLegacyAutomation()) {
            $request->validate(['revision' => 'required|integer|min:1']);
        }
        if ($knowledge->status !== 'active' && $knowledge->publication_status === 'published') {
            abort_unless($request->user()->hasRole(['owner', 'manager']), 403);
        }
        DB::transaction(function () use ($knowledge, $request) {
            $knowledge = KnowledgeBase::whereKey($knowledge->id)->lockForUpdate()->firstOrFail();
            if (! app(AiInteractionPolicy::class)->allowsLegacyAutomation() && $knowledge->revision !== $request->integer('revision')) {
                throw ValidationException::withMessages(['revision' => 'Materi sudah berubah. Muat ulang sebelum mengubah status.']);
            }
            if ($knowledge->status !== 'active' && $knowledge->publication_status === 'published') {
                abort_unless($request->user()->hasRole(['owner', 'manager']), 403);
            }
            $before = $knowledge->toArray();
            $knowledge->update(['status' => $knowledge->status === 'active' ? 'inactive' : 'active', 'revision' => $knowledge->revision + 1]);
            DB::table('ai_interaction_policies')->where('id', 1)->increment('revision');
            app(AuditLogService::class)->log($request->user(), 'ai.knowledge.toggle', $knowledge, $request, ['status' => $before['status']], ['status' => $knowledge->status]);
        });

        return back()->with('status', 'Status knowledge base berhasil diubah.');
    }

    public function draftFromDemo(Request $request, KnowledgeBase $knowledge): RedirectResponse
    {
        $draft = DB::transaction(function () use ($request, $knowledge) {
            $source = KnowledgeBase::whereKey($knowledge->id)->lockForUpdate()->firstOrFail();
            abort_unless($source->is_demo, 422);
            $draft = KnowledgeBase::firstOrCreate(['slug' => 'review-demo-'.$source->id], ['title' => preg_replace('/^\[DEMO\]\s*/u', '', $source->title),
                'type' => $source->type, 'content' => $source->content, 'status' => 'active', 'publication_status' => 'draft', 'audience' => 'customer_public', 'is_demo' => false, 'revision' => 1,
                'meta' => ['origin' => 'demo_review_draft', 'demo_source_id' => $source->id, 'keywords' => data_get($source->meta, 'keywords', [])]]);
            abort_unless((int) data_get($draft->meta, 'demo_source_id') === $source->id, 422);
            if ($draft->wasRecentlyCreated) {
                $draft->domains()->createMany($source->domains->map(fn ($d) => ['domain' => $d->domain])->all());
                $this->knowledgeBaseService->syncChunks($draft);
                app(AuditLogService::class)->log($request->user(), 'ai.knowledge.demo_to_draft', $draft, $request, [], ['demo_source_id' => $source->id], 'Salinan contoh disiapkan sebagai draft; perlu peninjauan sebelum terbit.');
            }

            return $draft;
        });

        return redirect()->route('admin.ai.knowledge.edit', $draft)->with('status', 'Draft disiapkan. Periksa ketentuan bisnis sebenarnya sebelum diterbitkan oleh owner/manager.');
    }

    private function validatedData(Request $request): array
    {
        $legacy = app(AiInteractionPolicy::class)->allowsLegacyAutomation();

        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:60'],
            'content' => ['nullable', 'required_without:file', 'string', 'max:60000'],
            'file' => ['nullable', 'file', 'max:5120', 'mimes:txt,md,csv,json'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'domains' => [$legacy ? 'nullable' : 'required', 'array', 'min:1', 'max:5'],
            'domains.*' => ['required', Rule::in(array_keys(AiDomain::LABELS)), 'distinct'],
            'publication_status' => [$legacy ? 'nullable' : 'required', Rule::in(['draft', 'published', 'needs_review', 'archived'])],
            'audience' => [$legacy ? 'nullable' : 'required', Rule::in(['customer_public', 'internal_only'])],
            'keywords' => ['nullable', 'string', 'max:800'],
        ]);
    }

    private function saveKnowledge(Request $request, KnowledgeBase $knowledge, array $data): void
    {
        $domains = $data['domains'] ?? [];
        $publication = $data['publication_status'] ?? 'needs_review';
        if ($publication === 'published') {
            abort_unless($request->user()->hasRole(['owner', 'manager']), 403);
            app(AiPublicContent::class)->validate($data['title']."\n".($data['content'] ?? ''));
        }
        if (Str::length((string) ($data['content'] ?? '')) > 60000 || ! mb_check_encoding((string) ($data['content'] ?? ''), 'UTF-8')) {
            throw ValidationException::withMessages(['content' => 'Konten harus UTF-8 dan maksimal 60.000 karakter.']);
        }
        $data['meta'] = array_replace($knowledge->meta ?? [], ['keywords' => array_values(array_filter(array_map('trim', explode(',', $data['keywords'] ?? ''))))]);
        unset($data['file'], $data['domains'], $data['keywords']);
        $data['publication_status'] = $publication;
        $data['audience'] ??= 'customer_public';
        $data['revision'] = ($knowledge->revision ?? 0) + 1;
        $data['reviewed_by'] = $publication === 'published' ? $request->user()->id : null;
        $data['reviewed_at'] = $publication === 'published' ? now() : null;
        if (! empty($data['valid_until']) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $data['valid_until'])) {
            $data['valid_until'] = Carbon::parse($data['valid_until'])->endOfDay();
        }
        DB::transaction(function () use ($knowledge, $data, $domains, $request) {
            if ($knowledge->exists && ! app(AiInteractionPolicy::class)->allowsLegacyAutomation()) {
                $knowledge = KnowledgeBase::whereKey($knowledge->id)->lockForUpdate()->firstOrFail();
                if ($knowledge->revision !== $request->integer('revision')) {
                    throw ValidationException::withMessages(['revision' => 'Materi sudah berubah. Muat ulang sebelum menyimpan atau menerbitkan.']);
                }
                $data['revision'] = $knowledge->revision + 1;
            }
            $knowledge->fill($data)->save();
            $knowledge->domains()->delete();
            $knowledge->domains()->createMany(array_map(fn ($domain) => ['domain' => $domain], $domains));
            $this->knowledgeBaseService->syncChunks($knowledge);
            DB::table('ai_interaction_policies')->where('id', 1)->increment('revision');
            app(AuditLogService::class)->log($request->user(), 'ai.knowledge.save', $knowledge, $request, [], [
                'domains' => $domains, 'publication_status' => $data['publication_status'], 'revision' => $data['revision'],
            ], 'Knowledge diperbarui; isi tidak disalin ke log audit.');
        });
    }

    private function applyUploadedFile(Request $request, array $data, ?KnowledgeBase $knowledge = null): array
    {
        if (! $request->hasFile('file')) {
            return $data;
        }

        $file = $request->file('file');
        $path = $file->store('knowledge-base', 'local');
        $content = file_get_contents($file->getRealPath());

        $data['source_path'] = $path;
        $data['mime_type'] = $file->getClientMimeType();
        $data['content'] = trim((string) $content) ?: ($data['content'] ?? $knowledge?->content);

        return $data;
    }
}
