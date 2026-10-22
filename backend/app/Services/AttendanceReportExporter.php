<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Category;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\OvertimeRule;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AttendanceReportExporter
{
    private const MONTH_NAMES = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    private const STATUS_CODES = [
        'present' => 1,
        'leave' => 'C',
        'duty' => 'T',
        'sick' => 'S',
        'permission' => 'i',
        'absent' => 'TK',
        'mpp' => 'MPP',
        'stb' => 'STB',
    ];

    public function __construct(private readonly AttendanceStatusResolver $statusResolver) {}

    /**
     * @return array{path: string, filename: string, employee_count: int, sheet_count: int}
     */
    public function create(int $month, int $year, ?string $categoryName = null, ?string $search = null): array
    {
        $periodStart = Carbon::create($year, $month, 1, 0, 0, 0, 'Asia/Jakarta')->startOfDay();
        $periodEnd = $periodStart->copy()->endOfMonth();
        $search = trim((string) $search) ?: null;

        $employees = Employee::query()
            ->with('categories:id,name,group,active')
            ->whereDate('employment_start_date', '<=', $periodEnd->toDateString())
            ->when($categoryName, fn ($query, $category) => $query->whereHas(
                'categories',
                fn ($categoryQuery) => $categoryQuery->where('name', $category)->where('active', true)
            ))
            ->when($search, fn ($query, $value) => $query->where(
                fn ($nested) => $nested
                    ->where('name', 'like', "%{$value}%")
                    ->orWhere('nid_nip', 'like', "%{$value}%")
            ))
            ->orderBy('name')
            ->get();

        $categories = Category::query()->where('active', true)->orderBy('id')->get();
        $employeeIds = $employees->pluck('id');
        $records = $employeeIds->isEmpty()
            ? new EloquentCollection
            : AttendanceRecord::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereBetween('attendance_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
                ->orderBy('attendance_date')
                ->get();
        $recordMap = $records->keyBy(fn (AttendanceRecord $record) => $this->recordKey(
            $record->employee_id,
            $record->attendance_date->toDateString()
        ));

        $holidayMap = Holiday::query()
            ->where('active', true)
            ->whereBetween('date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->get()
            ->keyBy(fn (Holiday $holiday) => $holiday->date->toDateString());
        $workSchedules = WorkSchedule::query()
            ->where('active', true)
            ->whereDate('effective_from', '<=', $periodEnd->toDateString())
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $periodStart->toDateString()))
            ->latest('effective_from')
            ->get();
        $overtimeRules = OvertimeRule::query()
            ->where('active', true)
            ->whereDate('effective_from', '<=', $periodEnd->toDateString())
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $periodStart->toDateString()))
            ->latest('effective_from')
            ->get();
        $timeMetrics = $this->calculateTimeMetrics($records, $holidayMap, $workSchedules, $overtimeRules);
        $individualEmployee = $employees->count() === 1 ? $employees->first() : null;
        $definitions = $individualEmployee
            ? []
            : $this->sheetDefinitions($employees, $categories, $month, $categoryName);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator('Kehadiran FTM')
            ->setTitle("Rekap Absensi FTM Periode {$this->monthName($month)} {$year}")
            ->setSubject('Rekap kehadiran pegawai Fakultas Teknologi Manufaktur');

        if ($individualEmployee) {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Laporan Perorangan');
            $this->buildIndividualSheet(
                $sheet,
                $individualEmployee,
                $periodStart,
                $periodEnd,
                $recordMap,
                $holidayMap,
                $workSchedules,
                $overtimeRules,
            );
        } else {
            foreach ($definitions as $index => $definition) {
                $sheet = $index === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
                $sheet->setTitle($definition['sheet_title']);
                $this->buildSheet(
                    $sheet,
                    $definition,
                    $periodStart,
                    $periodEnd,
                    $recordMap,
                    $holidayMap,
                    $timeMetrics,
                );
            }
        }

        $spreadsheet->setActiveSheetIndex(0);
        $reportDirectory = storage_path('app/private/reports');
        File::ensureDirectoryExists($reportDirectory);
        $temporaryPath = tempnam($reportDirectory, 'rekap-kehadiran-');
        if ($temporaryPath === false) {
            throw new \RuntimeException('File sementara laporan tidak dapat dibuat.');
        }

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(true);
        $writer->save($temporaryPath);
        $spreadsheet->disconnectWorksheets();

        $filename = $individualEmployee
            ? sprintf(
                'Laporan_%s_%s_%d.xlsx',
                preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($individualEmployee->name)),
                $this->monthName($month),
                $year,
            )
            : sprintf('Rekap Absensi FTM Periode %s %d.xlsx', $this->monthName($month), $year);

        return [
            'path' => $temporaryPath,
            'filename' => $filename,
            'employee_count' => $employees->count(),
            'sheet_count' => $individualEmployee ? 1 : count($definitions),
        ];
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<int, Category>  $categories
     * @return array<int, array{sheet_title: string, report_title: string, section_title: string, grouped: bool, groups: array<int, array{label: string, employees: Collection<int, Employee>}>}>
     */
    private function sheetDefinitions(Collection $employees, Collection $categories, int $month, ?string $categoryName): array
    {
        if ($categoryName) {
            return [[
                'sheet_title' => $this->safeSheetTitle("Absensi {$categoryName}"),
                'report_title' => 'REKAPITULASI ABSENSI PEGAWAI FAKULTAS TEKNOLOGI MANUFAKTUR UNJANI',
                'section_title' => mb_strtoupper($categoryName),
                'grouped' => false,
                'groups' => [['label' => mb_strtoupper($categoryName), 'employees' => $employees]],
            ]];
        }

        $definitions = [[
            'sheet_title' => $this->safeSheetTitle("Rekap Absensi {$this->monthName($month)} (ALL)"),
            'report_title' => 'REKAPITULASI ABSENSI SELURUH PEGAWAI FAKULTAS TEKNOLOGI MANUFAKTUR UNJANI',
            'section_title' => 'SELURUH PEGAWAI',
            'grouped' => false,
            'groups' => [['label' => 'SELURUH PEGAWAI', 'employees' => $employees]],
        ]];

        $tendikCategories = $categories->where('group', 'Tendik')->values();
        $definitions[] = [
            'sheet_title' => 'Absensi Tendik',
            'report_title' => 'REKAPITULASI ABSENSI TENAGA KEPENDIDIKAN FAKULTAS TEKNOLOGI MANUFAKTUR UNJANI',
            'section_title' => 'TENAGA KEPENDIDIKAN',
            'grouped' => true,
            'groups' => $tendikCategories->map(fn (Category $category) => [
                'label' => mb_strtoupper($category->name),
                'employees' => $this->employeesForCategory($employees, $category),
            ])->all(),
        ];

        $leaderCategory = $categories->firstWhere('group', 'Pimpinan');
        $definitions[] = [
            'sheet_title' => 'Absensi Pimpinan',
            'report_title' => 'REKAPITULASI ABSENSI PIMPINAN FAKULTAS TEKNOLOGI MANUFAKTUR UNJANI',
            'section_title' => 'PIMPINAN',
            'grouped' => false,
            'groups' => [[
                'label' => 'PIMPINAN',
                'employees' => $leaderCategory ? $this->employeesForCategory($employees, $leaderCategory) : collect(),
            ]],
        ];

        foreach ($categories->where('group', 'Dosen')->values() as $category) {
            $definitions[] = [
                'sheet_title' => $this->dosenSheetTitle($category->name),
                'report_title' => 'REKAPITULASI ABSENSI PEGAWAI DOSEN FAKULTAS TEKNOLOGI MANUFAKTUR UNJANI',
                'section_title' => mb_strtoupper(str_replace('Dosen ', '', $category->name)),
                'grouped' => false,
                'groups' => [[
                    'label' => mb_strtoupper($category->name),
                    'employees' => $this->employeesForCategory($employees, $category),
                ]],
            ];
        }

        return $definitions;
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, Employee>
     */
    private function employeesForCategory(Collection $employees, Category $category): Collection
    {
        return $employees
            ->filter(fn (Employee $employee) => $employee->categories->contains('id', $category->id))
            ->values();
    }

    /**
     * @param  array{sheet_title: string, report_title: string, section_title: string, grouped: bool, groups: array<int, array{label: string, employees: Collection<int, Employee>}>}  $definition
     * @param  Collection<string, AttendanceRecord>  $recordMap
     * @param  Collection<string, Holiday>  $holidayMap
     * @param  array<int, array{late: int, early: int, overtime: int}>  $timeMetrics
     */
    private function buildSheet(
        Worksheet $sheet,
        array $definition,
        Carbon $periodStart,
        Carbon $periodEnd,
        Collection $recordMap,
        Collection $holidayMap,
        array $timeMetrics,
    ): void {
        $daysInMonth = $periodEnd->day;
        $firstDayColumn = 4;
        $lastDayColumn = $firstDayColumn + $daysInMonth - 1;
        $workdayColumn = $lastDayColumn + 1;
        $sickColumn = $workdayColumn + 1;
        $permissionColumn = $sickColumn + 1;
        $absentColumn = $permissionColumn + 1;
        $dutyColumn = $absentColumn + 1;
        $leaveColumn = $dutyColumn + 1;
        $mppColumn = $leaveColumn + 1;
        $stbColumn = $mppColumn + 1;
        $presentColumn = $stbColumn + 1;
        $lateColumn = $presentColumn + 1;
        $earlyColumn = $lateColumn + 1;
        $overtimeColumn = $earlyColumn + 1;
        $lastColumn = $overtimeColumn;
        $lastColumnLetter = Coordinate::stringFromColumnIndex($lastColumn);
        $firstDayLetter = Coordinate::stringFromColumnIndex($firstDayColumn);
        $lastDayLetter = Coordinate::stringFromColumnIndex($lastDayColumn);

        $sheet->setShowGridlines(false);
        $sheet->freezePane('D7');
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.3)->setRight(0.25)->setBottom(0.3)->setLeft(0.25);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 6);

        $sheet->mergeCells("A1:{$lastColumnLetter}1");
        $sheet->setCellValue('A1', $definition['report_title']);
        $sheet->mergeCells("A2:{$lastColumnLetter}2");
        $sheet->setCellValue('A2', sprintf(
            'PERIODE : 1 %s %d S/D %d %s %d',
            mb_strtoupper($this->monthName($periodStart->month)),
            $periodStart->year,
            $periodEnd->day,
            mb_strtoupper($this->monthName($periodEnd->month)),
            $periodEnd->year,
        ));
        $sheet->mergeCells("A4:{$lastColumnLetter}4");
        $sheet->setCellValue('A4', $definition['section_title']);

        $this->writeTableHeader(
            $sheet,
            $periodStart,
            $holidayMap,
            $lastDayColumn,
            $workdayColumn,
            $sickColumn,
            $permissionColumn,
            $absentColumn,
            $dutyColumn,
            $leaveColumn,
            $mppColumn,
            $stbColumn,
            $presentColumn,
            $lateColumn,
            $earlyColumn,
            $overtimeColumn,
        );

        $sheet->getStyle("A1:{$lastColumnLetter}2")->getFont()->setName('Times New Roman')->setBold(true);
        $sheet->getStyle('A1')->getFont()->setSize(12);
        $sheet->getStyle('A2')->getFont()->setSize(10);
        $sheet->getStyle("A1:{$lastColumnLetter}2")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $this->styleSectionBand($sheet, 4, $lastColumnLetter);

        $row = 7;
        $sequence = 1;
        foreach ($definition['groups'] as $groupIndex => $group) {
            if ($definition['grouped']) {
                if ($groupIndex > 0) {
                    $row++;
                }
                $sheet->mergeCells("A{$row}:{$lastColumnLetter}{$row}");
                $sheet->setCellValue("A{$row}", $group['label']);
                $this->styleSectionBand($sheet, $row, $lastColumnLetter);
                $row++;
            }

            foreach ($group['employees'] as $employee) {
                $this->writeEmployeeRow(
                    $sheet,
                    $row,
                    $sequence,
                    $employee,
                    $periodStart,
                    $periodEnd,
                    $recordMap,
                    $holidayMap,
                    $timeMetrics[$employee->id] ?? ['late' => 0, 'early' => 0, 'overtime' => 0],
                    $firstDayColumn,
                    $lastDayColumn,
                    $workdayColumn,
                    $sickColumn,
                    $permissionColumn,
                    $absentColumn,
                    $dutyColumn,
                    $leaveColumn,
                    $mppColumn,
                    $stbColumn,
                    $presentColumn,
                    $lateColumn,
                    $earlyColumn,
                    $overtimeColumn,
                );
                $row++;
                $sequence++;
            }
        }

        if ($sequence === 1) {
            $sheet->mergeCells("A7:{$lastColumnLetter}8");
            $sheet->setCellValue('A7', 'Tidak ada data pegawai untuk filter yang dipilih.');
            $sheet->getStyle("A7:{$lastColumnLetter}8")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle('A7')->getFont()->setName('Times New Roman')->setItalic(true);
            $row = 9;
        }

        $sheet->getStyle("A5:{$lastColumnLetter}".($row - 1))->getFont()->setName('Times New Roman')->setSize(9);
        $sheet->getStyle("A5:{$lastColumnLetter}".($row - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(42);
        $sheet->getColumnDimension('C')->setWidth(16);
        for ($column = $firstDayColumn; $column <= $lastDayColumn; $column++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth(4.2);
        }
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($workdayColumn))->setWidth(12);
        foreach (range($sickColumn, $stbColumn) as $column) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth(6.5);
        }
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($presentColumn))->setWidth(13);
        foreach (range($lateColumn, $overtimeColumn) as $column) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth(14);
        }
        $sheet->getRowDimension(1)->setRowHeight(20);
        $sheet->getRowDimension(2)->setRowHeight(18);
        $sheet->getRowDimension(4)->setRowHeight(20);
        $sheet->getRowDimension(5)->setRowHeight(28);
        $sheet->getRowDimension(6)->setRowHeight(22);
        $sheet->getStyle("{$firstDayLetter}5:{$lastDayLetter}6")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    /** @param Collection<string, Holiday> $holidayMap */
    private function writeTableHeader(
        Worksheet $sheet,
        Carbon $periodStart,
        Collection $holidayMap,
        int $lastDayColumn,
        int $workdayColumn,
        int $sickColumn,
        int $permissionColumn,
        int $absentColumn,
        int $dutyColumn,
        int $leaveColumn,
        int $mppColumn,
        int $stbColumn,
        int $presentColumn,
        int $lateColumn,
        int $earlyColumn,
        int $overtimeColumn,
    ): void {
        $lastColumnLetter = Coordinate::stringFromColumnIndex($overtimeColumn);
        foreach (['A' => 'No', 'B' => 'Nama', 'C' => 'NID/NIP'] as $column => $label) {
            $sheet->mergeCells("{$column}5:{$column}6");
            $sheet->setCellValue("{$column}5", $label);
        }

        $firstDayLetter = Coordinate::stringFromColumnIndex(4);
        $lastDayLetter = Coordinate::stringFromColumnIndex($lastDayColumn);
        $sheet->mergeCells("{$firstDayLetter}5:{$lastDayLetter}5");
        $sheet->setCellValue("{$firstDayLetter}5", "{$this->monthName($periodStart->month)} {$periodStart->year}");

        for ($day = 1; $day <= $periodStart->daysInMonth; $day++) {
            $column = 3 + $day;
            $letter = Coordinate::stringFromColumnIndex($column);
            $date = $periodStart->copy()->day($day);
            $sheet->setCellValue("{$letter}6", $day);
            if ($this->isDayOff($date, $holidayMap)) {
                $sheet->getStyle("{$letter}6")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('A6A6A6');
            }
        }

        $workdayLetter = Coordinate::stringFromColumnIndex($workdayColumn);
        $sheet->mergeCells("{$workdayLetter}5:{$workdayLetter}6");
        $sheet->setCellValue("{$workdayLetter}5", 'Jumlah Hari Kerja');

        $sickLetter = Coordinate::stringFromColumnIndex($sickColumn);
        $stbLetter = Coordinate::stringFromColumnIndex($stbColumn);
        $sheet->mergeCells("{$sickLetter}5:{$stbLetter}5");
        $sheet->setCellValue("{$sickLetter}5", 'Keterangan');
        foreach ([
            $sickColumn => 'S',
            $permissionColumn => 'Izin',
            $absentColumn => 'TK',
            $dutyColumn => 'T',
            $leaveColumn => 'C',
            $mppColumn => 'MPP',
            $stbColumn => 'STB',
        ] as $column => $label) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($column).'6', $label);
        }

        $presentLetter = Coordinate::stringFromColumnIndex($presentColumn);
        $sheet->mergeCells("{$presentLetter}5:{$presentLetter}6");
        $sheet->setCellValue("{$presentLetter}5", 'Jumlah Total Hadir');

        $lateLetter = Coordinate::stringFromColumnIndex($lateColumn);
        $overtimeLetter = Coordinate::stringFromColumnIndex($overtimeColumn);
        $sheet->mergeCells("{$lateLetter}5:{$overtimeLetter}5");
        $sheet->setCellValue("{$lateLetter}5", 'Evaluasi Waktu (menit)');
        $sheet->setCellValue("{$lateLetter}6", 'Terlambat');
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($earlyColumn).'6', 'Pulang Cepat');
        $sheet->setCellValue("{$overtimeLetter}6", 'Lembur');

        $headerStyle = $sheet->getStyle("A5:{$lastColumnLetter}6");
        $headerStyle->getFont()->setName('Times New Roman')->setSize(10)->setBold(true);
        $headerStyle->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
        $headerStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('404040');
        $sheet->getStyle("A6:{$lastColumnLetter}6")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);
    }

    /**
     * @param  Collection<string, AttendanceRecord>  $recordMap
     * @param  Collection<string, Holiday>  $holidayMap
     * @param  array{late: int, early: int, overtime: int}  $metrics
     */
    private function writeEmployeeRow(
        Worksheet $sheet,
        int $row,
        int $sequence,
        Employee $employee,
        Carbon $periodStart,
        Carbon $periodEnd,
        Collection $recordMap,
        Collection $holidayMap,
        array $metrics,
        int $firstDayColumn,
        int $lastDayColumn,
        int $workdayColumn,
        int $sickColumn,
        int $permissionColumn,
        int $absentColumn,
        int $dutyColumn,
        int $leaveColumn,
        int $mppColumn,
        int $stbColumn,
        int $presentColumn,
        int $lateColumn,
        int $earlyColumn,
        int $overtimeColumn,
    ): void {
        $sheet->setCellValue("A{$row}", $sequence);
        $sheet->setCellValue("B{$row}", $employee->name);
        $sheet->setCellValueExplicit("C{$row}", $employee->nid_nip, DataType::TYPE_STRING);
        $workdayCount = 0;

        for ($day = 1; $day <= $periodEnd->day; $day++) {
            $date = $periodStart->copy()->day($day);
            $column = $firstDayColumn + $day - 1;
            $letter = Coordinate::stringFromColumnIndex($column);
            $isDayOff = $this->isDayOff($date, $holidayMap);
            $isEmployed = $employee->employment_start_date->startOfDay()->lessThanOrEqualTo($date);
            $record = $isEmployed ? $recordMap->get($this->recordKey($employee->id, $date->toDateString())) : null;
            $rawStatus = $record?->status ?? ($date->isBefore(Carbon::now('Asia/Jakarta')->startOfDay()) ? 'absent' : null);
            $effectiveStatus = $rawStatus ? $this->statusResolver->resolve($rawStatus, $employee, $date) : null;

            if ($isEmployed && ! $isDayOff && ! in_array($effectiveStatus, ['mpp', 'stb'], true)) {
                $workdayCount++;
            }

            $value = null;
            if ($isEmployed && $isDayOff) {
                $value = 'L';
                $sheet->getStyle("{$letter}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('A6A6A6');
            } elseif ($isEmployed) {
                $value = $effectiveStatus ? (self::STATUS_CODES[$effectiveStatus] ?? null) : null;
            }

            $sheet->setCellValue("{$letter}{$row}", $value);
        }

        $workdayLetter = Coordinate::stringFromColumnIndex($workdayColumn);
        $sheet->setCellValue("{$workdayLetter}{$row}", $workdayCount);
        $dailyRange = Coordinate::stringFromColumnIndex($firstDayColumn).$row.':'.Coordinate::stringFromColumnIndex($lastDayColumn).$row;
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($sickColumn).$row, "=COUNTIF({$dailyRange},\"S\")");
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($permissionColumn).$row, "=COUNTIF({$dailyRange},\"i\")");
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($absentColumn).$row, "=COUNTIF({$dailyRange},\"TK\")");
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($dutyColumn).$row, "=COUNTIF({$dailyRange},\"T\")");
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($leaveColumn).$row, "=COUNTIF({$dailyRange},\"C\")");
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($mppColumn).$row, "=COUNTIF({$dailyRange},\"MPP\")");
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($stbColumn).$row, "=COUNTIF({$dailyRange},\"STB\")");
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($presentColumn).$row, "=COUNTIF({$dailyRange},1)+COUNTIF({$dailyRange},\"T\")");
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($lateColumn).$row, $metrics['late']);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($earlyColumn).$row, $metrics['early']);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($overtimeColumn).$row, $metrics['overtime']);

        $lastColumnLetter = Coordinate::stringFromColumnIndex($overtimeColumn);
        $sheet->getStyle("A{$row}:C{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('606060');
        $sheet->getStyle(Coordinate::stringFromColumnIndex($firstDayColumn).$row.':'.Coordinate::stringFromColumnIndex($lastDayColumn).$row)
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_DASHED)->getColor()->setRGB('606060');
        $sheet->getStyle("{$workdayLetter}{$row}:{$lastColumnLetter}{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('606060');
        $sheet->getStyle(Coordinate::stringFromColumnIndex($lateColumn).$row.':'.Coordinate::stringFromColumnIndex($overtimeColumn).$row)
            ->getNumberFormat()->setFormatCode('0');
        $sheet->getStyle("A{$row}:A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("C{$row}:{$lastColumnLetter}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("B{$row}")->getAlignment()->setWrapText(true);
        $sheet->getRowDimension($row)->setRowHeight(19);
    }

    /**
     * @param  Collection<string, AttendanceRecord>  $recordMap
     * @param  Collection<string, Holiday>  $holidayMap
     * @param  Collection<int, WorkSchedule>  $workSchedules
     * @param  Collection<int, OvertimeRule>  $overtimeRules
     */
    private function buildIndividualSheet(
        Worksheet $sheet,
        Employee $employee,
        Carbon $periodStart,
        Carbon $periodEnd,
        Collection $recordMap,
        Collection $holidayMap,
        Collection $workSchedules,
        Collection $overtimeRules,
    ): void {
        $today = Carbon::now('Asia/Jakarta')->startOfDay();
        $reportEnd = $periodStart->isSameMonth($today) && $today->lessThan($periodEnd)
            ? $today
            : $periodEnd;
        $dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $statusLabels = [
            'present' => 'Hadir',
            'leave' => 'Cuti',
            'duty' => 'Tugas',
            'sick' => 'Sakit',
            'permission' => 'Izin',
            'absent' => 'Tanpa Keterangan',
            'mpp' => 'MPP',
            'stb' => 'STB',
        ];
        $summary = [
            'late_days' => 0,
            'incomplete_days' => 0,
            'absent_days' => 0,
            'permission_days' => 0,
            'mpp_days' => 0,
            'stb_days' => 0,
            'workdays' => 0,
            'present_days' => 0,
            'work_minutes' => 0,
            'late_minutes' => 0,
            'early_minutes' => 0,
        ];

        $sheet->setShowGridlines(false);
        $sheet->freezePane('A11');
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToWidth(1)
            ->setFitToHeight(1);
        $sheet->getPageMargins()->setTop(0.3)->setRight(0.25)->setBottom(0.3)->setLeft(0.25);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 10);

        foreach (['A1:M1', 'A2:M2', 'A3:M3', 'A4:M4'] as $range) {
            $sheet->mergeCells($range);
        }
        $sheet->setCellValue('A1', 'UNIVERSITAS JENDERAL ACHMAD YANI');
        $sheet->setCellValue('A2', 'Fakultas Teknologi Manufaktur');
        $sheet->setCellValue('A3', 'LAPORAN KEHADIRAN PERORANGAN');
        $sheet->setCellValue('A4', "Periode: {$this->monthName($periodStart->month)} {$periodStart->year}");

        foreach (['A6:B6', 'C6:F6', 'G6:H6', 'I6:M6', 'A7:B7', 'C7:F7', 'G7:H7', 'I7:M7', 'A8:B8', 'C8:F8', 'G8:H8', 'I8:M8'] as $range) {
            $sheet->mergeCells($range);
        }
        $sheet->setCellValue('A6', 'ID');
        $sheet->setCellValueExplicit('C6', $employee->machine_id, DataType::TYPE_STRING);
        $sheet->setCellValue('G6', 'Instansi');
        $sheet->setCellValue('I6', 'Fakultas Teknologi Manufaktur Unjani');
        $sheet->setCellValue('A7', 'Nama Staff');
        $sheet->setCellValue('C7', $employee->name);
        $sheet->setCellValue('G7', 'Jabatan');
        $sheet->setCellValue('I7', $employee->position);
        $sheet->setCellValue('A8', 'NIP/NID');
        $sheet->setCellValueExplicit('C8', $employee->nid_nip, DataType::TYPE_STRING);
        $sheet->setCellValue('G8', 'Tanggal Laporan');
        $sheet->setCellValue('I8', ExcelDate::PHPToExcel(Carbon::now('Asia/Jakarta')));
        $sheet->getStyle('I8')->getNumberFormat()->setFormatCode('dd/mm/yyyy');

        $headings = [
            'No', 'Tanggal', 'Hari', 'Tipe', 'Status', 'Verifikasi', 'Jam Masuk', 'Jam Keluar',
            'Total Jam Kerja', 'Lembur', 'Datang Telat (Menit)', 'Pulang Cepat (Menit)', 'Catatan',
        ];
        foreach ($headings as $index => $heading) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).'10', $heading);
        }

        $row = 11;
        $sequence = 1;
        for ($date = $periodStart->copy(); $date->lessThanOrEqualTo($reportEnd); $date->addDay()) {
            $dateString = $date->toDateString();
            $isEmployed = $employee->employment_start_date->startOfDay()->lessThanOrEqualTo($date);
            $isDayOff = $isEmployed && $this->isDayOff($date, $holidayMap);
            /** @var AttendanceRecord|null $record */
            $record = $isEmployed ? $recordMap->get($this->recordKey($employee->id, $dateString)) : null;
            $rawStatus = $record?->status ?? ($date->isBefore($today) ? 'absent' : null);
            $effectiveStatus = $rawStatus ? $this->statusResolver->resolve($rawStatus, $employee, $date) : null;
            $metrics = $record
                ? $this->evaluateRecordTimeMetrics($record, $holidayMap, $workSchedules, $overtimeRules)
                : ['total_work' => null, 'late' => null, 'early' => null, 'overtime' => null];

            if (! $isEmployed) {
                $type = '-';
                $status = '-';
                $note = 'Belum mulai bekerja.';
            } elseif ($isDayOff) {
                $type = 'Libur';
                $status = 'Libur';
                $note = $holidayMap->get($dateString)?->name ?? 'Akhir pekan';
            } else {
                $type = 'Kerja';
                $status = $effectiveStatus ? ($statusLabels[$effectiveStatus] ?? $effectiveStatus) : '-';
                $note = $record?->note;
                if (in_array($effectiveStatus, ['mpp', 'stb'], true) && ! $note) {
                    $note = sprintf(
                        'Periode %s: %s s.d. %s.',
                        strtoupper($effectiveStatus),
                        $employee->attendance_type_start_date?->format('d/m/Y'),
                        $employee->attendance_type_end_date?->format('d/m/Y')
                    );
                }
                if (! in_array($effectiveStatus, ['mpp', 'stb'], true)) {
                    $summary['workdays']++;
                }

                if (in_array($effectiveStatus, ['present', 'duty'], true)) {
                    $summary['present_days']++;
                }
                if ($effectiveStatus === 'absent') {
                    $summary['absent_days']++;
                }
                if ($effectiveStatus === 'permission') {
                    $summary['permission_days']++;
                }
                if ($effectiveStatus === 'mpp') {
                    $summary['mpp_days']++;
                }
                if ($effectiveStatus === 'stb') {
                    $summary['stb_days']++;
                }
            }

            if ($record?->incomplete) {
                $summary['incomplete_days']++;
            }
            if (($metrics['late'] ?? 0) > 0) {
                $summary['late_days']++;
            }
            $summary['work_minutes'] += $metrics['total_work'] ?? 0;
            $summary['late_minutes'] += $metrics['late'] ?? 0;
            $summary['early_minutes'] += $metrics['early'] ?? 0;

            $sheet->setCellValue("A{$row}", $sequence);
            $sheet->setCellValue("B{$row}", ExcelDate::PHPToExcel($date));
            $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            $sheet->setCellValue("C{$row}", $dayNames[$date->dayOfWeek]);
            $sheet->setCellValue("D{$row}", $type);
            $sheet->setCellValue("E{$row}", $status);
            $sheet->setCellValue("F{$row}", $record ? $this->verificationLabel($record->source) : '-');
            $sheet->setCellValue("G{$row}", $record?->check_in ? substr($record->check_in, 0, 5) : '-');
            $sheet->setCellValue("H{$row}", $record?->check_out ? substr($record->check_out, 0, 5) : '-');
            $sheet->setCellValue("I{$row}", $this->formatDuration($metrics['total_work']));
            $sheet->setCellValue("J{$row}", $this->formatDuration($metrics['overtime']));
            $sheet->setCellValue("K{$row}", $metrics['late'] ?? '-');
            $sheet->setCellValue("L{$row}", $metrics['early'] ?? '-');
            $sheet->setCellValue("M{$row}", $note ?: '');

            $dataStyle = $sheet->getStyle("A{$row}:M{$row}");
            $dataStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('000000');
            $dataStyle->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("A{$row}:L{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("M{$row}")->getAlignment()->setWrapText(true);
            if ($isDayOff) {
                $dataStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E7E7E7');
            }

            $row++;
            $sequence++;
        }

        $summaryTitleRow = $row + 1;
        $sheet->mergeCells("A{$summaryTitleRow}:M{$summaryTitleRow}");
        $sheet->setCellValue("A{$summaryTitleRow}", 'EVALUASI KEHADIRAN');
        $sheet->getStyle("A{$summaryTitleRow}:M{$summaryTitleRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$summaryTitleRow}:M{$summaryTitleRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $summaryRows = [
            ['Jumlah Datang Terlambat', $summary['late_days'], 'Total Hari Periode Kerja', $summary['workdays'], '0'],
            ['Jumlah Lupa Absen', $summary['incomplete_days'], 'Total Hadir Kerja', $summary['present_days'], '0'],
            ['Jumlah Tidak Masuk', $summary['absent_days'], 'Jumlah Lama Kerja (jam)', $summary['work_minutes'] / 60, '0.00'],
            ['Jumlah Izin', $summary['permission_days'], 'Jumlah Lama Terlambat (jam)', $summary['late_minutes'] / 60, '0.00'],
            ['Jumlah MPP', $summary['mpp_days'], '', '', 'General'],
            ['Jumlah STB', $summary['stb_days'], '', '', 'General'],
            [
                'Persentase Kehadiran',
                $summary['workdays'] > 0 ? $summary['present_days'] / $summary['workdays'] : 0,
                'Jumlah Lama Pulang Cepat (jam)',
                $summary['early_minutes'] / 60,
                '0.00',
            ],
        ];

        foreach ($summaryRows as $index => [$leftLabel, $leftValue, $rightLabel, $rightValue, $rightFormat]) {
            $summaryRow = $summaryTitleRow + $index + 1;
            foreach (["A{$summaryRow}:B{$summaryRow}", "C{$summaryRow}:F{$summaryRow}", "G{$summaryRow}:H{$summaryRow}", "I{$summaryRow}:M{$summaryRow}"] as $range) {
                $sheet->mergeCells($range);
            }
            $sheet->setCellValue("A{$summaryRow}", $leftLabel);
            $sheet->setCellValue("C{$summaryRow}", $leftValue);
            $sheet->setCellValue("G{$summaryRow}", $rightLabel);
            $sheet->setCellValue("I{$summaryRow}", $rightValue);
            $sheet->getStyle("I{$summaryRow}")->getNumberFormat()->setFormatCode($rightFormat);
            if ($leftLabel === 'Persentase Kehadiran') {
                $sheet->getStyle("C{$summaryRow}")->getNumberFormat()->setFormatCode('0%');
            } else {
                $sheet->getStyle("C{$summaryRow}")->getNumberFormat()->setFormatCode('0');
            }
            $sheet->getStyle("A{$summaryRow}:M{$summaryRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('000000');
            foreach (["A{$summaryRow}:B{$summaryRow}", "G{$summaryRow}:H{$summaryRow}"] as $labelRange) {
                $sheet->getStyle($labelRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F0F3F1');
                $sheet->getStyle($labelRange)->getFont()->setBold(true);
            }
        }

        $sheet->getStyle('A1:M4')->getFont()->setName('Calibri');
        $sheet->getStyle('A1:M4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A1')->getFont()->setSize(13)->setBold(true);
        $sheet->getStyle('A2:A3')->getFont()->setSize(12)->setBold(true);
        $sheet->getStyle('A4')->getFont()->setSize(11);
        foreach (['A6:B8', 'G6:H8'] as $labelRange) {
            $sheet->getStyle($labelRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F0F3F1');
            $sheet->getStyle($labelRange)->getFont()->setBold(true);
        }
        $sheet->getStyle('A6:M8')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('000000');
        $headerStyle = $sheet->getStyle('A10:M10');
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006837');
        $headerStyle->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $headerStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('000000');
        $sheet->getStyle('A6:M'.($summaryTitleRow + count($summaryRows)))->getFont()->setName('Calibri')->setSize(10);

        foreach ([5, 12, 9, 11, 14, 14, 10, 10, 14, 10, 17, 17, 26] as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }
        $sheet->getRowDimension(10)->setRowHeight(34);
        for ($dataRow = 11; $dataRow < $row; $dataRow++) {
            $sheet->getRowDimension($dataRow)->setRowHeight(21);
        }
    }

    private function verificationLabel(string $source): string
    {
        return match ($source) {
            'fingerprint' => 'Mesin',
            'manual' => 'Manual',
            'mixed' => 'Manual + Mesin',
            'system' => 'Otomatis',
            default => '-',
        };
    }

    private function formatDuration(?int $minutes): int|string
    {
        if ($minutes === null) {
            return '-';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $hours > 0 ? "{$hours}j {$remainingMinutes}m" : "{$remainingMinutes}m";
    }

    private function styleSectionBand(Worksheet $sheet, int $row, string $lastColumnLetter): void
    {
        $style = $sheet->getStyle("A{$row}:{$lastColumnLetter}{$row}");
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('8A1F2D');
        $style->getFont()->setName('Times New Roman')->setSize(10)->setBold(true)->getColor()->setRGB('FFFFFF');
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $style->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('5C1420');
    }

    /**
     * @param  Collection<string, Holiday>  $holidayMap
     * @param  Collection<int, WorkSchedule>  $workSchedules
     * @param  Collection<int, OvertimeRule>  $overtimeRules
     * @return array{total_work: ?int, late: ?int, early: ?int, overtime: ?int}
     */
    private function evaluateRecordTimeMetrics(
        AttendanceRecord $record,
        Collection $holidayMap,
        Collection $workSchedules,
        Collection $overtimeRules,
    ): array {
        $emptyResult = ['total_work' => null, 'late' => null, 'early' => null, 'overtime' => null];
        if (! $record->check_in || ! $record->check_out) {
            return $emptyResult;
        }

        $date = $record->attendance_date->copy()->startOfDay();
        $schedule = $workSchedules->first(fn (WorkSchedule $item) => $item->effective_from->lessThanOrEqualTo($date)
            && (! $item->effective_until || $item->effective_until->greaterThanOrEqualTo($date)));
        if (! $schedule) {
            return $emptyResult;
        }

        $dateString = $date->toDateString();
        $checkIn = Carbon::parse("{$dateString} {$record->check_in}", 'Asia/Jakarta');
        $checkOut = Carbon::parse("{$dateString} {$record->check_out}", 'Asia/Jakarta');
        $workStart = Carbon::parse("{$dateString} {$schedule->work_start}", 'Asia/Jakarta');
        $workEnd = Carbon::parse("{$dateString} {$schedule->work_end}", 'Asia/Jakarta');
        $breakStart = Carbon::parse("{$dateString} {$schedule->break_start}", 'Asia/Jakarta');
        $breakEnd = Carbon::parse("{$dateString} {$schedule->break_end}", 'Asia/Jakarta');
        $workedMinutes = max(0, $checkIn->diffInMinutes($checkOut, false));
        $overlapStart = $checkIn->greaterThan($breakStart) ? $checkIn : $breakStart;
        $overlapEnd = $checkOut->lessThan($breakEnd) ? $checkOut : $breakEnd;
        $breakMinutes = $overlapEnd->greaterThan($overlapStart)
            ? $overlapStart->diffInMinutes($overlapEnd)
            : 0;

        $result = [
            'total_work' => (int) round(max(0, $workedMinutes - $breakMinutes)),
            'late' => (int) round(max(
                0,
                $workStart->copy()->addMinutes($schedule->late_tolerance_minutes)->diffInMinutes($checkIn, false)
            )),
            'early' => (int) round(max(
                0,
                $checkOut->diffInMinutes($workEnd->copy()->subMinutes($schedule->early_leave_tolerance_minutes), false)
            )),
            'overtime' => 0,
        ];

        if ($date->isWeekend() || $holidayMap->has($dateString)) {
            return $result;
        }

        $rule = $overtimeRules->first(fn (OvertimeRule $item) => $item->effective_from->lessThanOrEqualTo($date)
            && (! $item->effective_until || $item->effective_until->greaterThanOrEqualTo($date)));
        if (! $rule) {
            return $result;
        }

        $startsAt = $workEnd->copy()->addMinutes($rule->starts_after_minutes);
        $result['overtime'] = $checkOut->greaterThan($startsAt)
            ? (int) round(min($startsAt->diffInMinutes($checkOut), $rule->max_minutes))
            : 0;

        return $result;
    }

    /**
     * @param  Collection<int, AttendanceRecord>  $records
     * @param  Collection<string, Holiday>  $holidayMap
     * @param  Collection<int, WorkSchedule>  $workSchedules
     * @param  Collection<int, OvertimeRule>  $overtimeRules
     * @return array<int, array{late: int, early: int, overtime: int}>
     */
    private function calculateTimeMetrics(
        Collection $records,
        Collection $holidayMap,
        Collection $workSchedules,
        Collection $overtimeRules,
    ): array {
        $totals = [];

        foreach ($records as $record) {
            $totals[$record->employee_id] ??= ['late' => 0, 'early' => 0, 'overtime' => 0];
            $metrics = $this->evaluateRecordTimeMetrics($record, $holidayMap, $workSchedules, $overtimeRules);
            $totals[$record->employee_id]['late'] += $metrics['late'] ?? 0;
            $totals[$record->employee_id]['early'] += $metrics['early'] ?? 0;
            $totals[$record->employee_id]['overtime'] += $metrics['overtime'] ?? 0;
        }

        return $totals;
    }

    /** @param Collection<string, Holiday> $holidayMap */
    private function isDayOff(Carbon $date, Collection $holidayMap): bool
    {
        return $date->isWeekend() || $holidayMap->has($date->toDateString());
    }

    private function recordKey(int $employeeId, string $date): string
    {
        return "{$employeeId}|{$date}";
    }

    private function monthName(int $month): string
    {
        return self::MONTH_NAMES[$month];
    }

    private function safeSheetTitle(string $title): string
    {
        return mb_substr(str_replace(['\\', '/', '*', '?', ':', '[', ']'], '-', $title), 0, 31);
    }

    private function dosenSheetTitle(string $categoryName): string
    {
        return match ($categoryName) {
            'Dosen MMT' => 'Absensi Dosen Prodi MMT',
            'Dosen Teknik Metalurgi' => 'Absensi Dosen Prodi T.Metal',
            'Dosen Teknik Mesin' => 'Absensi Dosen Prodi T.Mesin',
            'Dosen Teknik Industri' => 'Absensi Dosen Prodi T. Industri',
            default => $this->safeSheetTitle("Absensi {$categoryName}"),
        };
    }
}
