<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Category;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeAttendanceTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.internal_api_key' => 'test-internal-key']);
        $this->seed();
    }

    private function apiHeaders(): array
    {
        return ['X-Internal-Api-Key' => 'test-internal-key', 'Accept' => 'application/json'];
    }

    public function test_mpp_and_stb_receive_default_editable_periods(): void
    {
        $category = Category::query()->firstOrFail();
        $mpp = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/employees', [
                'nid_nip' => 'MPP-001',
                'name' => 'Pegawai MPP',
                'position' => 'Dosen',
                'machine_id' => 'MPP-01',
                'employment_start_date' => '2000-01-01',
                'attendance_type' => 'mpp',
                'attendance_type_start_date' => '2027-01-15',
                'active' => true,
                'category_ids' => [$category->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.attendance_type_end_date', '2027-07-15')
            ->json('data');

        $this->withHeaders($this->apiHeaders())
            ->putJson("/api/v1/employees/{$mpp['id']}", [
                'nid_nip' => 'MPP-001',
                'name' => 'Pegawai MPP',
                'position' => 'Dosen',
                'machine_id' => 'MPP-01',
                'employment_start_date' => '2000-01-01',
                'attendance_type' => 'stb',
                'attendance_type_start_date' => '2027-02-01',
                'attendance_type_end_date' => '2032-02-01',
                'active' => true,
                'category_ids' => [$category->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.attendance_type', 'stb')
            ->assertJsonPath('data.attendance_type_end_date', '2032-02-01');
    }

    public function test_tk_is_displayed_as_employee_type_only_inside_the_period(): void
    {
        $employee = Employee::query()->firstOrFail();
        $employee->update([
            'attendance_type' => 'mpp',
            'attendance_type_start_date' => '2027-01-01',
            'attendance_type_end_date' => '2027-01-31',
        ]);
        AttendanceRecord::query()->create([
            'employee_id' => $employee->id,
            'attendance_date' => '2027-01-05',
            'status' => 'absent',
            'source' => 'system',
            'incomplete' => false,
        ]);

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/v1/attendances?month=1&year=2027')
            ->assertOk();
        $record = collect($response->json('data'))->firstWhere('employee_id', $employee->id);

        $this->assertSame('mpp', $record['effective_status']);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $record['id'],
            'status' => 'absent',
        ]);
    }

    public function test_leader_tk_is_displayed_as_present_without_changing_fingerprint_times(): void
    {
        $leader = Employee::query()
            ->whereHas('categories', fn ($query) => $query->where('group', 'Pimpinan'))
            ->firstOrFail();
        AttendanceRecord::query()->create([
            'employee_id' => $leader->id,
            'attendance_date' => '2027-01-05',
            'status' => 'absent',
            'source' => 'system',
            'incomplete' => false,
        ]);
        AttendanceRecord::query()->create([
            'employee_id' => $leader->id,
            'attendance_date' => '2027-01-06',
            'status' => 'present',
            'check_in' => '07:45:00',
            'check_out' => '16:30:00',
            'source' => 'fingerprint',
            'incomplete' => false,
        ]);

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/v1/attendances?month=1&year=2027')
            ->assertOk();
        $leaderRecords = collect($response->json('data'))->where('employee_id', $leader->id)->keyBy('attendance_date');

        $this->assertSame('present', $leaderRecords->get('2027-01-05')['effective_status']);
        $this->assertSame('07:45:00', $leaderRecords->get('2027-01-06')['check_in']);
        $this->assertSame('16:30:00', $leaderRecords->get('2027-01-06')['check_out']);
        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $leader->id,
            'attendance_date' => '2027-01-05',
            'status' => 'absent',
        ]);
    }

    public function test_historical_attendance_of_deleted_employee_does_not_break_monthly_recap(): void
    {
        $employee = Employee::query()->firstOrFail();
        AttendanceRecord::query()->create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-08-03',
            'status' => 'absent',
            'source' => 'system',
            'incomplete' => false,
        ]);
        $employee->delete();

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/v1/attendances?month=8&year=2026')
            ->assertOk()
            ->assertJsonMissing(['employee_id' => $employee->id]);
    }
}
