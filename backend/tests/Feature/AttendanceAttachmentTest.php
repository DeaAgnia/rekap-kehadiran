<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttendanceAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.internal_api_key' => 'test-internal-key']);
        $this->seed();
        Storage::fake('local');
    }

    private function apiHeaders(): array
    {
        return ['X-Internal-Api-Key' => 'test-internal-key', 'Accept' => 'application/json'];
    }

    public function test_every_status_can_optionally_receive_an_attachment(): void
    {
        $employee = Employee::query()->firstOrFail();

        foreach (['present', 'leave', 'duty', 'sick', 'permission', 'absent'] as $index => $status) {
            $this->withHeaders($this->apiHeaders())
                ->post('/api/v1/attendances', [
                    'employee_id' => $employee->id,
                    'attendance_date' => '2027-05-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                    'status' => $status,
                    'attachment' => $this->fakePdf("lampiran-{$status}.pdf"),
                ])
                ->assertCreated()
                ->assertJsonPath('data.attachment.name', "lampiran-{$status}.pdf");
        }
    }

    public function test_admin_can_upload_view_and_delete_a_sick_letter(): void
    {
        $employee = Employee::query()->firstOrFail();

        $created = $this->withHeaders($this->apiHeaders())
            ->post('/api/v1/attendances', [
                'employee_id' => $employee->id,
                'attendance_date' => '2027-04-04',
                'status' => 'sick',
                'note' => 'Surat dokter terlampir.',
                'attachment' => $this->fakePdf('surat-sakit.pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.attachment.name', 'surat-sakit.pdf')
            ->assertJsonPath('data.attachment.mime_type', 'application/pdf')
            ->json('data');

        $attendance = AttendanceRecord::query()->findOrFail($created['id']);
        $this->assertNotNull($attendance->attachment_path);
        Storage::disk('local')->assertExists($attendance->attachment_path);

        $this->withHeaders($this->apiHeaders())
            ->get("/api/v1/attendances/{$attendance->id}/attachment")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'attendance.attachment_viewed',
            'status' => 'success',
        ]);

        $this->withHeaders($this->apiHeaders())
            ->putJson("/api/v1/attendances/{$attendance->id}", [
                'employee_id' => $employee->id,
                'attendance_date' => '2027-04-04',
                'status' => 'sick',
                'note' => 'Catatan diperbarui tanpa mengganti lampiran.',
            ])
            ->assertOk()
            ->assertJsonPath('data.attachment.name', 'surat-sakit.pdf');

        $this->withHeaders($this->apiHeaders())
            ->deleteJson("/api/v1/attendances/{$attendance->id}")
            ->assertOk();
        Storage::disk('local')->assertMissing($attendance->attachment_path);
    }

    private function fakePdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF"
        );
    }
}
