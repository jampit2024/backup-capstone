<?php

namespace App\Http\Controllers\Result;

use App\Http\Controllers\Controller;

use App\Services\ExaminationAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExaminationAnalyticsController extends Controller
{
    public function __construct(private ExaminationAnalyticsService $analytics) {}

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $this->analytics->overview(),
        ]);
    }
}
