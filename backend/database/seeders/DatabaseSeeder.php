<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\OvertimeRule;
use App\Models\WorkSchedule;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $categories = collect([
            ['name' => 'Tendik Dekanat', 'group' => 'Tendik'],
            ['name' => 'Tendik Teknik Mesin', 'group' => 'Tendik'],
            ['name' => 'Tendik Teknik Industri', 'group' => 'Tendik'],
            ['name' => 'Tendik Teknik Metalurgi', 'group' => 'Tendik'],
            ['name' => 'Tendik MMT', 'group' => 'Tendik'],
            ['name' => 'Dosen Teknik Mesin', 'group' => 'Dosen'],
            ['name' => 'Dosen Teknik Industri', 'group' => 'Dosen'],
            ['name' => 'Dosen Teknik Metalurgi', 'group' => 'Dosen'],
            ['name' => 'Dosen MMT', 'group' => 'Dosen'],
            ['name' => 'Pimpinan', 'group' => 'Pimpinan'],
        ])->mapWithKeys(function (array $category): array {
            $model = Category::query()->create([...$category, 'active' => true]);

            return [$model->name => $model];
        });

        WorkSchedule::query()->create([
            'name' => 'Jam Kerja Umum',
            'work_start' => '08:00',
            'work_end' => '17:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'late_tolerance_minutes' => 0,
            'early_leave_tolerance_minutes' => 0,
            'effective_from' => '2026-01-01',
            'active' => true,
        ]);

        OvertimeRule::query()->create([
            'name' => 'Lembur Umum',
            'starts_after_minutes' => 120,
            'max_minutes' => 240,
            'effective_from' => '2026-01-01',
            'active' => true,
        ]);

        Holiday::query()->create([
            'date' => '2026-08-17',
            'name' => 'Hari Kemerdekaan Republik Indonesia',
            'type' => 'national',
            'active' => true,
        ]);

        $employees = [
            ['nid_nip' => '198704112', 'name' => 'Ayu Lestari', 'position' => 'Staf Administrasi', 'machine_id' => '10', 'categories' => ['Tendik Dekanat']],
            ['nid_nip' => '199102208', 'name' => 'Budi Santoso', 'position' => 'Dosen', 'machine_id' => '11', 'categories' => ['Dosen Teknik Mesin', 'Pimpinan']],
            ['nid_nip' => '198909305', 'name' => 'Citra Maharani', 'position' => 'Dosen', 'machine_id' => '13', 'categories' => ['Dosen Teknik Industri']],
            ['nid_nip' => '199306417', 'name' => 'Dimas Pratama', 'position' => 'Laboran', 'machine_id' => '15', 'categories' => ['Tendik Teknik Mesin']],
            ['nid_nip' => '198511520', 'name' => 'Erna Wulandari', 'position' => 'Dosen', 'machine_id' => '16', 'categories' => ['Dosen MMT', 'Pimpinan']],
            ['nid_nip' => '199408623', 'name' => 'Fajar Nugraha', 'position' => 'Staf Program Studi', 'machine_id' => '17', 'categories' => ['Tendik Teknik Industri']],
            ['nid_nip' => '199011734', 'name' => 'Gita Permatasari', 'position' => 'Dosen', 'machine_id' => '19', 'categories' => ['Dosen Teknik Metalurgi']],
            ['nid_nip' => '198802841', 'name' => 'Hendra Kurniawan', 'position' => 'Teknisi', 'machine_id' => '22', 'categories' => ['Tendik Teknik Metalurgi']],
        ];

        foreach ($employees as $employeeData) {
            $categoryNames = $employeeData['categories'];
            unset($employeeData['categories']);
            $employee = Employee::query()->create([
                ...$employeeData,
                'employment_start_date' => '2020-01-01',
                'active' => true,
            ]);
            $employee->categories()->sync($categories->only($categoryNames)->pluck('id'));
        }
    }
}
