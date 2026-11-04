<?php

namespace App\Http\Controllers;

use App\Services\CRM\HomeService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class HomeServiceQuoteController extends Controller
{
    public function __invoke(Request $request, HomeService $homeService)
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
        ]);
        try {
            return response()->json($homeService->quote((float) $data['latitude'], (float) $data['longitude'], (int) $data['service_id']));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
