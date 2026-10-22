<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOvertimeRuleRequest;
use App\Http\Requests\UpdateOvertimeRuleRequest;
use App\Models\OvertimeRule;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OvertimeRuleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => OvertimeRule::query()->latest('effective_from')->get()]);
    }

    public function store(StoreOvertimeRuleRequest $request): JsonResponse
    {
        $rule = OvertimeRule::create($request->validated());
        ActivityLogger::success('overtime_rule.created', 'Aturan lembur berhasil ditambahkan.', $rule);

        return response()->json(['message' => 'Aturan lembur berhasil ditambahkan.', 'data' => $rule], 201);
    }

    public function show(OvertimeRule $overtimeRule): JsonResponse
    {
        return response()->json(['data' => $overtimeRule]);
    }

    public function update(UpdateOvertimeRuleRequest $request, OvertimeRule $overtimeRule): JsonResponse
    {
        $overtimeRule->update($request->validated());
        ActivityLogger::success('overtime_rule.updated', 'Aturan lembur berhasil diperbarui.', $overtimeRule);

        return response()->json(['message' => 'Aturan lembur berhasil diperbarui.', 'data' => $overtimeRule->fresh()]);
    }

    public function updateStatus(Request $request, OvertimeRule $overtimeRule): JsonResponse
    {
        $validated = $request->validate(['active' => ['required', 'boolean']]);
        $overtimeRule->update($validated);
        ActivityLogger::success('overtime_rule.status_updated', 'Status aturan lembur berhasil diperbarui.', $overtimeRule, $validated);

        return response()->json(['message' => 'Status aturan lembur berhasil diperbarui.', 'data' => $overtimeRule->fresh()]);
    }

    public function destroy(OvertimeRule $overtimeRule): JsonResponse
    {
        $overtimeRule->delete();
        ActivityLogger::success('overtime_rule.deleted', 'Aturan lembur berhasil dihapus.', $overtimeRule);

        return response()->json(['message' => 'Aturan lembur berhasil dihapus.']);
    }
}
