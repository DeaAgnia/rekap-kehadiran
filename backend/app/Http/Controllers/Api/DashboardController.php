<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AttendanceRecord;
use App\Models\Category;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\ImportBatch;
use App\Services\AttendanceStatusResolver;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    private const MONTH_ABBREVIATIONS = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    public function __construct(private readonly AttendanceStatusResolver $statusResolver) {}

    public function __invoke(Request $request): JsonResponse
    {
        $today = Carbon::now('Asia/Jakarta')->startOfDay();
        $validated = $request->validate([
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'category' => [
                'nullable',
                'string',
                Rule::exists('categories', 'name')->where(fn ($query) => $query->where('active', true)),
            ],
        ]);
        $month = (int) ($validated['month'] ?? $today->month);
        $year = (int) ($validated['year'] ?? $today->year);
        $category = $validated['category'] ?? null;
        $periodStart = Carbon::create($year, $month, 1, 0, 0, 0, 'Asia/Jakarta')->startOfDay();
        $periodEnd = $periodStart->copy()->endOfMonth();

        $employees = Employee::query()
            ->with(['categories' => fn ($query) => $query->where('active', true)])
            ->where('active', true)
            ->when($category, fn ($query, $name) => $query->whereHas(
                'categories',
                fn ($categoryQuery) => $categoryQuery->where('name', $name)->where('active', true)
            ))
            ->get(['id', 'employment_start_date', 'attendance_type', 'attendance_type_start_date', 'attendance_type_end_date']);
        $employeeIds = $employees->pluck('id');
        $employeesById = $employees->keyBy('id');
        $activeEmployeeCount = $employees->count();
        $categoryCount = $employees->flatMap->categories->unique('id')->count();

        $holidayDates = Holiday::query()
            ->where('active', true)
            ->whereBetween('date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->flip();
        $records = $employeeIds->isEmpty()
            ? collect()
            : AttendanceRecord::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereBetween('attendance_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
                ->get();

        $latestCompletedDay = $today->copy()->subDay();
        $effectiveEnd = $periodEnd->lessThan($latestCompletedDay) ? $periodEnd : $latestCompletedDay;
        $expectedWorkdays = 0;
        if ($effectiveEnd->greaterThanOrEqualTo($periodStart)) {
            foreach (CarbonPeriod::create($periodStart, $effectiveEnd) as $date) {
                if ($date->isWeekend() || $holidayDates->has($date->toDateString())) {
                    continue;
                }
                foreach ($employees as $employee) {
                    if ($employee->employment_start_date->lessThanOrEqualTo($date)
                        && ! $this->statusResolver->employeeTypeForDate($employee, $date)) {
                        $expectedWorkdays++;
                    }
                }
            }
        }

        $completedRecords = $records->filter(fn (AttendanceRecord $record) => $record->attendance_date->lessThanOrEqualTo($effectiveEnd)
            && ! $record->attendance_date->isWeekend()
            && ! $holidayDates->has($record->attendance_date->toDateString()));
        $presentCount = $completedRecords->filter(fn (AttendanceRecord $record) => in_array(
            $this->statusResolver->resolve(
                $record->status,
                $employeesById->get($record->employee_id),
                $record->attendance_date
            ),
            ['present', 'duty'],
            true
        ))->count();
        $incompleteCount = $completedRecords->where('incomplete', true)->count();
        $mixedCount = $completedRecords->where('source', 'mixed')->count();
        $absentCount = $completedRecords->filter(fn (AttendanceRecord $record) => $this->statusResolver->resolve(
            $record->status,
            $employeesById->get($record->employee_id),
            $record->attendance_date
        ) === 'absent')->count();
        $needsReview = $completedRecords->filter(fn (AttendanceRecord $record) => $record->incomplete
            || $this->statusResolver->resolve(
                $record->status,
                $employeesById->get($record->employee_id),
                $record->attendance_date
            ) === 'absent'
            || $record->source === 'mixed')->count();

        $todayPresent = $employeeIds->isEmpty()
            ? 0
            : AttendanceRecord::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereDate('attendance_date', $today->toDateString())
                ->get()
                ->filter(fn (AttendanceRecord $record) => in_array(
                    $this->statusResolver->resolve(
                        $record->status,
                        $employeesById->get($record->employee_id),
                        $record->attendance_date
                    ),
                    ['present', 'duty'],
                    true
                ))
                ->count();

        $trendEnd = $periodEnd->lessThan($today) ? $periodEnd->copy()->startOfDay() : $today;
        $trendDates = collect();
        for ($date = $trendEnd->copy(); $date->greaterThanOrEqualTo($periodStart) && $trendDates->count() < 7; $date->subDay()) {
            if (! $date->isWeekend() && ! $holidayDates->has($date->toDateString())) {
                $trendDates->prepend($date->copy());
            }
        }
        $trend = $trendDates->map(function (Carbon $date) use ($records, $employeesById): array {
            $value = $records->filter(fn (AttendanceRecord $record) => $record->attendance_date->isSameDay($date)
                && in_array(
                    $this->statusResolver->resolve(
                        $record->status,
                        $employeesById->get($record->employee_id),
                        $record->attendance_date
                    ),
                    ['present', 'duty'],
                    true
                ))->count();

            return [
                'date' => $date->toDateString(),
                'label' => $date->day.' '.self::MONTH_ABBREVIATIONS[$date->month],
                'value' => $value,
            ];
        })->values();

        return response()->json([
            'data' => [
                'active_employees' => $activeEmployeeCount,
                'category_count' => $categoryCount,
                'present_today' => $todayPresent,
                'present_today_percentage' => $activeEmployeeCount > 0
                    ? round(($todayPresent / $activeEmployeeCount) * 100, 1)
                    : 0,
                'needs_review' => $needsReview,
                'attendance_rate' => $expectedWorkdays > 0
                    ? round(($presentCount / $expectedWorkdays) * 100, 1)
                    : 0,
                'expected_workdays' => $expectedWorkdays,
                'present_workdays' => $presentCount,
                'issues' => [
                    'incomplete' => $incompleteCount,
                    'mixed' => $mixedCount,
                    'absent' => $absentCount,
                ],
                'trend' => $trend,
                'latest_import' => ImportBatch::query()->latest()->first(),
                'recent_activity' => ActivityLog::query()->latest('occurred_at')->limit(50)->get(),
                'categories' => Category::query()->where('active', true)->orderBy('id')->pluck('name'),
                'updated_at' => Carbon::now('Asia/Jakarta')->toIso8601String(),
                'filters' => ['month' => $month, 'year' => $year, 'category' => $category],
            ],
        ]);
    }
}
