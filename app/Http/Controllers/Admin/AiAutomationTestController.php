<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiExtractedData;
use App\Services\AI\AiAutomationRuleService;
use App\Services\AI\AiDataExtractionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiAutomationTestController extends Controller
{
    public function index(): View
    {
        return view('admin.ai.data-automation.test', ['result' => null]);
    }

    public function run(Request $request, AiDataExtractionService $extractionService, AiAutomationRuleService $ruleService): View
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $extracted = $extractionService->extract($data['message']);
        $fake = new AiExtractedData([
            'intent' => $extracted['intent'],
            'confidence_score' => $extracted['confidence_score'],
            'extracted_customer_data' => $extracted['customer'],
            'extracted_booking_data' => $extracted['booking'],
            'extracted_followup_data' => $extracted['followup'],
            'missing_fields' => $extracted['missing_fields'],
            'raw_ai_response' => $extracted,
        ]);
        $rule = $ruleService->matchingRule($fake);

        return view('admin.ai.data-automation.test', [
            'message' => $data['message'],
            'result' => $extracted,
            'rule' => $rule,
        ]);
    }
}
