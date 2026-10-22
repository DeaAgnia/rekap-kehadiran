<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\AttendanceReportExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class AttendanceReportExportTest extends TestCase
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

    public function test_export_filters_are_validated(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/v1/attendance-reports/export?month=13&year=1999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month', 'year']);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/v1/attendance-reports/export?month=8&year=2026&category=Tidak%20Ada')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category']);
    }

    public function test_complete_workbook_matches_monthly_attendance_layout(): void
    {
        $employee = Employee::query()->where('machine_id', '10')->firstOrFail();
        $leader = Employee::query()->where('machine_id', '11')->firstOrFail();
        $employee->update([
            'attendance_type' => 'mpp',
            'attendance_type_start_date' => '2026-08-04',
            'attendance_type_end_date' => '2026-08-06',
        ]);
        AttendanceRecord::query()->create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-08-03',
            'status' => 'present',
            'check_in' => '08:05:00',
            'check_out' => '19:30:00',
            'source' => 'fingerprint',
            'incomplete' => false,
        ]);
        AttendanceRecord::query()->create([
            'employee_id' => $leader->id,
            'attendance_date' => '2026-08-03',
            'status' => 'absent',
            'source' => 'system',
            'incomplete' => false,
        ]);

        $result = app(AttendanceReportExporter::class)->create(8, 2026);

        try {
            $workbook = IOFactory::load($result['path']);
            $this->assertSame(7, $workbook->getSheetCount());
            $this->assertSame([
                'Rekap Absensi Agustus (ALL)',
                'Absensi Tendik',
                'Absensi Pimpinan',
                'Absensi Dosen Prodi T.Mesin',
                'Absensi Dosen Prodi T. Industri',
                'Absensi Dosen Prodi T.Metal',
                'Absensi Dosen Prodi MMT',
            ], $workbook->getSheetNames());

            $sheet = $workbook->getSheet(0);
            $this->assertStringContainsString('SELURUH PEGAWAI', (string) $sheet->getCell('A1')->getValue());
            $this->assertSame('No', $sheet->getCell('A5')->getValue());
            $this->assertSame('Nama', $sheet->getCell('B5')->getValue());
            $this->assertSame('NID/NIP', $sheet->getCell('C5')->getValue());
            $this->assertSame('L', $sheet->getCell('D7')->getValue());
            $this->assertSame(1, $sheet->getCell('F7')->getValue());
            $this->assertSame('MPP', $sheet->getCell('G7')->getValue());
            $this->assertSame(1, $sheet->getCell('F8')->getValue());

            $presentColumn = Coordinate::stringFromColumnIndex(3 + 31 + 9);
            $this->assertStringContainsString('COUNTIF', (string) $sheet->getCell("{$presentColumn}7")->getValue());
            $this->assertSame('D7', (string) $sheet->getFreezePane());
            $workbook->disconnectWorksheets();
        } finally {
            @unlink($result['path']);
        }
    }

    public function test_single_employee_filter_creates_individual_report(): void
    {
        $employee = Employee::query()->where('machine_id', '11')->firstOrFail();
        AttendanceRecord::query()->create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-02',
            'status' => 'present',
            'check_in' => '08:00:00',
            'check_out' => '17:00:00',
            'source' => 'fingerprint',
            'incomplete' => false,
        ]);

        $result = app(AttendanceReportExporter::class)->create(9, 2026, 'Dosen Teknik Mesin');

        try {
            $workbook = IOFactory::load($result['path']);
            $this->assertSame(1, $workbook->getSheetCount());
            $this->assertSame('Laporan Perorangan', $workbook->getSheet(0)->getTitle());
            $this->assertSame('Laporan_BudiSantoso_September_2026.xlsx', $result['filename']);
            $this->assertSame('Budi Santoso', $workbook->getSheet(0)->getCell('C7')->getValue());
            $this->assertSame('NIP/NID', $workbook->getSheet(0)->getCell('A8')->getValue());
            $this->assertSame('Status', $workbook->getSheet(0)->getCell('E10')->getValue());
            $this->assertSame('Hadir', $workbook->getSheet(0)->getCell('E12')->getValue());
            $this->assertSame('Mesin', $workbook->getSheet(0)->getCell('F12')->getValue());
            $this->assertSame('08:00', $workbook->getSheet(0)->getCell('G12')->getValue());
            $this->assertSame('17:00', $workbook->getSheet(0)->getCell('H12')->getValue());
            $this->assertSame('8j 0m', $workbook->getSheet(0)->getCell('I12')->getValue());
            $this->assertSame('A11', (string) $workbook->getSheet(0)->getFreezePane());
            $workbook->disconnectWorksheets();
        } finally {
            @unlink($result['path']);
        }
    }

    public function test_export_endpoint_returns_an_xlsx_download_and_logs_activity(): void
    {
        $response = $this->withHeaders($this->apiHeaders())
            ->get('/api/v1/attendance-reports/export?month=8&year=2026');

        $filePath = $response->baseResponse->getFile()->getPathname();

        try {
            $response->assertOk();
            $this->assertStringContainsString(
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                (string) $response->headers->get('content-type')
            );
            $this->assertDatabaseHas('activity_logs', [
                'action' => 'attendance_report.exported',
                'status' => 'success',
            ]);
        } finally {
            @unlink($filePath);
        }
    }
}
