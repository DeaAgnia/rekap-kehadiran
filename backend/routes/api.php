<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AttendanceMachineController;
use App\Http\Controllers\Api\AttendanceReportController;
use App\Http\Controllers\Api\AttendanceSettingController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\FingerprintImportController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\OvertimeRuleController;
use App\Http\Controllers\Api\WorkScheduleController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('internal.api')->group(function (): void {
    Route::get('dashboard', DashboardController::class);
    Route::get('attendance-settings', [AttendanceSettingController::class, 'show']);
    Route::put('attendance-settings', [AttendanceSettingController::class, 'update']);

    Route::patch('employees/{employee}/status', [EmployeeController::class, 'updateStatus']);
    Route::apiResource('employees', EmployeeController::class);

    Route::patch('categories/{category}/status', [CategoryController::class, 'updateStatus']);
    Route::apiResource('categories', CategoryController::class);

    Route::patch('work-schedules/{work_schedule}/status', [WorkScheduleController::class, 'updateStatus']);
    Route::apiResource('work-schedules', WorkScheduleController::class)->parameters(['work-schedules' => 'work_schedule']);

    Route::patch('overtime-rules/{overtime_rule}/status', [OvertimeRuleController::class, 'updateStatus']);
    Route::apiResource('overtime-rules', OvertimeRuleController::class)->parameters(['overtime-rules' => 'overtime_rule']);

    Route::patch('holidays/{holiday}/status', [HolidayController::class, 'updateStatus']);
    Route::apiResource('holidays', HolidayController::class);

    Route::patch('attendance-machines/{attendance_machine}/status', [AttendanceMachineController::class, 'updateStatus']);
    Route::apiResource('attendance-machines', AttendanceMachineController::class)->parameters(['attendance-machines' => 'attendance_machine']);

    Route::apiResource('attendances', AttendanceController::class);
    Route::get('attendances/{attendance}/attachment', [AttendanceController::class, 'attachment']);
    Route::get('attendance-reports/export', AttendanceReportController::class);
    Route::post('fingerprint-imports', [FingerprintImportController::class, 'store']);
    Route::get('fingerprint-imports', [FingerprintImportController::class, 'index']);
    Route::get('activity-logs', ActivityLogController::class);
});
