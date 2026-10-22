<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.internal_api_key' => 'test-internal-key']);
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'Asia/Jakarta'));
        $this->seed();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function apiHeaders(): array
    {
        return ['X-Internal-Api-Key' => 'test-internal-key', 'Accept' => 'application/json'];
    }

    public function test_dashboard_is_calculated_from_employee_attendance_and_activity_data(): void
    {
        $employees = Employee::query()->orderBy('id')->take(5)->get();
        $records = [
            ['employee_id' => $employees[0]->id, 'status' => 'present', 'source' => 'fingerprint', 'incomplete' => false],
            ['employee_id' => $employees[1]->id, 'status' => 'duty', 'source' => 'manual', 'incomplete' => false],
            ['employee_id' => $employees[2]->id, 'status' => 'present', 'source' => 'mixed', 'incomplete' => true],
            ['employee_id' => $employees[3]->id, 'status' => 'absent', 'source' => 'system', 'incomplete' => false],
        ];
        foreach ($records as $record) {
            AttendanceRecord::query()->create([
                ...$record,
                'attendance_date' => '2026-10-14',
            ]);
        }
        AttendanceRecord::query()->create([
            'employee_id' => $employees[1]->id,
            'attendance_date' => '2026-10-15',
            'status' => 'present',
            'source' => 'fingerprint',
            'incomplete' => false,
        ]);
        ActivityLog::query()->create([
            'action' => 'dashboard.test',
            'status' => 'success',
            'description' => 'Aktivitas aktual untuk dashboard.',
            'actor' => 'admin.sdm',
            'occurred_at' => now(),
        ]);

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/v1/dashboard?month=10&year=2026')
            ->assertOk()
            ->assertJsonPath('data.active_employees', 8)
            ->assertJsonPath('data.present_today', 1)
            ->assertJsonPath('data.present_workdays', 3)
            ->assertJsonPath('data.needs_review', 2)
            ->assertJsonPath('data.issues.incomplete', 1)
            ->assertJsonPath('data.issues.mixed', 1)
            ->assertJsonPath('data.issues.absent', 1)
            ->assertJsonPath('data.recent_activity.0.description', 'Aktivitas aktual untuk dashboard.');

        $this->assertCount(7, $response->json('data.trend'));
        $this->assertSame(3, collect($response->json('data.trend'))->firstWhere('date', '2026-10-14')['value']);
        $this->assertSame(10, count($response->json('data.categories')));
    }

    public function test_dashboard_category_filter_uses_matching_employee_records(): void
    {
        $employee = Employee::query()->where('machine_id', '11')->firstOrFail();
        AttendanceRecord::query()->create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-10-15',
            'status' => 'present',
            'source' => 'fingerprint',
            'incomplete' => false,
        ]);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/v1/dashboard?month=10&year=2026&category=Dosen%20Teknik%20Mesin')
            ->assertOk()
            ->assertJsonPath('data.active_employees', 1)
            ->assertJsonPath('data.present_today', 1)
            ->assertJsonPath('data.filters.category', 'Dosen Teknik Mesin');
    }

    public function test_dashboard_counts_leader_tk_as_present(): void
    {
        $leader = Employee::query()
            ->whereHas('categories', fn ($query) => $query->where('group', 'Pimpinan'))
            ->firstOrFail();
        AttendanceRecord::query()->create([
            'employee_id' => $leader->id,
            'attendance_date' => '2026-10-14',
            'status' => 'absent',
            'source' => 'system',
            'incomplete' => false,
        ]);

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/v1/dashboard?month=10&year=2026')
            ->assertOk();

        $this->assertSame(1, $response->json('data.present_workdays'));
        $this->assertSame(0, $response->json('data.issues.absent'));
        $this->assertSame(1, collect($response->json('data.trend'))->firstWhere('date', '2026-10-14')['value']);
    }
}
