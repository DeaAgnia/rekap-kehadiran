"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import { usePathname, useRouter } from "next/navigation"
import { ActivityLogView } from "@/components/activity-log-view"
import { AttendanceManagement } from "@/components/attendance-management"
import { EmployeeManagement } from "@/components/employee-management"
import { FingerprintImport } from "@/components/fingerprint-import"
import { MasterDataManagement } from "@/components/master-data-management"
import {
  BadgeCheck,
  CalendarCheck2,
  CalendarPlus,
  ChartNoAxesCombined,
  CircleAlert,
  Database,
  Download,
  FileSpreadsheet,
  Fingerprint,
  LayoutDashboard,
  LogOut,
  MoveHorizontal,
  ScanLine,
  ScrollText,
  Search,
  Settings2,
  Upload,
  UserPlus,
  Users,
} from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { cn } from "@/lib/utils"
import { apiRequest } from "@/lib/api"
import type { ActivityLog, ImportBatch, Notify } from "@/lib/admin-types"
import { TablePagination } from "@/components/table-pagination"

type AppView = "dashboard" | "employees" | "attendance" | "import" | "master" | "logs"
type AttendanceCode = "1" | "C" | "T" | "S" | "i" | "TK" | "L"

type Employee = {
  nidNip: string
  name: string
  categories: string[]
  marks: Partial<Record<number, AttendanceCode>>
}

type AttendanceSelection = {
  employee: Employee
  day: number
  code: AttendanceCode
  checkIn: string
  checkOut: string
  evaluation: string
}

const MONTHS = [
  "Januari",
  "Februari",
  "Maret",
  "April",
  "Mei",
  "Juni",
  "Juli",
  "Agustus",
  "September",
  "Oktober",
  "November",
  "Desember",
]

const CATEGORIES = [
  "Semua kategori",
  "Tendik Dekanat",
  "Tendik Teknik Mesin",
  "Tendik Teknik Industri",
  "Tendik Teknik Metalurgi",
  "Tendik MMT",
  "Dosen Teknik Mesin",
  "Dosen Teknik Industri",
  "Dosen Teknik Metalurgi",
  "Dosen MMT",
  "Pimpinan",
]

const EMPLOYEES: Employee[] = [
  {
    nidNip: "198704112",
    name: "Ayu Lestari",
    categories: ["Tendik Dekanat"],
    marks: { 4: "TK", 11: "i", 17: "T" },
  },
  {
    nidNip: "199102208",
    name: "Budi Santoso",
    categories: ["Dosen Teknik Mesin", "Pimpinan"],
    marks: { 3: "S", 15: "C", 24: "TK" },
  },
  {
    nidNip: "198909305",
    name: "Citra Maharani",
    categories: ["Dosen Teknik Industri"],
    marks: { 8: "T", 18: "i" },
  },
  {
    nidNip: "199306417",
    name: "Dimas Pratama",
    categories: ["Tendik Teknik Mesin"],
    marks: { 2: "TK", 9: "TK", 21: "S" },
  },
  {
    nidNip: "198511520",
    name: "Erna Wulandari",
    categories: ["Dosen MMT", "Pimpinan"],
    marks: { 7: "T", 22: "C" },
  },
  {
    nidNip: "199408623",
    name: "Fajar Nugraha",
    categories: ["Tendik Teknik Industri"],
    marks: { 10: "i", 29: "TK" },
  },
  {
    nidNip: "199011734",
    name: "Gita Permatasari",
    categories: ["Dosen Teknik Metalurgi"],
    marks: { 6: "C", 20: "T" },
  },
  {
    nidNip: "198802841",
    name: "Hendra Kurniawan",
    categories: ["Tendik Teknik Metalurgi"],
    marks: { 5: "S", 16: "TK" },
  },
]

const STATUS_META: Record<
  AttendanceCode,
  { label: string; className: string }
> = {
  "1": {
    label: "Hadir",
    className: "bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300",
  },
  C: {
    label: "Cuti",
    className: "bg-violet-50 text-violet-700 dark:bg-violet-950/60 dark:text-violet-300",
  },
  T: {
    label: "Tugas",
    className: "bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300",
  },
  S: {
    label: "Sakit",
    className: "bg-purple-50 text-purple-700 dark:bg-purple-950/60 dark:text-purple-300",
  },
  i: {
    label: "Izin",
    className: "bg-orange-50 text-orange-700 dark:bg-orange-950/60 dark:text-orange-300",
  },
  TK: {
    label: "Tanpa keterangan",
    className: "bg-red-50 text-red-700 dark:bg-red-950/60 dark:text-red-300",
  },
  L: {
    label: "Libur",
    className: "bg-transparent text-muted-foreground",
  },
}

function getDaysInMonth(month: number, year: number) {
  return new Date(year, month, 0).getDate()
}

function getDayName(day: number, month: number, year: number) {
  const dayNames = ["Min", "Sen", "Sel", "Rab", "Kam", "Jum", "Sab"]
  return dayNames[new Date(Date.UTC(year, month - 1, day)).getUTCDay()]
}

function isWeekend(day: number, month: number, year: number) {
  const dayIndex = new Date(Date.UTC(year, month - 1, day)).getUTCDay()
  return dayIndex === 0 || dayIndex === 6
}

function getAttendanceCode(
  employee: Employee,
  day: number,
  month: number,
  year: number
): AttendanceCode {
  if (isWeekend(day, month, year)) return "L"
  return employee.marks[day] ?? "1"
}

function getAttendanceDetail(
  employee: Employee,
  day: number,
  code: AttendanceCode
): Omit<AttendanceSelection, "employee" | "day" | "code"> {
  if (code === "L") {
    return { checkIn: "—", checkOut: "—", evaluation: "Akhir pekan" }
  }

  if (code !== "1") {
    return {
      checkIn: "—",
      checkOut: "—",
      evaluation: `Status manual: ${STATUS_META[code].label}`,
    }
  }

  const isLate = (day + Number(employee.nidNip.slice(-1))) % 7 === 0
  const leavesEarly = day % 9 === 0

  return {
    checkIn: isLate ? "08.14" : "07.52",
    checkOut: leavesEarly ? "16.42" : "17.08",
    evaluation: isLate
      ? "Terlambat 14 menit"
      : leavesEarly
        ? "Pulang cepat 18 menit"
        : "Sesuai jadwal",
  }
}

function AppSidebar({
  activeView,
  onViewChange,
  onLogout,
}: {
  activeView: AppView
  onViewChange: (view: AppView) => void
  onLogout: () => void
}) {
  const menu = [
    { label: "Dashboard", icon: LayoutDashboard, view: "dashboard" as const },
    { label: "Pegawai", icon: Users, view: "employees" as const },
    { label: "Rekap kehadiran", icon: CalendarCheck2, view: "attendance" as const },
    { label: "Impor fingerprint", icon: Fingerprint, view: "import" as const },
    { label: "Data master", icon: Database, view: "master" as const },
    { label: "Log aktivitas", icon: ScrollText, view: "logs" as const },
  ]

  return (
    <aside className="sticky top-0 flex h-screen w-[4.5rem] shrink-0 flex-col bg-primary px-2 py-5 text-primary-foreground md:w-60 md:px-4">
      <div className="flex items-center justify-center gap-3 px-1 md:justify-start md:px-2">
        <div className="grid size-10 shrink-0 place-items-center rounded-xl border border-white/20 bg-white/10">
          <ScanLine className="size-5" aria-hidden="true" />
        </div>
        <div className="hidden md:block">
          <p className="text-sm font-medium">Kehadiran FTM</p>
          <p className="mt-0.5 text-[11px] text-white/65">Administrasi SDM</p>
        </div>
      </div>

      <nav className="mt-7 grid gap-1.5" aria-label="Navigasi utama">
        {menu.map((item) => {
          const Icon = item.icon
          const isActive = item.view === activeView

          return (
            <Button
              key={item.label}
              type="button"
              variant="ghost"
              aria-current={isActive ? "page" : undefined}
              onClick={() => onViewChange(item.view)}
              className={cn(
                "h-10 w-full justify-center rounded-lg px-0 text-white/70 hover:bg-white/10 hover:text-white md:justify-start md:px-3",
                isActive && "bg-white/15 text-white hover:bg-white/15"
              )}
            >
              <Icon className="size-4" aria-hidden="true" />
              <span className="hidden md:inline">{item.label}</span>
            </Button>
          )
        })}
      </nav>

      <div className="mt-auto flex items-center justify-center gap-2 border-t border-white/15 pt-4 md:px-2">
        <div className="grid size-8 shrink-0 place-items-center rounded-full bg-white/90 text-[11px] font-medium text-primary">
          AS
        </div>
        <div className="hidden min-w-0 flex-1 md:block">
          <p className="text-xs font-medium">Admin SDM</p>
          <p className="mt-0.5 text-[10px] text-white/60">Administrator</p>
        </div>
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          aria-label="Keluar dari sistem"
          onClick={onLogout}
          className="rounded-lg text-white/70 hover:bg-white/10 hover:text-white"
        >
          <LogOut className="size-4" aria-hidden="true" />
        </Button>
      </div>
    </aside>
  )
}

function FilterSelect({
  label,
  value,
  options,
  onValueChange,
  className,
}: {
  label: string
  value: string
  options: Array<{ label: string; value: string }>
  onValueChange: (value: string) => void
  className?: string
}) {
  return (
    <label className={cn("grid min-w-0 gap-1.5", className)}>
      <span className="text-[11px] text-muted-foreground">{label}</span>
      <Select value={value} onValueChange={(nextValue) => onValueChange(String(nextValue))}>
        <SelectTrigger className="h-9 w-full rounded-lg bg-background px-3">
          <SelectValue>{options.find((option) => option.value === value)?.label}</SelectValue>
        </SelectTrigger>
        <SelectContent align="start" className="rounded-lg">
          {options.map((option) => (
            <SelectItem key={option.value} value={option.value} className="rounded-md">
              {option.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </label>
  )
}

type DashboardData = {
  active_employees: number
  category_count: number
  present_today: number
  present_today_percentage: number
  needs_review: number
  attendance_rate: number
  expected_workdays: number
  present_workdays: number
  issues: { incomplete: number; mixed: number; absent: number }
  trend: Array<{ date: string; label: string; value: number }>
  latest_import: ImportBatch | null
  recent_activity: ActivityLog[]
  categories: string[]
  updated_at: string
}

function DashboardView({
  month,
  year,
  category,
  onMonthChange,
  onYearChange,
  onCategoryChange,
  onOpenEmployees,
  onOpenAttendance,
  onOpenImport,
  onOpenMaster,
  onOpenLogs,
  notify,
}: {
  month: number
  year: number
  category: string
  onMonthChange: (month: number) => void
  onYearChange: (year: number) => void
  onCategoryChange: (category: string) => void
  onOpenEmployees: () => void
  onOpenAttendance: () => void
  onOpenImport: () => void
  onOpenMaster: () => void
  onOpenLogs: () => void
  notify: Notify
}) {
  const [data, setData] = useState<DashboardData | null>(null)
  const [loading, setLoading] = useState(true)
  const [selectedTrendDate, setSelectedTrendDate] = useState<string | null>(null)
  const [activityPage, setActivityPage] = useState(1)
  const [activityPageSize, setActivityPageSize] = useState(5)

  useEffect(() => {
    let cancelled = false
    const timer = window.setTimeout(async () => {
      setLoading(true)
      try {
        const params = new URLSearchParams({ month: String(month), year: String(year) })
        if (category !== "Semua kategori") params.set("category", category)
        const response = await apiRequest<{ data: DashboardData }>(`dashboard?${params.toString()}`)
        if (!cancelled) {
          setData(response.data)
          setActivityPage(1)
          setSelectedTrendDate(response.data.trend.at(-1)?.date ?? null)
        }
      } catch (error) {
        if (!cancelled) notify(error instanceof Error ? error.message : "Dashboard gagal dimuat.", "error")
      } finally {
        if (!cancelled) setLoading(false)
      }
    }, 0)

    return () => {
      cancelled = true
      window.clearTimeout(timer)
    }
  }, [category, month, notify, year])

  const trend = data?.trend ?? []
  const selectedPoint = trend.find((item) => item.date === selectedTrendDate) ?? trend.at(-1) ?? null
  const maxTrend = Math.max(1, ...trend.map((item) => item.value))
  const minTrend = trend.length > 0 ? Math.min(...trend.map((item) => item.value)) : 0
  const activities = data?.recent_activity ?? []
  const activityCurrentPage = Math.min(activityPage, Math.max(1, Math.ceil(activities.length / activityPageSize)))
  const paginatedActivities = activities.slice((activityCurrentPage - 1) * activityPageSize, activityCurrentPage * activityPageSize)
  const categoryOptions = ["Semua kategori", ...(data?.categories ?? (category === "Semua kategori" ? [] : [category]))]
  const metrics = [
    { label: "Pegawai aktif", value: data?.active_employees ?? 0, context: `${data?.category_count ?? 0} kategori aktif`, icon: Users },
    { label: "Hadir hari ini", value: data?.present_today ?? 0, context: `${data?.present_today_percentage ?? 0}% dari pegawai aktif`, icon: BadgeCheck },
    { label: "Perlu ditinjau", value: data?.needs_review ?? 0, context: "Scan tunggal, data campuran, dan TK", icon: CircleAlert },
    { label: "Kehadiran periode", value: `${data?.attendance_rate ?? 0}%`, context: `${data?.present_workdays ?? 0} dari ${data?.expected_workdays ?? 0} hari-pegawai`, icon: ChartNoAxesCombined },
  ]
  const issues = [
    { title: `${data?.issues.incomplete ?? 0} scan tunggal`, description: "Jam pulang belum tersedia", icon: ScanLine },
    { title: `${data?.issues.mixed ?? 0} data campuran`, description: "Status manual dan fingerprint tersedia", icon: CircleAlert },
    { title: `${data?.issues.absent ?? 0} tanpa keterangan`, description: "Hari kerja telah berakhir", icon: CalendarCheck2 },
  ]
  const updatedAt = data?.updated_at
    ? new Date(data.updated_at).toLocaleString("id-ID", { timeZone: "Asia/Jakarta", dateStyle: "medium", timeStyle: "short" })
    : "—"

  return (
    <div className="mx-auto w-full max-w-[1500px] p-4 sm:p-6 lg:p-7">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div><h1 className="text-2xl font-medium tracking-tight">Dashboard</h1><p className="mt-1 text-xs text-muted-foreground">Ringkasan operasional kehadiran Fakultas Teknologi Manufaktur.</p></div>
        <div className="flex flex-wrap gap-2">
          <Button type="button" variant="outline" className="h-9 rounded-lg px-3" onClick={onOpenEmployees}><UserPlus data-icon="inline-start" />Tambah pegawai</Button>
          <Button type="button" className="h-9 rounded-lg px-3" onClick={onOpenImport}><Upload data-icon="inline-start" />Impor fingerprint</Button>
        </div>
      </header>

      <div className="mt-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <FilterSelect label="Bulan" value={String(month)} onValueChange={(value) => onMonthChange(Number(value))} options={MONTHS.map((label, index) => ({ label, value: String(index + 1) }))} />
          <FilterSelect label="Tahun" value={String(year)} onValueChange={(value) => onYearChange(Number(value))} options={[2024, 2025, 2026, 2027].map((value) => ({ label: String(value), value: String(value) }))} />
          <FilterSelect label="Kategori" value={category} onValueChange={onCategoryChange} options={categoryOptions.map((value) => ({ label: value, value }))} />
        </div>
        <p className="text-[11px] text-muted-foreground">{loading ? "Memperbarui data..." : `Data aktual · diperbarui ${updatedAt} WIB`}</p>
      </div>

      <section className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Ringkasan kehadiran">
        {metrics.map((metric, index) => {
          const Icon = metric.icon
          return <Card key={metric.label} className={cn("gap-0 rounded-xl border border-border/80 py-4 shadow-sm ring-0", index === 0 && "border-primary/45 shadow-[inset_3px_0_0_var(--primary)]")}><CardContent className="px-4"><div className="flex items-center justify-between gap-3"><span className="text-[11px] text-muted-foreground">{metric.label}</span><span className="grid size-8 place-items-center rounded-lg bg-primary/8 text-primary"><Icon className="size-4" aria-hidden="true" /></span></div><p className="mt-2 text-2xl font-medium tabular-nums">{loading ? "…" : metric.value}</p><p className="mt-2 text-[11px] text-muted-foreground">{metric.context}</p></CardContent></Card>
        })}
      </section>

      <div className="mt-3 grid gap-3 xl:grid-cols-[minmax(0,1.7fr)_minmax(270px,.8fr)]">
        <Card className="rounded-xl border border-border/80 py-0 shadow-sm ring-0">
          <CardHeader className="border-b px-4 py-4"><CardTitle>Kehadiran 7 hari kerja</CardTitle><CardDescription>Jumlah pegawai dengan status Hadir atau Tugas</CardDescription><CardAction><Button type="button" variant="outline" className="rounded-lg" onClick={onOpenAttendance}>Lihat rekap</Button></CardAction></CardHeader>
          <CardContent className="px-4 py-4">
            {trend.length > 0 ? <><div className="grid h-44 items-end gap-2 border-b" style={{ gridTemplateColumns: `repeat(${trend.length}, minmax(0, 1fr))` }}>{trend.map((item) => <button type="button" key={item.date} aria-label={`${item.label}: ${item.value} pegawai hadir`} onClick={() => setSelectedTrendDate(item.date)} className="group grid h-full grid-rows-[1fr_auto] items-end gap-2 text-[10px] text-muted-foreground"><span className="flex h-full items-end justify-center rounded-t-md bg-muted/55"><span className={cn("w-3/5 rounded-t-md bg-primary/55 transition-colors group-hover:bg-primary/75", selectedPoint?.date === item.date && "bg-primary")} style={{ height: `${Math.max(4, (item.value / maxTrend) * 100)}%` }} /></span><span>{item.label}</span></button>)}</div><div className="mt-3 flex flex-wrap items-center justify-between gap-2 text-[11px] text-muted-foreground"><span><strong className="font-medium text-foreground">{selectedPoint?.label}</strong> · {selectedPoint?.value ?? 0} pegawai hadir</span><span>Rentang {minTrend}–{maxTrend} pegawai</span></div></> : <div className="grid h-44 place-items-center text-xs text-muted-foreground">Belum ada data hari kerja pada periode ini.</div>}
          </CardContent>
        </Card>

        <Card className="rounded-xl border border-border/80 py-0 shadow-sm ring-0">
          <CardHeader className="border-b px-4 py-4"><CardTitle>Perlu ditinjau</CardTitle><CardDescription>Data aktual yang membutuhkan tindakan admin</CardDescription></CardHeader>
          <CardContent className="grid gap-0 px-4 py-1">{issues.map(({ title, description, icon: Icon }) => <div key={description} className="grid grid-cols-[2.25rem_minmax(0,1fr)_auto] items-center gap-3 border-b py-3 last:border-b-0"><span className="grid size-9 place-items-center rounded-lg bg-primary/8 text-primary"><Icon className="size-4" aria-hidden="true" /></span><div className="min-w-0"><p className="text-xs font-medium">{title}</p><p className="mt-1 truncate text-[10px] text-muted-foreground">{description}</p></div><Button type="button" variant="ghost" size="sm" className="rounded-md text-primary" onClick={onOpenAttendance}>Tinjau</Button></div>)}</CardContent>
        </Card>
      </div>

      <div className="mt-3 grid gap-3 xl:grid-cols-[minmax(0,1.25fr)_minmax(260px,.75fr)]">
        <Card className="rounded-xl border border-border/80 py-0 shadow-sm ring-0">
          <CardHeader className="border-b px-4 py-4"><CardTitle>Aktivitas terbaru</CardTitle><CardDescription>Riwayat aktivitas aktual dari aplikasi</CardDescription><CardAction><Button type="button" variant="outline" className="rounded-lg" onClick={onOpenLogs}>Lihat semua log</Button></CardAction></CardHeader>
          <CardContent className="p-0">
            <Table><TableHeader><TableRow><TableHead>Aktivitas</TableHead><TableHead>Waktu</TableHead><TableHead>Status</TableHead></TableRow></TableHeader><TableBody>{loading ? <TableRow><TableCell colSpan={3} className="h-24 text-center text-muted-foreground">Memuat aktivitas...</TableCell></TableRow> : paginatedActivities.length === 0 ? <TableRow><TableCell colSpan={3} className="h-24 text-center text-muted-foreground">Belum ada aktivitas.</TableCell></TableRow> : paginatedActivities.map((item) => <TableRow key={item.id}><TableCell>{item.description}</TableCell><TableCell>{new Date(item.occurred_at).toLocaleString("id-ID", { timeZone: "Asia/Jakarta" })}</TableCell><TableCell><Badge variant={item.status === "success" ? "secondary" : "destructive"} className="rounded-full">{item.status === "success" ? "Berhasil" : "Error"}</Badge></TableCell></TableRow>)}</TableBody></Table>
            <TablePagination page={activityCurrentPage} pageSize={activityPageSize} total={activities.length} onPageChange={setActivityPage} onPageSizeChange={setActivityPageSize} />
          </CardContent>
        </Card>

        <Card className="rounded-xl border border-border/80 py-0 shadow-sm ring-0">
          <CardHeader className="border-b px-4 py-4"><CardTitle>Akses cepat</CardTitle><CardDescription>{data?.latest_import ? `${data.latest_import.imported_count} scan pada import terakhir` : "Belum ada riwayat import"}</CardDescription></CardHeader>
          <CardContent className="grid gap-2 px-4 py-4"><Button type="button" variant="outline" className="h-10 justify-start rounded-lg" onClick={onOpenAttendance}><CalendarPlus data-icon="inline-start" />Input absensi manual</Button><Button type="button" variant="outline" className="h-10 justify-start rounded-lg" onClick={onOpenAttendance}><FileSpreadsheet data-icon="inline-start" />Unduh laporan Excel</Button><Button type="button" variant="outline" className="h-10 justify-start rounded-lg" onClick={onOpenMaster}><Settings2 data-icon="inline-start" />Atur jam kerja</Button></CardContent>
        </Card>
      </div>
    </div>
  )
}

/** @deprecated Digantikan oleh AttendanceManagement yang terhubung ke Laravel API. */
export function AttendanceRecapView({
  month,
  year,
  category,
  onMonthChange,
  onYearChange,
  onCategoryChange,
  onNotice,
}: {
  month: number
  year: number
  category: string
  onMonthChange: (month: number) => void
  onYearChange: (year: number) => void
  onCategoryChange: (category: string) => void
  onNotice: (message: string) => void
}) {
  const [query, setQuery] = useState("")
  const [selectedAttendance, setSelectedAttendance] = useState<AttendanceSelection | null>(null)
  const daysInMonth = getDaysInMonth(month, year)
  const days = Array.from({ length: daysInMonth }, (_, index) => index + 1)

  const filteredEmployees = useMemo(() => {
    const normalizedQuery = query.trim().toLowerCase()

    return EMPLOYEES.filter((employee) => {
      const matchesCategory =
        category === "Semua kategori" || employee.categories.includes(category)
      const matchesSearch =
        !normalizedQuery ||
        employee.name.toLowerCase().includes(normalizedQuery) ||
        employee.nidNip.includes(normalizedQuery)

      return matchesCategory && matchesSearch
    })
  }, [category, query])

  const selectAttendance = (employee: Employee, day: number, code: AttendanceCode) => {
    setSelectedAttendance({ employee, day, code, ...getAttendanceDetail(employee, day, code) })
  }

  return (
    <div className="mx-auto w-full max-w-[1700px] p-4 sm:p-6 lg:p-7">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-2xl font-medium tracking-tight">Rekap kehadiran</h1>
          <p className="mt-1 text-xs text-muted-foreground">Lihat status harian dan unduh laporan bulanan pegawai.</p>
        </div>
        <Button type="button" variant="outline" className="h-9 rounded-lg px-3" onClick={() => onNotice("Menyiapkan pilihan ekspor laporan Excel.")}>
          <Download data-icon="inline-start" />Unduh Excel
        </Button>
      </header>

      <Card className="mt-5 rounded-xl border border-border/80 py-0 shadow-sm ring-0">
        <CardContent className="grid gap-3 px-4 py-4 sm:grid-cols-2 xl:grid-cols-[1fr_.75fr_1.4fr_1.4fr_auto] xl:items-end">
          <FilterSelect
            label="Bulan"
            value={String(month)}
            onValueChange={(value) => {
              onMonthChange(Number(value))
              setSelectedAttendance(null)
            }}
            options={MONTHS.map((label, index) => ({ label, value: String(index + 1) }))}
          />
          <FilterSelect
            label="Tahun"
            value={String(year)}
            onValueChange={(value) => {
              onYearChange(Number(value))
              setSelectedAttendance(null)
            }}
            options={[2024, 2025, 2026, 2027].map((value) => ({ label: String(value), value: String(value) }))}
          />
          <FilterSelect
            label="Kategori pegawai"
            value={category}
            onValueChange={(value) => {
              onCategoryChange(value)
              setSelectedAttendance(null)
            }}
            options={CATEGORIES.map((value) => ({ label: value, value }))}
          />
          <label className="grid min-w-0 gap-1.5">
            <span className="text-[11px] text-muted-foreground">Nama pegawai</span>
            <span className="relative block">
              <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
              <Input
                type="search"
                value={query}
                onChange={(event) => {
                  setQuery(event.target.value)
                  setSelectedAttendance(null)
                }}
                placeholder="Cari nama atau NIP/NID"
                className="h-9 rounded-lg bg-background pl-9"
              />
            </span>
          </label>
          <Button type="button" className="h-9 rounded-lg px-4" onClick={() => onNotice(`${filteredEmployees.length} pegawai ditampilkan.`)}>
            Tampilkan
          </Button>
        </CardContent>
      </Card>

      <Card className="mt-4 min-w-0 gap-0 rounded-xl border border-border/80 py-0 shadow-sm ring-0">
        <CardHeader className="border-b px-4 py-4">
          <CardTitle>{MONTHS[month - 1]} {year}</CardTitle>
          <CardDescription>{filteredEmployees.length} pegawai ditampilkan · {daysInMonth} hari kalender</CardDescription>
          <CardAction className="hidden items-center gap-2 text-[11px] text-muted-foreground sm:flex">
            <MoveHorizontal className="size-4" aria-hidden="true" />
            Geser di area tabel untuk melihat tanggal berikutnya
          </CardAction>
        </CardHeader>

        {/* Scroll hanya diterapkan pada container bawaan Table. Kolom identitas dibuat sticky dengan offset yang sama dengan lebarnya. */}
        <CardContent className="min-w-0 p-0">
          <div className="attendance-scroll min-w-0 [&_[data-slot=table-container]]:max-h-[480px] [&_[data-slot=table-container]]:overflow-auto [&_[data-slot=table-container]]:overscroll-contain">
            <Table
              className="table-fixed border-separate border-spacing-0"
              style={{ minWidth: `${336 + daysInMonth * 56}px` }}
            >
              <TableHeader>
                <TableRow className="hover:bg-transparent">
                  <TableHead className="sticky top-0 left-0 z-50 h-14 w-32 min-w-32 max-w-32 border-r bg-muted px-3">
                    NIP/NID
                  </TableHead>
                  <TableHead className="sticky top-0 left-32 z-50 h-14 w-52 min-w-52 max-w-52 border-r bg-muted px-3 shadow-[7px_0_12px_-10px_rgba(0,0,0,.85)]">
                    Nama
                  </TableHead>
                  {days.map((day) => {
                    const weekend = isWeekend(day, month, year)
                    return (
                      <TableHead
                        key={day}
                        className={cn(
                          "sticky top-0 z-20 h-14 w-14 min-w-14 max-w-14 border-r bg-muted px-1 text-center",
                          weekend && "bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                        )}
                      >
                        <span className="block text-xs font-medium tabular-nums">{day}</span>
                        <span className="mt-0.5 block text-[10px] font-normal text-muted-foreground">{getDayName(day, month, year)}</span>
                      </TableHead>
                    )
                  })}
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredEmployees.length > 0 ? (
                  filteredEmployees.map((employee) => (
                    <TableRow key={employee.nidNip} className="group">
                      <TableCell className="sticky left-0 z-30 w-32 min-w-32 max-w-32 border-r bg-card px-3 font-medium tabular-nums group-hover:bg-muted">
                        {employee.nidNip}
                      </TableCell>
                      <TableCell className="sticky left-32 z-30 w-52 min-w-52 max-w-52 overflow-hidden border-r bg-card px-3 shadow-[7px_0_12px_-10px_rgba(0,0,0,.85)] group-hover:bg-muted">
                        <span className="block truncate" title={employee.name}>{employee.name}</span>
                      </TableCell>
                      {days.map((day) => {
                        const code = getAttendanceCode(employee, day, month, year)
                        const isSelected =
                          selectedAttendance?.employee.nidNip === employee.nidNip &&
                          selectedAttendance.day === day

                        return (
                          <TableCell key={day} className="w-14 min-w-14 max-w-14 border-r px-1 text-center">
                            <button
                              type="button"
                              aria-label={`${employee.name}, ${day} ${MONTHS[month - 1]}: ${STATUS_META[code].label}`}
                              onClick={() => selectAttendance(employee, day, code)}
                              className={cn(
                                "mx-auto grid size-7 place-items-center rounded-md text-[10px] font-medium transition-shadow",
                                STATUS_META[code].className,
                                isSelected && "ring-2 ring-primary ring-offset-1 ring-offset-card"
                              )}
                            >
                              {code}
                            </button>
                          </TableCell>
                        )
                      })}
                    </TableRow>
                  ))
                ) : (
                  <TableRow>
                    <TableCell colSpan={daysInMonth + 2} className="h-28 text-center text-muted-foreground">
                      Tidak ada pegawai yang sesuai dengan filter.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </div>

          <div className="grid min-h-16 gap-3 border-t bg-muted/35 px-4 py-3 sm:grid-cols-[1.6fr_repeat(4,minmax(90px,.7fr))] sm:items-center">
            <div className="min-w-0">
              <span className="block text-[10px] text-muted-foreground">Pegawai dan tanggal</span>
              <strong className="mt-1 block truncate text-xs font-medium">
                {selectedAttendance
                  ? `${selectedAttendance.employee.name} · ${selectedAttendance.day} ${MONTHS[month - 1]} ${year}`
                  : "Pilih salah satu status pada tabel"}
              </strong>
            </div>
            {[
              ["Status", selectedAttendance ? STATUS_META[selectedAttendance.code].label : "—"],
              ["Jam masuk", selectedAttendance?.checkIn ?? "—"],
              ["Jam pulang", selectedAttendance?.checkOut ?? "—"],
              ["Evaluasi", selectedAttendance?.evaluation ?? "—"],
            ].map(([label, value]) => (
              <div key={label} className="min-w-0">
                <span className="block text-[10px] text-muted-foreground">{label}</span>
                <strong className="mt-1 block truncate text-xs font-medium">{value}</strong>
              </div>
            ))}
          </div>

          <div className="flex flex-wrap items-center gap-x-4 gap-y-2 border-t px-4 py-3 text-[10px] text-muted-foreground">
            {(Object.entries(STATUS_META) as Array<[AttendanceCode, (typeof STATUS_META)[AttendanceCode]]>).map(([code, meta]) => (
              <span key={code} className="inline-flex items-center gap-1.5"><strong className="font-medium text-foreground">{code}</strong>{meta.label}</span>
            ))}
          </div>
        </CardContent>
      </Card>
    </div>
  )
}

export function AttendanceApp() {
  const pathname = usePathname()
  const router = useRouter()
  const activeView: AppView = pathname.startsWith("/pegawai")
    ? "employees"
    : pathname.startsWith("/rekap-kehadiran")
      ? "attendance"
      : pathname.startsWith("/import-fingerprint")
        ? "import"
        : pathname.startsWith("/data-master")
          ? "master"
          : pathname.startsWith("/log-aktivitas")
            ? "logs"
            : "dashboard"
  const [month, setMonth] = useState(1)
  const [year, setYear] = useState(2000)
  const [periodReady, setPeriodReady] = useState(false)
  const [category, setCategory] = useState("Semua kategori")
  const [notices, setNotices] = useState<Array<{ id: number; message: string; type: "success" | "error" | "info" }>>([])

  const notify = useCallback<Notify>((message, type = "info") => {
    const id = Date.now() + Math.random()
    setNotices((current) => [...current, { id, message, type }])
    window.setTimeout(() => {
      setNotices((current) => current.filter((notice) => notice.id !== id))
    }, 4500)
  }, [])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      const now = new Date()
      setMonth(Number(new Intl.DateTimeFormat("en-US", { timeZone: "Asia/Jakarta", month: "numeric" }).format(now)))
      setYear(Number(new Intl.DateTimeFormat("en-US", { timeZone: "Asia/Jakarta", year: "numeric" }).format(now)))
      setPeriodReady(true)
    }, 0)

    return () => window.clearTimeout(timer)
  }, [])

  const navigateToView = (view: AppView) => {
    const paths: Record<AppView, string> = {
      dashboard: "/dashboard",
      employees: "/pegawai",
      attendance: "/rekap-kehadiran",
      import: "/import-fingerprint",
      master: "/data-master",
      logs: "/log-aktivitas",
    }
    router.push(paths[view])
  }

  const logout = async () => {
    notify("Mengakhiri sesi...", "info")

    try {
      const response = await fetch("/api/auth/logout", { method: "POST" })
      if (!response.ok) throw new Error("Logout gagal")
      router.replace("/login")
      router.refresh()
    } catch {
      notify("Logout gagal. Silakan coba kembali.", "error")
    }
  }

  return (
    <div className="flex min-h-screen min-w-0 bg-background text-foreground">
      <AppSidebar
        activeView={activeView}
        onViewChange={navigateToView}
        onLogout={logout}
      />
      <main className="min-w-0 flex-1 overflow-x-hidden">
        {!periodReady ? (
          <div className="grid min-h-screen place-items-center text-sm text-muted-foreground">Memuat periode aktif...</div>
        ) : activeView === "dashboard" ? (
          <DashboardView
            month={month}
            year={year}
            category={category}
            onMonthChange={setMonth}
            onYearChange={setYear}
            onCategoryChange={setCategory}
            onOpenEmployees={() => navigateToView("employees")}
            onOpenAttendance={() => navigateToView("attendance")}
            onOpenImport={() => navigateToView("import")}
            onOpenMaster={() => navigateToView("master")}
            onOpenLogs={() => navigateToView("logs")}
            notify={notify}
          />
        ) : activeView === "employees" ? (
          <EmployeeManagement notify={notify} />
        ) : activeView === "attendance" ? (
          <AttendanceManagement
            month={month}
            year={year}
            category={category}
            onMonthChange={setMonth}
            onYearChange={setYear}
            onCategoryChange={setCategory}
            notify={notify}
          />
        ) : activeView === "import" ? (
          <FingerprintImport notify={notify} />
        ) : activeView === "master" ? (
          <MasterDataManagement notify={notify} />
        ) : (
          <ActivityLogView notify={notify} />
        )}

        <div className="pointer-events-none fixed right-4 bottom-4 z-[70] grid w-[min(24rem,calc(100vw-2rem))] gap-2" aria-live="polite">
          {notices.map((notice) => (
            <div
              key={notice.id}
              className={cn(
                "rounded-lg border bg-card/95 px-3 py-2.5 text-xs shadow-lg backdrop-blur",
                notice.type === "success" && "border-emerald-200 text-emerald-800",
                notice.type === "error" && "border-destructive/30 text-destructive",
                notice.type === "info" && "text-muted-foreground"
              )}
            >
              {notice.message}
            </div>
          ))}
        </div>
      </main>
    </div>
  )
}
