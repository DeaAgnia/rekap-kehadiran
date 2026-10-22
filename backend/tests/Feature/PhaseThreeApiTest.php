<?php

namespace Tests\Feature;

use App\Models\AttendanceMachine;
use App\Models\AttendanceRecord;
use App\Models\Category;
use App\Models\Employee;
use App\Models\FingerprintScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PhaseThreeApiTest extends TestCase
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

    private function registerAttendanceMachine(bool $active = true): AttendanceMachine
    {
        return AttendanceMachine::query()->create([
            'name' => 'Mesin Fingerprint Utama',
            'serial_number' => 'JHG3240400192',
            'location' => 'Lobi FTM Bandung',
            'active' => $active,
        ]);
    }

    public function test_internal_api_key_is_required(): void
    {
        $this->getJson('/api/v1/employees')->assertUnauthorized();
    }

    public function test_employee_form_is_validated_and_crud_works(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/employees', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nid_nip', 'name', 'position', 'machine_id', 'employment_start_date', 'category_ids']);

        $category = Category::query()->firstOrFail();
        $created = $this->withHeaders($this->apiHeaders())->postJson('/api/v1/employees', [
            'nid_nip' => '999001',
            'name' => 'Pegawai Uji',
            'position' => 'Staf Pengujian',
            'machine_id' => '999',
            'employment_start_date' => '2026-01-01',
            'active' => true,
            'category_ids' => [$category->id],
        ])->assertCreated()->json('data');

        $this->assertDatabaseHas('employees', ['nid_nip' => '999001', 'name' => 'Pegawai Uji']);
        $this->withHeaders($this->apiHeaders())
            ->patchJson("/api/v1/employees/{$created['id']}/status", ['active' => false])
            ->assertOk();
        $this->assertDatabaseHas('employees', ['id' => $created['id'], 'active' => false]);

        $this->withHeaders($this->apiHeaders())
            ->deleteJson("/api/v1/employees/{$created['id']}")
            ->assertOk();
        $this->assertSoftDeleted('employees', ['id' => $created['id']]);
    }

    public function test_machine_id_can_be_reused_after_employee_is_soft_deleted(): void
    {
        $category = Category::query()->firstOrFail();
        $firstEmployee = $this->withHeaders($this->apiHeaders())->postJson('/api/v1/employees', [
            'nid_nip' => 'REUSE-001',
            'name' => 'Pegawai Lama',
            'position' => 'Staf',
            'machine_id' => 'REUSE-10',
            'employment_start_date' => '2026-01-01',
            'active' => true,
            'category_ids' => [$category->id],
        ])->assertCreated()->json('data');

        $this->withHeaders($this->apiHeaders())
            ->deleteJson("/api/v1/employees/{$firstEmployee['id']}")
            ->assertOk();

        $secondEmployee = $this->withHeaders($this->apiHeaders())->postJson('/api/v1/employees', [
            'nid_nip' => 'REUSE-002',
            'name' => 'Pegawai Baru',
            'position' => 'Staf',
            'machine_id' => 'REUSE-10',
            'employment_start_date' => '2026-02-01',
            'active' => true,
            'category_ids' => [$category->id],
        ])->assertCreated()->json('data');

        $this->assertNotSame($firstEmployee['id'], $secondEmployee['id']);
        $this->assertDatabaseHas('employees', [
            'id' => $secondEmployee['id'], 'machine_id' => 'REUSE-10', 'deleted_at' => null,
        ]);

        $this->withHeaders($this->apiHeaders())->postJson('/api/v1/employees', [
            'nid_nip' => 'REUSE-003',
            'name' => 'Pegawai Duplikat',
            'position' => 'Staf',
            'machine_id' => 'REUSE-10',
            'employment_start_date' => '2026-03-01',
            'active' => true,
            'category_ids' => [$category->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['machine_id']);
    }

    public function test_placeholder_machine_id_can_be_shared_by_multiple_active_leaders(): void
    {
        $category = Category::query()->where('name', 'Pimpinan')->firstOrFail();

        foreach ([
            ['nid_nip' => 'LEADER-001', 'name' => 'Pimpinan Satu', 'machine_id' => '-'],
            ['nid_nip' => 'LEADER-002', 'name' => 'Pimpinan Dua', 'machine_id' => ' - '],
        ] as $employee) {
            $this->withHeaders($this->apiHeaders())->postJson('/api/v1/employees', [
                ...$employee,
                'position' => 'Pimpinan',
                'employment_start_date' => '2026-01-01',
                'active' => true,
                'category_ids' => [$category->id],
            ])->assertCreated();
        }

        $this->assertDatabaseCount('employees', 10);
        $this->assertSame(2, Employee::query()->where('machine_id', '-')->count());
    }

    public function test_master_and_attendance_forms_reject_invalid_values(): void
    {
        $this->withHeaders($this->apiHeaders())->postJson('/api/v1/work-schedules', [
            'name' => 'Tidak Valid',
            'work_start' => '17:00',
            'work_end' => '08:00',
        ])->assertUnprocessable();

        $employee = Employee::query()->firstOrFail();
        $this->withHeaders($this->apiHeaders())->postJson('/api/v1/attendances', [
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-01',
            'status' => 'present',
            'check_in' => '17:00',
            'check_out' => '08:00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['check_out']);
    }

    public function test_all_master_resources_support_crud_and_status_endpoint(): void
    {
        $category = $this->withHeaders($this->apiHeaders())->postJson('/api/v1/categories', [
            'name' => 'Kategori Uji', 'group' => 'Tendik', 'active' => true,
        ])->assertCreated()->json('data');
        $this->withHeaders($this->apiHeaders())->putJson("/api/v1/categories/{$category['id']}", [
            'name' => 'Kategori Uji Diperbarui', 'group' => 'Tendik', 'active' => true,
        ])->assertOk();
        $this->withHeaders($this->apiHeaders())->patchJson("/api/v1/categories/{$category['id']}/status", ['active' => false])->assertOk();
        $this->withHeaders($this->apiHeaders())->deleteJson("/api/v1/categories/{$category['id']}")->assertOk();

        $schedule = $this->withHeaders($this->apiHeaders())->postJson('/api/v1/work-schedules', [
            'name' => 'Jadwal Uji', 'work_start' => '08:00', 'work_end' => '17:00',
            'break_start' => '12:00', 'break_end' => '13:00',
            'late_tolerance_minutes' => 5, 'early_leave_tolerance_minutes' => 5,
            'effective_from' => '2027-01-01', 'effective_until' => null, 'active' => true,
        ])->assertCreated()->json('data');
        $this->withHeaders($this->apiHeaders())->putJson("/api/v1/work-schedules/{$schedule['id']}", [
            'name' => 'Jadwal Uji Baru', 'work_start' => '08:00', 'work_end' => '17:00',
            'break_start' => '12:00', 'break_end' => '13:00',
            'late_tolerance_minutes' => 10, 'early_leave_tolerance_minutes' => 5,
            'effective_from' => '2027-01-01', 'effective_until' => null, 'active' => true,
        ])->assertOk();
        $this->withHeaders($this->apiHeaders())->patchJson("/api/v1/work-schedules/{$schedule['id']}/status", ['active' => false])->assertOk();
        $this->withHeaders($this->apiHeaders())->deleteJson("/api/v1/work-schedules/{$schedule['id']}")->assertOk();

        $overtime = $this->withHeaders($this->apiHeaders())->postJson('/api/v1/overtime-rules', [
            'name' => 'Lembur Uji', 'starts_after_minutes' => 90, 'max_minutes' => 180,
            'effective_from' => '2027-01-01', 'effective_until' => null, 'active' => true,
        ])->assertCreated()->json('data');
        $this->withHeaders($this->apiHeaders())->putJson("/api/v1/overtime-rules/{$overtime['id']}", [
            'name' => 'Lembur Uji Baru', 'starts_after_minutes' => 120, 'max_minutes' => 240,
            'effective_from' => '2027-01-01', 'effective_until' => null, 'active' => true,
        ])->assertOk();
        $this->withHeaders($this->apiHeaders())->patchJson("/api/v1/overtime-rules/{$overtime['id']}/status", ['active' => false])->assertOk();
        $this->withHeaders($this->apiHeaders())->deleteJson("/api/v1/overtime-rules/{$overtime['id']}")->assertOk();

        $holiday = $this->withHeaders($this->apiHeaders())->postJson('/api/v1/holidays', [
            'date' => '2027-02-01', 'name' => 'Libur Uji', 'type' => 'internal', 'active' => true,
        ])->assertCreated()->json('data');
        $this->withHeaders($this->apiHeaders())->putJson("/api/v1/holidays/{$holiday['id']}", [
            'date' => '2027-02-02', 'name' => 'Libur Uji Baru', 'type' => 'internal', 'active' => true,
        ])->assertOk();
        $this->withHeaders($this->apiHeaders())->patchJson("/api/v1/holidays/{$holiday['id']}/status", ['active' => false])->assertOk();
        $this->withHeaders($this->apiHeaders())->deleteJson("/api/v1/holidays/{$holiday['id']}")->assertOk();
    }

    public function test_combined_attendance_setting_updates_schedule_and_overtime_rule(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/v1/attendance-settings')
            ->assertOk()
            ->assertJsonPath('data.work_start', '08:00')
            ->assertJsonPath('data.overtime_starts_after_minutes', 120);

        $this->withHeaders($this->apiHeaders())->putJson('/api/v1/attendance-settings', [
            'work_start' => '13:00',
            'work_end' => '17:00',
            'late_tolerance_minutes' => 10,
            'overtime_starts_after_minutes' => 90,
            'overtime_max_minutes' => 180,
        ])->assertUnprocessable()->assertJsonValidationErrors(['work_start']);

        $this->withHeaders($this->apiHeaders())->putJson('/api/v1/attendance-settings', [
            'work_start' => '07:30',
            'work_end' => '16:30',
            'late_tolerance_minutes' => 10,
            'overtime_starts_after_minutes' => 90,
            'overtime_max_minutes' => 180,
        ])->assertOk()
            ->assertJsonPath('data.work_start', '07:30')
            ->assertJsonPath('data.work_end', '16:30')
            ->assertJsonPath('data.late_tolerance_minutes', 10)
            ->assertJsonPath('data.overtime_starts_after_minutes', 90)
            ->assertJsonPath('data.overtime_max_minutes', 180);

        $this->assertDatabaseHas('work_schedules', [
            'work_start' => '07:30', 'work_end' => '16:30', 'late_tolerance_minutes' => 10,
        ]);
        $this->assertDatabaseHas('overtime_rules', [
            'starts_after_minutes' => 90, 'max_minutes' => 180,
        ]);
    }

    public function test_active_holiday_removes_automatic_absence_for_that_date(): void
    {
        $employee = Employee::query()->firstOrFail();
        AttendanceRecord::query()->create([
            'employee_id' => $employee->id,
            'attendance_date' => '2027-03-03',
            'status' => 'absent',
            'source' => 'system',
            'incomplete' => false,
        ]);

        $this->withHeaders($this->apiHeaders())->postJson('/api/v1/holidays', [
            'date' => '2027-03-03',
            'name' => 'Libur Internal Uji',
            'type' => 'internal',
            'active' => true,
        ])->assertCreated();

        $this->assertDatabaseMissing('attendance_records', [
            'employee_id' => $employee->id,
            'attendance_date' => '2027-03-03',
            'status' => 'absent',
            'source' => 'system',
        ]);
    }

    public function test_fingerprint_csv_is_imported_and_aggregated(): void
    {
        $this->registerAttendanceMachine();
        $csv = implode("\r\n", [
            'ID,NAMA,TANGGAL,STATUS,STAT NAMA,EVALUASI,SUHU,JAM KERJA,MESIN,VERIFY',
            '10,10,02/09/2026 07:44:24,0,Masuk,No Jadwal,0,,JHG3240400192,1',
            '10,10,02/09/2026 17:16:52,0,Masuk,No Jadwal,0,,JHG3240400192,1',
        ]);

        $this->withHeaders($this->apiHeaders())
            ->post('/api/v1/fingerprint-imports', [
                'file' => UploadedFile::fake()->createWithContent('fingerprint.csv', $csv),
            ])
            ->assertCreated()
            ->assertJsonPath('data.imported_count', 2)
            ->assertJsonPath('data.error_count', 0);

        $this->assertDatabaseCount('fingerprint_scans', 2);
        $this->assertDatabaseHas('attendance_records', [
            'attendance_date' => '2026-09-02',
            'status' => 'present',
            'check_in' => '07:44:24',
            'check_out' => '17:16:52',
            'incomplete' => false,
        ]);

        $employee = Employee::query()->where('machine_id', '10')->firstOrFail();
        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/v1/attendances?month=9&year=2026')
            ->assertOk();
        $record = collect($response->json('data'))->first(fn (array $item) => $item['employee_id'] === $employee->id
            && $item['attendance_date'] === '2026-09-02');
        $this->assertSame('Mesin Fingerprint Utama — Lobi FTM Bandung', $record['machine_location']);
    }

    public function test_us_fingerprint_date_format_is_detected_and_aggregated(): void
    {
        $this->registerAttendanceMachine();
        $csv = implode("\r\n", [
            'ID,NAMA,TANGGAL,STATUS,STAT NAMA,EVALUASI,SUHU,JAM KERJA,MESIN,VERIFY',
            '10,10,08/13/2026 07:44:24,0,Masuk,No Jadwal,0,,JHG3240400192,1',
            '10,10,08/13/2026 17:16:52,0,Masuk,No Jadwal,0,,JHG3240400192,1',
        ]);

        $this->withHeaders($this->apiHeaders())
            ->post('/api/v1/fingerprint-imports', [
                'file' => UploadedFile::fake()->createWithContent('fingerprint-us.csv', $csv),
            ])
            ->assertCreated()
            ->assertJsonPath('data.imported_count', 2)
            ->assertJsonPath('data.error_count', 0);

        $this->assertDatabaseHas('attendance_records', [
            'attendance_date' => '2026-08-13',
            'status' => 'present',
            'check_in' => '07:44:24',
            'check_out' => '17:16:52',
            'incomplete' => false,
        ]);
    }

    public function test_invalid_fingerprint_date_is_not_normalized_to_another_month(): void
    {
        $this->registerAttendanceMachine();
        $csv = implode("\r\n", [
            'ID,NAMA,TANGGAL,STATUS,STAT NAMA,EVALUASI,SUHU,JAM KERJA,MESIN,VERIFY',
            '10,10,31/02/2026 07:44:24,0,Masuk,No Jadwal,0,,JHG3240400192,1',
        ]);

        $this->withHeaders($this->apiHeaders())
            ->post('/api/v1/fingerprint-imports', [
                'file' => UploadedFile::fake()->createWithContent('fingerprint-invalid.csv', $csv),
            ])
            ->assertCreated()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.error_count', 1);

        $this->assertDatabaseCount('fingerprint_scans', 0);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_fingerprint_import_rejects_unregistered_or_inactive_machine(): void
    {
        $csv = implode("\r\n", [
            'ID,NAMA,TANGGAL,STATUS,STAT NAMA,EVALUASI,SUHU,JAM KERJA,MESIN,VERIFY',
            '10,10,02/09/2026 07:44:24,0,Masuk,No Jadwal,0,,JHG3240400192,1',
        ]);

        $this->withHeaders($this->apiHeaders())
            ->post('/api/v1/fingerprint-imports', [
                'file' => UploadedFile::fake()->createWithContent('fingerprint.csv', $csv),
            ])
            ->assertCreated()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.error_count', 1)
            ->assertJsonPath('data.errors.0.message', 'Kode SN mesin JHG3240400192 belum terdaftar di Data Master Mesin Absensi.');

        $this->registerAttendanceMachine(false);
        $this->withHeaders($this->apiHeaders())
            ->post('/api/v1/fingerprint-imports', [
                'file' => UploadedFile::fake()->createWithContent('fingerprint-inactive.csv', $csv),
            ])
            ->assertCreated()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.error_count', 1)
            ->assertJsonPath('data.errors.0.message', 'Mesin Mesin Fingerprint Utama berstatus tidak aktif.');
    }

    public function test_recap_shows_different_check_in_and_check_out_machine_locations(): void
    {
        $employee = Employee::query()->where('machine_id', '10')->firstOrFail();
        AttendanceMachine::query()->create([
            'name' => 'Mesin Bandung', 'serial_number' => 'SN-BDG', 'location' => 'FTM Bandung', 'active' => true,
        ]);
        AttendanceMachine::query()->create([
            'name' => 'Mesin Cimahi', 'serial_number' => 'SN-CMI', 'location' => 'FTM Cimahi', 'active' => true,
        ]);
        AttendanceRecord::query()->create([
            'employee_id' => $employee->id,
            'attendance_date' => '2027-04-05',
            'status' => 'present',
            'check_in' => '08:00:00',
            'check_out' => '17:00:00',
            'source' => 'fingerprint',
            'incomplete' => false,
        ]);
        FingerprintScan::query()->create([
            'employee_id' => $employee->id,
            'scanned_at' => '2027-04-05 08:00:00',
            'device_serial' => 'SN-BDG',
        ]);
        FingerprintScan::query()->create([
            'employee_id' => $employee->id,
            'scanned_at' => '2027-04-05 17:00:00',
            'device_serial' => 'SN-CMI',
        ]);

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/v1/attendances?month=4&year=2027')
            ->assertOk();
        $record = collect($response->json('data'))->firstWhere('employee_id', $employee->id);

        $this->assertSame(
            'Masuk: Mesin Bandung — FTM Bandung · Pulang: Mesin Cimahi — FTM Cimahi',
            $record['machine_location']
        );
    }
}
