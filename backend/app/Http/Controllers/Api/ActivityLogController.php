<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $logs = ActivityLog::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest('occurred_at')
            ->limit(min(max($request->integer('limit', 100), 1), 500))
            ->get();

        return response()->json(['data' => $logs]);
    }
}
