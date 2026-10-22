<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExportAttendanceReportRequest;
use App\Services\ActivityLogger;
use App\Services\AttendanceReportExporter;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class AttendanceReportController extends Controller
{
    public function __invoke(
        ExportAttendanceReportRequest $request,
        AttendanceReportExporter $exporter
    ): BinaryFileResponse|JsonResponse {
        $validated = $request->validated();

        try {
            $result = $exporter->create(
                (int) $validated['month'],
                (int) $validated['year'],
                $validated['category'] ?? null,
                $validated['search'] ?? null,
            );

            ActivityLogger::success(
                'attendance_report.exported',
                'Rekap kehadiran Excel berhasil diunduh.',
                metadata: [
                    'month' => (int) $validated['month'],
                    'year' => (int) $validated['year'],
                    'category' => $validated['category'] ?? null,
                    'search' => $validated['search'] ?? null,
                    'employee_count' => $result['employee_count'],
                    'sheet_count' => $result['sheet_count'],
                ]
            );

            return response()
                ->download($result['path'], $result['filename'], [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])
                ->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            ActivityLogger::error(
                'attendance_report.export_failed',
                'Rekap kehadiran Excel gagal dibuat.',
                metadata: ['error' => $exception->getMessage(), 'filters' => $validated]
            );

            report($exception);

            return response()->json(['message' => 'Rekap kehadiran Excel gagal dibuat.'], 500);
        }
    }
}
