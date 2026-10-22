<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttendanceMachineRequest;
use App\Http\Requests\UpdateAttendanceMachineRequest;
use App\Models\AttendanceMachine;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AttendanceMachineController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => AttendanceMachine::query()->orderByDesc('active')->orderBy('name')->get(),
        ]);
    }

    public function store(StoreAttendanceMachineRequest $request): JsonResponse
    {
        try {
            $machine = AttendanceMachine::create($request->validated());
            ActivityLogger::success('attendance_machine.created', 'Mesin absensi berhasil ditambahkan.', $machine);

            return response()->json(['message' => 'Mesin absensi berhasil ditambahkan.', 'data' => $machine], 201);
        } catch (Throwable $exception) {
            ActivityLogger::error('attendance_machine.create_failed', 'Mesin absensi gagal ditambahkan.', metadata: ['error' => $exception->getMessage()]);
            throw $exception;
        }
    }

    public function show(AttendanceMachine $attendanceMachine): JsonResponse
    {
        return response()->json(['data' => $attendanceMachine]);
    }

    public function update(UpdateAttendanceMachineRequest $request, AttendanceMachine $attendanceMachine): JsonResponse
    {
        try {
            $attendanceMachine->update($request->validated());
            ActivityLogger::success('attendance_machine.updated', 'Mesin absensi berhasil diperbarui.', $attendanceMachine);

            return response()->json(['message' => 'Mesin absensi berhasil diperbarui.', 'data' => $attendanceMachine->fresh()]);
        } catch (Throwable $exception) {
            ActivityLogger::error('attendance_machine.update_failed', 'Mesin absensi gagal diperbarui.', $attendanceMachine, ['error' => $exception->getMessage()]);
            throw $exception;
        }
    }

    public function updateStatus(Request $request, AttendanceMachine $attendanceMachine): JsonResponse
    {
        $validated = $request->validate(['active' => ['required', 'boolean']]);
        $attendanceMachine->update($validated);
        ActivityLogger::success('attendance_machine.status_updated', 'Status mesin absensi berhasil diperbarui.', $attendanceMachine, $validated);

        return response()->json(['message' => 'Status mesin absensi berhasil diperbarui.', 'data' => $attendanceMachine->fresh()]);
    }

    public function destroy(AttendanceMachine $attendanceMachine): JsonResponse
    {
        try {
            $attendanceMachine->delete();
            ActivityLogger::success('attendance_machine.deleted', 'Mesin absensi berhasil dihapus.', $attendanceMachine);

            return response()->json(['message' => 'Mesin absensi berhasil dihapus.']);
        } catch (Throwable $exception) {
            ActivityLogger::error('attendance_machine.delete_failed', 'Mesin absensi gagal dihapus.', $attendanceMachine, ['error' => $exception->getMessage()]);
            throw $exception;
        }
    }
}
