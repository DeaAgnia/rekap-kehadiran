<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkScheduleRequest;
use App\Http\Requests\UpdateWorkScheduleRequest;
use App\Models\WorkSchedule;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class WorkScheduleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => WorkSchedule::query()->latest('effective_from')->get()]);
    }

    public function store(StoreWorkScheduleRequest $request): JsonResponse
    {
        try {
            $schedule = WorkSchedule::create($request->validated());
            ActivityLogger::success('work_schedule.created', 'Jam kerja berhasil ditambahkan.', $schedule);

            return response()->json(['message' => 'Jam kerja berhasil ditambahkan.', 'data' => $schedule], 201);
        } catch (Throwable $exception) {
            ActivityLogger::error('work_schedule.create_failed', 'Jam kerja gagal ditambahkan.', metadata: ['error' => $exception->getMessage()]);
            throw $exception;
        }
    }

    public function show(WorkSchedule $workSchedule): JsonResponse
    {
        return response()->json(['data' => $workSchedule]);
    }

    public function update(UpdateWorkScheduleRequest $request, WorkSchedule $workSchedule): JsonResponse
    {
        $workSchedule->update($request->validated());
        ActivityLogger::success('work_schedule.updated', 'Jam kerja berhasil diperbarui.', $workSchedule);

        return response()->json(['message' => 'Jam kerja berhasil diperbarui.', 'data' => $workSchedule->fresh()]);
    }

    public function updateStatus(Request $request, WorkSchedule $workSchedule): JsonResponse
    {
        $validated = $request->validate(['active' => ['required', 'boolean']]);
        $workSchedule->update($validated);
        ActivityLogger::success('work_schedule.status_updated', 'Status jam kerja berhasil diperbarui.', $workSchedule, $validated);

        return response()->json(['message' => 'Status jam kerja berhasil diperbarui.', 'data' => $workSchedule->fresh()]);
    }

    public function destroy(WorkSchedule $workSchedule): JsonResponse
    {
        $workSchedule->delete();
        ActivityLogger::success('work_schedule.deleted', 'Jam kerja berhasil dihapus.', $workSchedule);

        return response()->json(['message' => 'Jam kerja berhasil dihapus.']);
    }
}
