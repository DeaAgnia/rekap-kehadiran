"use client"

import { type FormEvent, useCallback, useEffect, useMemo, useState } from "react"
import { Download, ExternalLink, MoveHorizontal, Paperclip, Pencil, Plus, Search, Trash2 } from "lucide-react"

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import { Button } from "@/components/ui/button"
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { Textarea } from "@/components/ui/textarea"
import { TablePagination } from "@/components/table-pagination"
import { ApiError, apiRequest, firstValidationError, type ApiValidationErrors } from "@/lib/api"
import type { AttendanceRecord, AttendanceStatus, Category, EffectiveAttendanceStatus, Employee, Holiday, Notify } from "@/lib/admin-types"
import { cn } from "@/lib/utils"

type AttendanceForm = {
  employee_id: string
  attendance_date: string
  status: AttendanceStatus
  check_in: string
  check_out: string
  note: string
}

const STATUS_META: Record<EffectiveAttendanceStatus, { code: string; label: string; className: string }> = {
  present: { code: "1", label: "Hadir", className: "bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300" },
  leave: { code: "C", label: "Cuti", className: "bg-violet-50 text-violet-700 dark:bg-violet-950/60 dark:text-violet-300" },
  duty: { code: "T", label: "Tugas", className: "bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300" },
  sick: { code: "S", label: "Sakit", className: "bg-purple-50 text-purple-700 dark:bg-purple-950/60 dark:text-purple-300" },
  permission: { code: "i", label: "Izin", className: "bg-orange-50 text-orange-700 dark:bg-orange-950/60 dark:text-orange-300" },
  absent: { code: "TK", label: "Tanpa keterangan", className: "bg-red-50 text-red-700 dark:bg-red-950/60 dark:text-red-300" },
  mpp: { code: "MPP", label: "Masa Persiapan Pensiun", className: "bg-teal-50 text-teal-700 dark:bg-teal-950/60 dark:text-teal-300" },
  stb: { code: "STB", label: "Surat Tugas Belajar", className: "bg-cyan-50 text-cyan-700 dark:bg-cyan-950/60 dark:text-cyan-300" },
}
const MANUAL_STATUS_VALUES: AttendanceStatus[] = ["present", "leave", "duty", "sick", "permission", "absent"]
const CATEGORY_BADGE_CLASSNAMES: Record<Category["group"], string> = {
  Tendik: "bg-sky-50 text-sky-700 dark:bg-sky-950/60 dark:text-sky-300",
  Dosen: "bg-violet-50 text-violet-700 dark:bg-violet-950/60 dark:text-violet-300",
  Pimpinan: "bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300",
}
const POSITION_BADGE_CLASSNAME = "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300"

const MONTHS = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"]
const DAY_NAMES = ["Min", "Sen", "Sel", "Rab", "Kam", "Jum", "Sab"]

function daysInMonth(month: number, year: number) {
  return new Date(year, month, 0).getDate()
}

function dateKey(year: number, month: number, day: number) {
  return `${year}-${String(month).padStart(2, "0")}-${String(day).padStart(2, "0")}`
}

function dayIndex(year: number, month: number, day: number) {
  return new Date(Date.UTC(year, month - 1, day)).getUTCDay()
}

function timeValue(value: string | null) {
  return value ? value.slice(0, 5) : ""
}

function formatMinutes(value: number | null) {
  if (value === null) return "—"
  const hours = Math.floor(value / 60)
  const minutes = value % 60
  return hours > 0 ? `${hours}j ${minutes}m` : `${minutes} menit`
}

function formatLateMinutes(value: number | null) {
  return value === null ? "—" : formatMinutes(Math.floor(value))
}

export function AttendanceManagement({
  month,
  year,
  category,
  onMonthChange,
  onYearChange,
  onCategoryChange,
  notify,
}: {
  month: number
  year: number
  category: string
  onMonthChange: (value: number) => void
  onYearChange: (value: number) => void
  onCategoryChange: (value: string) => void
  notify: Notify
}) {
  const [employees, setEmployees] = useState<Employee[]>([])
  const [categories, setCategories] = useState<Category[]>([])
  const [records, setRecords] = useState<AttendanceRecord[]>([])
  const [holidays, setHolidays] = useState<Holiday[]>([])
  const [query, setQuery] = useState("")
  const [loading, setLoading] = useState(true)
  const [selectedRecord, setSelectedRecord] = useState<AttendanceRecord | null>(null)
  const [selectedCell, setSelectedCell] = useState<{ employee: Employee; day: number } | null>(null)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editingRecord, setEditingRecord] = useState<AttendanceRecord | null>(null)
  const [deleteRecord, setDeleteRecord] = useState<AttendanceRecord | null>(null)
  const [saving, setSaving] = useState(false)
  const [exporting, setExporting] = useState(false)
  const [attachment, setAttachment] = useState<File | null>(null)
  const [attachmentInputKey, setAttachmentInputKey] = useState(0)
  const [errors, setErrors] = useState<ApiValidationErrors>({})
  const [form, setForm] = useState<AttendanceForm>({ employee_id: "", attendance_date: "", status: "present", check_in: "", check_out: "", note: "" })
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(10)
  const [referencesReady, setReferencesReady] = useState(false)

  const loadReferenceData = useCallback(async () => {
    try {
      const [employeeResponse, categoryResponse] = await Promise.all([
        apiRequest<{ data: Employee[] }>("employees"),
        apiRequest<{ data: Category[] }>("categories"),
      ])
      setEmployees(employeeResponse.data)
      setCategories(categoryResponse.data.filter((item) => item.active))
    } catch (error) {
      notify(error instanceof Error ? error.message : "Referensi kehadiran gagal dimuat.", "error")
    } finally {
      setReferencesReady(true)
    }
  }, [notify])

  const loadAttendance = useCallback(async (employeeIds: number[], signal?: AbortSignal) => {
    setSelectedRecord(null)
    setSelectedCell(null)

    if (employeeIds.length === 0) {
      setRecords([])
      setLoading(false)
      return
    }

    setLoading(true)
    try {
      const params = new URLSearchParams({ month: String(month), year: String(year) })
      employeeIds.forEach((employeeId) => params.append("employee_ids[]", String(employeeId)))
      const attendanceResponse = await apiRequest<{ data: AttendanceRecord[] }>(`attendances?${params.toString()}`, { signal })
      setRecords(attendanceResponse.data)
    } catch (error) {
      if (error instanceof Error && error.name === "AbortError") return
      notify(error instanceof Error ? error.message : "Rekap kehadiran gagal dimuat.", "error")
    } finally {
      if (!signal?.aborted) setLoading(false)
    }
  }, [month, notify, year])

  useEffect(() => {
    const timer = window.setTimeout(() => void loadReferenceData(), 0)
    return () => window.clearTimeout(timer)
  }, [loadReferenceData])
  useEffect(() => {
    const controller = new AbortController()
    void apiRequest<{ data: Holiday[] }>(`holidays?year=${year}`, { signal: controller.signal })
      .then((response) => setHolidays(response.data.filter((holiday) => holiday.active)))
      .catch((error) => {
        if (error instanceof Error && error.name === "AbortError") return
        notify(error instanceof Error ? error.message : "Hari libur gagal dimuat.", "error")
      })

    return () => controller.abort()
  }, [notify, year])

  const filteredEmployees = useMemo(() => {
    const normalizedQuery = query.trim().toLowerCase()
    return employees.filter((employee) => {
      const categoryMatches = category === "Semua kategori" || employee.categories.some((item) => item.name === category)
      const searchMatches = !normalizedQuery || employee.name.toLowerCase().includes(normalizedQuery) || employee.nid_nip.includes(normalizedQuery)
      return categoryMatches && searchMatches
    })
  }, [employees, category, query])

  const recordMap = useMemo(() => new Map(records.map((record) => [`${record.employee_id}|${record.attendance_date}`, record])), [records])
  const holidaySet = useMemo(() => new Set(holidays.map((holiday) => holiday.date)), [holidays])
  const totalDays = daysInMonth(month, year)
  const days = Array.from({ length: totalDays }, (_, index) => index + 1)
  const currentPage = Math.min(page, Math.max(1, Math.ceil(filteredEmployees.length / pageSize)))
  const paginatedEmployees = filteredEmployees.slice((currentPage - 1) * pageSize, currentPage * pageSize)
  const visibleEmployeeIdsKey = paginatedEmployees.map((employee) => employee.id).join(",")

  useEffect(() => {
    if (!referencesReady) return

    const controller = new AbortController()
    const employeeIds = visibleEmployeeIdsKey
      .split(",")
      .filter(Boolean)
      .map(Number)
    const timer = window.setTimeout(() => void loadAttendance(employeeIds, controller.signal), 0)

    return () => {
      window.clearTimeout(timer)
      controller.abort()
    }
  }, [loadAttendance, referencesReady, visibleEmployeeIdsKey])

  const openCreate = (employee?: Employee, day?: number) => {
    setEditingRecord(null)
    setAttachment(null)
    setAttachmentInputKey((value) => value + 1)
    setForm({
      employee_id: employee ? String(employee.id) : "",
      attendance_date: day ? dateKey(year, month, day) : dateKey(year, month, 1),
      status: "present",
      check_in: "",
      check_out: "",
      note: "",
    })
    setErrors({})
    setDialogOpen(true)
  }

  const openEdit = (record: AttendanceRecord) => {
    setEditingRecord(record)
    setAttachment(null)
    setAttachmentInputKey((value) => value + 1)
    setForm({ employee_id: String(record.employee_id), attendance_date: record.attendance_date, status: record.manual_status ?? record.status, check_in: timeValue(record.check_in), check_out: timeValue(record.check_out), note: record.note ?? "" })
    setErrors({})
    setDialogOpen(true)
  }

  const validate = () => {
    const nextErrors: ApiValidationErrors = {}
    if (!form.employee_id) nextErrors.employee_id = ["Pegawai wajib dipilih."]
    if (!form.attendance_date) nextErrors.attendance_date = ["Tanggal wajib diisi."]
    if (!form.status) nextErrors.status = ["Status wajib dipilih."]
    if (form.check_out && !form.check_in) nextErrors.check_in = ["Jam masuk wajib diisi jika jam pulang tersedia."]
    if (form.check_in && form.check_out && form.check_out <= form.check_in) nextErrors.check_out = ["Jam pulang harus setelah jam masuk."]
    if (attachment && attachment.size > 5 * 1024 * 1024) nextErrors.attachment = ["Ukuran lampiran maksimal 5 MB."]
    if (attachment && !["application/pdf", "image/jpeg", "image/png"].includes(attachment.type) && !/\.(pdf|jpe?g|png)$/i.test(attachment.name)) nextErrors.attachment = ["Lampiran harus berupa PDF, JPG, JPEG, atau PNG."]
    setErrors(nextErrors)
    return Object.keys(nextErrors).length === 0
  }

  const save = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!validate()) { notify("Periksa kembali form kehadiran.", "error"); return }
    setSaving(true)
    try {
      const formData = new FormData()
      formData.append("employee_id", form.employee_id)
      formData.append("attendance_date", form.attendance_date)
      formData.append("status", form.status)
      formData.append("check_in", form.check_in)
      formData.append("check_out", form.check_out)
      formData.append("note", form.note)
      if (attachment) formData.append("attachment", attachment)
      if (editingRecord) formData.append("_method", "PUT")

      const response = await apiRequest<{ message: string }>(editingRecord ? `attendances/${editingRecord.id}` : "attendances", {
        method: "POST",
        body: formData,
      })
      notify(response.message, "success")
      setDialogOpen(false)
      setAttachment(null)
      await loadAttendance(paginatedEmployees.map((employee) => employee.id))
    } catch (error) {
      if (error instanceof ApiError) setErrors(error.errors)
      notify(error instanceof Error ? error.message : "Data kehadiran gagal disimpan.", "error")
    } finally { setSaving(false) }
  }

  const confirmDelete = async () => {
    if (!deleteRecord) return
    try {
      const response = await apiRequest<{ message: string }>(`attendances/${deleteRecord.id}`, { method: "DELETE" })
      notify(response.message, "success")
      setSelectedRecord(null)
      await loadAttendance(paginatedEmployees.map((employee) => employee.id))
    } catch (error) {
      notify(error instanceof Error ? error.message : "Data kehadiran gagal dihapus.", "error")
    } finally { setDeleteRecord(null) }
  }

  const chooseCell = (employee: Employee, day: number) => {
    const record = recordMap.get(`${employee.id}|${dateKey(year, month, day)}`) ?? null
    setSelectedCell({ employee, day })
    setSelectedRecord(record)
  }

  const downloadReport = async () => {
    setExporting(true)
    try {
      const params = new URLSearchParams({ month: String(month), year: String(year) })
      if (category !== "Semua kategori") params.set("category", category)
      if (query.trim()) params.set("search", query.trim())

      const response = await fetch(`/api/backend/attendance-reports/export?${params.toString()}`)
      if (!response.ok) {
        const payload = await response.json().catch(() => ({})) as { message?: string }
        throw new Error(payload.message ?? "Rekap kehadiran Excel gagal dibuat.")
      }

      const blob = await response.blob()
      const url = URL.createObjectURL(blob)
      const anchor = document.createElement("a")
      anchor.href = url
      const individualEmployee = filteredEmployees.length === 1 ? filteredEmployees[0] : null
      const safeEmployeeName = individualEmployee?.name
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .replace(/[^A-Za-z0-9]/g, "")
      anchor.download = individualEmployee
        ? `Laporan_${safeEmployeeName}_${MONTHS[month - 1]}_${year}.xlsx`
        : `Rekap Absensi FTM Periode ${MONTHS[month - 1]} ${year}.xlsx`
      anchor.click()
      URL.revokeObjectURL(url)
      notify("Rekap kehadiran Excel berhasil diunduh.", "success")
    } catch (error) {
      notify(error instanceof Error ? error.message : "Rekap kehadiran Excel gagal dibuat.", "error")
    } finally {
      setExporting(false)
    }
  }

  return (
    <div className="mx-auto w-full max-w-[1700px] p-4 sm:p-6 lg:p-7">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div><h1 className="text-2xl font-medium tracking-tight">Rekap kehadiran</h1><p className="mt-1 text-xs text-muted-foreground">Kelola kehadiran manual dan lihat hasil import fingerprint.</p></div><div className="flex gap-2"><Button type="button" variant="outline" className="rounded-lg" disabled={exporting} onClick={() => void downloadReport()}><Download data-icon="inline-start" />{exporting ? "Menyiapkan..." : "Unduh Excel"}</Button><Button type="button" className="rounded-lg" onClick={() => openCreate()}><Plus data-icon="inline-start" />Tambah absensi</Button></div></header>

      <Card className="mt-5 gap-0 rounded-xl border border-border/80 py-0 shadow-sm ring-0"><CardContent className="grid gap-3 px-4 py-4 sm:grid-cols-2 xl:grid-cols-[1fr_.75fr_1.4fr_1.4fr_auto] xl:items-end">
        <label className="grid gap-1.5"><span className="text-[11px] text-muted-foreground">Bulan</span><Select value={String(month)} onValueChange={(value) => { setPage(1); onMonthChange(Number(value)) }}><SelectTrigger className="h-9 w-full rounded-lg"><SelectValue>{MONTHS[month - 1]}</SelectValue></SelectTrigger><SelectContent className="rounded-lg">{MONTHS.map((label, index) => <SelectItem key={label} value={String(index + 1)} className="rounded-md">{label}</SelectItem>)}</SelectContent></Select></label>
        <label className="grid gap-1.5"><span className="text-[11px] text-muted-foreground">Tahun</span><Select value={String(year)} onValueChange={(value) => { setPage(1); onYearChange(Number(value)) }}><SelectTrigger className="h-9 w-full rounded-lg"><SelectValue>{year}</SelectValue></SelectTrigger><SelectContent className="rounded-lg">{[2024, 2025, 2026, 2027].map((value) => <SelectItem key={value} value={String(value)}>{value}</SelectItem>)}</SelectContent></Select></label>
        <label className="grid gap-1.5"><span className="text-[11px] text-muted-foreground">Kategori pegawai</span><Select value={category} onValueChange={(value) => { setPage(1); onCategoryChange(String(value)) }}><SelectTrigger className="h-9 w-full rounded-lg"><SelectValue>{category}</SelectValue></SelectTrigger><SelectContent className="rounded-lg"><SelectItem value="Semua kategori">Semua kategori</SelectItem>{categories.map((item) => <SelectItem key={item.id} value={item.name}>{item.name}</SelectItem>)}</SelectContent></Select></label>
        <label className="grid gap-1.5"><span className="text-[11px] text-muted-foreground">Nama pegawai</span><span className="relative"><Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" /><Input value={query} onChange={(event) => { setPage(1); setQuery(event.target.value) }} placeholder="Cari nama atau NIP/NID" className="h-9 rounded-lg pl-9" /></span></label>
        <Button type="button" className="h-9 rounded-lg" onClick={() => notify(`${filteredEmployees.length} pegawai ditampilkan.`, "info")}>Tampilkan</Button>
      </CardContent></Card>

      <Card className="mt-4 min-w-0 gap-0 rounded-xl border border-border/80 py-0 shadow-sm ring-0">
        <CardHeader className="border-b px-4 py-4"><CardTitle>{MONTHS[month - 1]} {year}</CardTitle><CardDescription>{filteredEmployees.length} pegawai · {totalDays} hari kalender</CardDescription><CardAction className="hidden items-center gap-2 text-[11px] text-muted-foreground sm:flex"><MoveHorizontal className="size-4" />Geser di dalam tabel</CardAction></CardHeader>
        <CardContent className="min-w-0 p-0"><div className="attendance-scroll min-w-0 [&_[data-slot=table-container]]:max-h-[480px] [&_[data-slot=table-container]]:overflow-auto"><Table className="table-fixed border-separate border-spacing-0" style={{ minWidth: `${336 + totalDays * 56}px` }}>
          <TableHeader><TableRow className="hover:bg-transparent"><TableHead className="sticky top-0 left-0 z-50 h-14 w-32 min-w-32 max-w-32 border-r bg-muted px-3">NIP/NID</TableHead><TableHead className="sticky top-0 left-32 z-50 h-14 w-52 min-w-52 max-w-52 border-r bg-muted px-3 shadow-[7px_0_12px_-10px_rgba(0,0,0,.85)]">Nama</TableHead>{days.map((day) => { const index = dayIndex(year, month, day); const weekend = index === 0 || index === 6; return <TableHead key={day} className={cn("sticky top-0 z-20 h-14 w-14 min-w-14 max-w-14 border-r bg-muted px-1 text-center", weekend && "bg-amber-50 dark:bg-amber-950")}><span className="block text-xs">{day}</span><span className="block text-[10px] font-normal text-muted-foreground">{DAY_NAMES[index]}</span></TableHead> })}</TableRow></TableHeader>
          <TableBody>{loading ? <TableRow><TableCell colSpan={totalDays + 2} className="h-32 text-center text-muted-foreground">Memuat rekap...</TableCell></TableRow> : filteredEmployees.length === 0 ? <TableRow><TableCell colSpan={totalDays + 2} className="h-32 text-center text-muted-foreground">Data pegawai tidak ditemukan.</TableCell></TableRow> : paginatedEmployees.map((employee) => <TableRow key={employee.id} className="group"><TableCell className="sticky left-0 z-30 w-32 min-w-32 max-w-32 border-r bg-card px-3 font-medium group-hover:bg-muted">{employee.nid_nip}</TableCell><TableCell className="sticky left-32 z-30 w-52 min-w-52 max-w-52 overflow-hidden border-r bg-card px-3 shadow-[7px_0_12px_-10px_rgba(0,0,0,.85)] group-hover:bg-muted"><div className="grid min-w-0 gap-1"><span className="block truncate" title={employee.name}>{employee.name}</span><div className="flex min-w-0 max-w-full flex-wrap gap-1">{Array.from(new Set(employee.categories.map((category) => category.group))).map((group) => <Badge key={group} className={cn("max-w-full rounded-full", CATEGORY_BADGE_CLASSNAMES[group])}><span className="truncate">{group}</span></Badge>)}<Badge className={cn("max-w-full rounded-full", POSITION_BADGE_CLASSNAME)} title={employee.position}><span className="truncate">{employee.position}</span></Badge></div></div></TableCell>{days.map((day) => { const key = dateKey(year, month, day); const index = dayIndex(year, month, day); const isDayOff = index === 0 || index === 6 || holidaySet.has(key); const record = recordMap.get(`${employee.id}|${key}`); const code = isDayOff ? "L" : record ? STATUS_META[record.effective_status].code : "—"; const style = isDayOff ? "bg-transparent text-muted-foreground" : record ? STATUS_META[record.effective_status].className : "bg-muted/50 text-muted-foreground"; const selected = selectedCell?.employee.id === employee.id && selectedCell.day === day; return <TableCell key={day} className="w-14 min-w-14 max-w-14 border-r px-1 text-center"><button type="button" onClick={() => chooseCell(employee, day)} className={cn("mx-auto grid size-7 place-items-center rounded-md text-[10px] font-medium", style, selected && "ring-2 ring-primary ring-offset-1")}>{code}</button></TableCell> })}</TableRow>)}</TableBody>
        </Table></div>

        <TablePagination page={currentPage} pageSize={pageSize} total={filteredEmployees.length} onPageChange={setPage} onPageSizeChange={setPageSize} />

        <div className="grid min-h-20 gap-3 border-t bg-muted/35 px-4 py-3 sm:grid-cols-[1.4fr_repeat(6,minmax(90px,.7fr))_auto] sm:items-center"><div><span className="text-[10px] text-muted-foreground">Pegawai dan tanggal</span><strong className="mt-1 block text-xs">{selectedCell ? `${selectedCell.employee.name} · ${selectedCell.day} ${MONTHS[month - 1]} ${year}` : "Pilih sel pada tabel"}</strong></div><div><span className="text-[10px] text-muted-foreground">Status</span><strong className="mt-1 block text-xs">{selectedRecord ? STATUS_META[selectedRecord.effective_status].label : "—"}</strong></div><div><span className="text-[10px] text-muted-foreground">Jam masuk</span><strong className="mt-1 block text-xs">{timeValue(selectedRecord?.check_in ?? null) || "—"}</strong></div><div><span className="text-[10px] text-muted-foreground">Jam pulang</span><strong className="mt-1 block text-xs">{timeValue(selectedRecord?.check_out ?? null) || "—"}</strong></div><div><span className="text-[10px] text-muted-foreground">Evaluasi</span><strong className="mt-1 block text-xs">{selectedRecord ? `Telat ${formatLateMinutes(selectedRecord.metrics.late_minutes)} · Lembur ${formatMinutes(selectedRecord.metrics.overtime_minutes)}` : "—"}</strong></div><div><span className="text-[10px] text-muted-foreground">Lokasi mesin</span><strong className="mt-1 block text-xs">{selectedRecord?.machine_location ?? "—"}</strong></div><div><span className="text-[10px] text-muted-foreground">Lampiran</span>{selectedRecord?.attachment ? <a href={`/api/backend/${selectedRecord.attachment.url}`} target="_blank" rel="noreferrer" className="mt-1 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"><Paperclip className="size-3" />Lihat file<ExternalLink className="size-3" /></a> : <strong className="mt-1 block text-xs">—</strong>}</div><div className="flex gap-1">{selectedRecord ? <><Button type="button" variant="outline" size="icon-sm" className="rounded-md" onClick={() => openEdit(selectedRecord)}><Pencil /></Button><Button type="button" variant="outline" size="icon-sm" className="rounded-md text-destructive" onClick={() => setDeleteRecord(selectedRecord)}><Trash2 /></Button></> : selectedCell ? <Button type="button" size="sm" className="rounded-md" onClick={() => openCreate(selectedCell.employee, selectedCell.day)}>Tambah</Button> : null}</div></div>
        <div className="flex flex-wrap gap-x-4 gap-y-2 border-t px-4 py-3 text-[10px] text-muted-foreground">{Object.values(STATUS_META).map((meta) => <span key={meta.code}><strong className="mr-1 text-foreground">{meta.code}</strong>{meta.label}</span>)}<span><strong className="mr-1 text-foreground">L</strong>Libur</span></div>
        </CardContent>
      </Card>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}><DialogContent className="max-h-[90vh] overflow-y-auto rounded-xl sm:max-w-xl"><form onSubmit={save} noValidate><DialogHeader><DialogTitle>{editingRecord ? "Ubah absensi" : "Tambah absensi"}</DialogTitle><DialogDescription>Data manual akan tersimpan sebagai status resmi dan tetap dapat dibandingkan dengan fingerprint.</DialogDescription></DialogHeader><div className="grid gap-4 py-5 sm:grid-cols-2">
        <label className="grid gap-1.5 sm:col-span-2"><span className="text-xs font-medium">Pegawai *</span><Select value={form.employee_id} onValueChange={(value) => setForm({ ...form, employee_id: String(value) })}><SelectTrigger className="h-9 w-full rounded-lg"><SelectValue>{employees.find((item) => String(item.id) === form.employee_id)?.name}</SelectValue></SelectTrigger><SelectContent className="rounded-lg">{employees.map((item) => <SelectItem key={item.id} value={String(item.id)}>{item.nid_nip} · {item.name}</SelectItem>)}</SelectContent></Select>{firstValidationError(errors, "employee_id") ? <span className="text-[11px] text-destructive">{firstValidationError(errors, "employee_id")}</span> : null}</label>
        <label className="grid gap-1.5"><span className="text-xs font-medium">Tanggal *</span><Input type="date" value={form.attendance_date} onChange={(event) => setForm({ ...form, attendance_date: event.target.value })} className="h-9 rounded-lg" />{firstValidationError(errors, "attendance_date") ? <span className="text-[11px] text-destructive">{firstValidationError(errors, "attendance_date")}</span> : null}</label>
        <label className="grid gap-1.5"><span className="text-xs font-medium">Status *</span><Select value={form.status} onValueChange={(value) => setForm({ ...form, status: String(value) as AttendanceStatus })}><SelectTrigger className="h-9 w-full rounded-lg"><SelectValue>{STATUS_META[form.status].label}</SelectValue></SelectTrigger><SelectContent className="rounded-lg">{MANUAL_STATUS_VALUES.map((value) => <SelectItem key={value} value={value}>{STATUS_META[value].code} · {STATUS_META[value].label}</SelectItem>)}</SelectContent></Select></label>
        <label className="grid gap-1.5"><span className="text-xs font-medium">Jam masuk</span><Input type="time" value={form.check_in} onChange={(event) => setForm({ ...form, check_in: event.target.value })} className="h-9 rounded-lg" />{firstValidationError(errors, "check_in") ? <span className="text-[11px] text-destructive">{firstValidationError(errors, "check_in")}</span> : null}</label>
        <label className="grid gap-1.5"><span className="text-xs font-medium">Jam pulang</span><Input type="time" value={form.check_out} onChange={(event) => setForm({ ...form, check_out: event.target.value })} className="h-9 rounded-lg" />{firstValidationError(errors, "check_out") ? <span className="text-[11px] text-destructive">{firstValidationError(errors, "check_out")}</span> : null}</label>
        <label className="grid gap-1.5 sm:col-span-2"><span className="text-xs font-medium">Catatan</span><Textarea value={form.note} onChange={(event) => setForm({ ...form, note: event.target.value })} className="min-h-20 rounded-lg" /></label>
        <label className="grid gap-1.5 sm:col-span-2"><span className="text-xs font-medium">Lampiran bukti</span><Input key={attachmentInputKey} type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" onChange={(event) => setAttachment(event.target.files?.[0] ?? null)} className="h-9 rounded-lg" /><span className="text-[10px] text-muted-foreground">Opsional untuk seluruh status. PDF/JPG/JPEG/PNG, maksimal 5 MB.</span>{firstValidationError(errors, "attachment") ? <span className="text-[11px] text-destructive">{firstValidationError(errors, "attachment")}</span> : null}{editingRecord?.attachment ? <a href={`/api/backend/${editingRecord.attachment.url}`} target="_blank" rel="noreferrer" className="inline-flex w-fit items-center gap-1 text-xs font-medium text-primary hover:underline"><Paperclip className="size-3.5" />{editingRecord.attachment.name ?? "Lihat lampiran saat ini"}<ExternalLink className="size-3.5" /></a> : null}</label>
      </div><DialogFooter><Button type="button" variant="outline" className="rounded-lg" onClick={() => setDialogOpen(false)}>Batal</Button><Button type="submit" className="rounded-lg" disabled={saving}>{saving ? "Menyimpan..." : "Simpan absensi"}</Button></DialogFooter></form></DialogContent></Dialog>

      <AlertDialog open={Boolean(deleteRecord)} onOpenChange={(open) => !open && setDeleteRecord(null)}><AlertDialogContent className="rounded-xl"><AlertDialogHeader><AlertDialogTitle>Hapus data kehadiran?</AlertDialogTitle><AlertDialogDescription>Data kehadiran akan dihapus setelah Anda mengonfirmasi tindakan ini.</AlertDialogDescription></AlertDialogHeader><AlertDialogFooter><AlertDialogCancel className="rounded-lg">Batal</AlertDialogCancel><AlertDialogAction className="rounded-lg" onClick={() => void confirmDelete()}>Ya, hapus</AlertDialogAction></AlertDialogFooter></AlertDialogContent></AlertDialog>
    </div>
  )
}
