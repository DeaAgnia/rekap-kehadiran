<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceMachineApiTest extends TestCase
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

    public function test_machine_form_requires_all_fields_and_unique_serial_number(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/attendance-machines', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'serial_number', 'location', 'active']);

        $payload = [
            'name' => 'Mesin Utama Bandung',
            'serial_number' => 'JHG3240400192',
            'location' => 'Lobi Gedung FTM Bandung',
            'active' => true,
        ];
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/attendance-machines', $payload)
            ->assertCreated();

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/attendance-machines', [
                ...$payload,
                'name' => 'Mesin Duplikat',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['serial_number']);
    }

    public function test_machine_crud_and_status_work_with_activity_logs(): void
    {
        $created = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/attendance-machines', [
                'name' => 'Mesin Utama Bandung',
                'serial_number' => 'JHG3240400192',
                'location' => 'Lobi Gedung FTM Bandung',
                'active' => true,
            ])
            ->assertCreated()
            ->json('data');

        $this->withHeaders($this->apiHeaders())
            ->putJson("/api/v1/attendance-machines/{$created['id']}", [
                'name' => 'Mesin Utama Cimahi',
                'serial_number' => 'JHG3240400192',
                'location' => 'Lobi Gedung FTM Cimahi',
                'active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.location', 'Lobi Gedung FTM Cimahi');

        $this->withHeaders($this->apiHeaders())
            ->patchJson("/api/v1/attendance-machines/{$created['id']}/status", ['active' => false])
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/v1/attendance-machines')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withHeaders($this->apiHeaders())
            ->deleteJson("/api/v1/attendance-machines/{$created['id']}")
            ->assertOk();
        $this->assertDatabaseMissing('attendance_machines', ['id' => $created['id']]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'attendance_machine.deleted',
            'status' => 'success',
        ]);
    }
}
