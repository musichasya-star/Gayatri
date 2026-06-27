<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiExtractedData;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiExtractedDataController extends Controller
{
    public function index(Request $request): View
    {
        $items = AiExtractedData::query()
            ->with(['customer', 'conversation'])
            ->when($request->filled('intent'), fn ($query) => $query->where('intent', $request->string('intent')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.ai.data-automation.extracted.index', compact('items'));
    }

    public function show(AiExtractedData $extractedData): View
    {
        $extractedData->load(['customer', 'conversation', 'message', 'approvals']);

        return view('admin.ai.data-automation.extracted.show', compact('extractedData'));
    }
}
