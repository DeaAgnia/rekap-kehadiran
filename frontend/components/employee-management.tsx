"use client"

import { type FormEvent, useCallback, useEffect, useMemo, useState } from "react"
import { Pencil, Plus, Search, Trash2 } from "lucide-react"

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
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Checkbox } from "@/components/ui/checkbox"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Switch } from "@/components/ui/switch"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { TablePagination } from "@/components/table-pagination"
import { ApiError, apiRequest, firstValidationError, type ApiValidationErrors } from "@/lib/api"
import type { Category, Employee, Notify } from "@/lib/admin-types"

type EmployeeForm = {
  nid_nip: string
  name: string
  position: string
  machine_id: string
  employment_start_date: string
  attendance_type: "normal" | "mpp" | "stb"
  attendance_type_start_date: string
  attendance_type_end_date: string
  active: boolean
  category_ids: number[]
}

type Confirmation =
  | { type: "delete"; employee: Employee }
  | { type: "status"; employee: Employee; nextActive: boolean }
  | null

const EMPTY_FORM: EmployeeForm = {
  nid_nip: "",
  name: "",
  position: "",
  machine_id: "",
  employment_start_date: "",
  attendance_type: "normal",
  attendance_type_start_date: "",
  attendance_type_end_date: "",
  active: true,
  category_ids: [],
}

function FieldError({ message }: { message?: string }) {
  return message ? <p className="text-[11px] text-destructive">{message}</p> : null
}

function defaultTypeEndDate(type: EmployeeForm["attendance_type"], startDate: string) {
  if (type === "normal" || !startDate) return ""
  const [year, month, day] = startDate.split("-").map(Number)
  const monthsToAdd = type === "mpp" ? 6 : 48
  const targetMonthIndex = month - 1 + monthsToAdd
  const targetYear = year + Math.floor(targetMonthIndex / 12)
  const targetMonth = targetMonthIndex % 12
  const lastDay = new Date(Date.UTC(targetYear, targetMonth + 1, 0)).getUTCDate()
  return `${targetYear}-${String(targetMonth + 1).padStart(2, "0")}-${String(Math.min(day, lastDay)).padStart(2, "0")}`
}

export function EmployeeManagement({ notify }: { notify: Notify }) {
  const [employees, setEmployees] = useState<Employee[]>([])
  const [categories, setCategories] = useState<Category[]>([])
  const [search, setSearch] = useState("")
  const [categoryFilter, setCategoryFilter] = useState("all")
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(10)
  const [isLoading, setIsLoading] = useState(true)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editingEmployee, setEditingEmployee] = useState<Employee | null>(null)
  const [form, setForm] = useState<EmployeeForm>(EMPTY_FORM)
  const [errors, setErrors] = useState<ApiValidationErrors>({})
  const [isSaving, setIsSaving] = useState(false)
  const [confirmation, setConfirmation] = useState<Confirmation>(null)

  const loadData = useCallback(async () => {
    try {
      const [employeeResponse, categoryResponse] = await Promise.all([
        apiRequest<{ data: Employee[] }>("employees"),
        apiRequest<{ data: Category[] }>("categories"),
      ])
      setEmployees(employeeResponse.data)
      setCategories(categoryResponse.data.filter((category) => category.active))
    } catch (error) {
      notify(error instanceof Error ? error.message : "Data pegawai gagal dimuat.", "error")
    } finally {
      setIsLoading(false)
    }
  }, [notify])

  useEffect(() => {
    const timer = window.setTimeout(() => void loadData(), 0)
    return () => window.clearTimeout(timer)
  }, [loadData])

  const filteredEmployees = useMemo(() => {
    const query = search.trim().toLowerCase()
    return employees.filter((employee) => {
      const searchMatches = !query || [employee.name, employee.nid_nip, employee.position, employee.machine_id]
        .some((value) => value.toLowerCase().includes(query))
      const categoryMatches = categoryFilter === "all"
        || (categoryFilter.startsWith("group:") && employee.categories.some((category) => category.group === categoryFilter.replace("group:", "")))
        || (categoryFilter.startsWith("category:") && employee.categories.some((category) => category.id === Number(categoryFilter.replace("category:", ""))))

      return searchMatches && categoryMatches
    })
  }, [categoryFilter, employees, search])

  const currentPage = Math.min(page, Math.max(1, Math.ceil(filteredEmployees.length / pageSize)))
  const paginatedEmployees = filteredEmployees.slice((currentPage - 1) * pageSize, currentPage * pageSize)
  const selectedCategoryLabel = categoryFilter === "all"
    ? "Semua kategori"
    : categoryFilter.startsWith("group:")
      ? `${categoryFilter.replace("group:", "")} — semua`
      : categories.find((category) => `category:${category.id}` === categoryFilter)?.name ?? "Kategori"

  const openCreate = () => {
    setEditingEmployee(null)
    setForm(EMPTY_FORM)
    setErrors({})
    setDialogOpen(true)
  }

  const openEdit = (employee: Employee) => {
    setEditingEmployee(employee)
    setForm({
      nid_nip: employee.nid_nip,
      name: employee.name,
      position: employee.position,
      machine_id: employee.machine_id,
      employment_start_date: employee.employment_start_date,
      attendance_type: employee.attendance_type,
      attendance_type_start_date: employee.attendance_type_start_date ?? "",
      attendance_type_end_date: employee.attendance_type_end_date ?? "",
      active: employee.active,
      category_ids: employee.categories.map((category) => category.id),
    })
    setErrors({})
    setDialogOpen(true)
  }

  const validate = () => {
    const nextErrors: ApiValidationErrors = {}
    if (!form.nid_nip.trim()) nextErrors.nid_nip = ["NIP/NID wajib diisi."]
    if (!form.name.trim()) nextErrors.name = ["Nama pegawai wajib diisi."]
    if (!form.position.trim()) nextErrors.position = ["Jabatan wajib diisi."]
    if (!form.machine_id.trim()) nextErrors.machine_id = ["ID Mesin wajib diisi."]
    if (!form.employment_start_date) nextErrors.employment_start_date = ["Tanggal mulai bekerja wajib diisi."]
    if (form.attendance_type !== "normal" && !form.attendance_type_start_date) nextErrors.attendance_type_start_date = ["Tanggal mulai tipe wajib diisi."]
    if (form.attendance_type !== "normal" && !form.attendance_type_end_date) nextErrors.attendance_type_end_date = ["Tanggal akhir tipe wajib diisi."]
    if (form.attendance_type_start_date && form.attendance_type_end_date && form.attendance_type_end_date < form.attendance_type_start_date) nextErrors.attendance_type_end_date = ["Tanggal akhir tidak boleh sebelum tanggal mulai."]
    if (form.category_ids.length === 0) nextErrors.category_ids = ["Pilih minimal satu kategori."]
    setErrors(nextErrors)
    return Object.keys(nextErrors).length === 0
  }

  const saveEmployee = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!validate()) {
      notify("Periksa kembali data pegawai yang wajib diisi.", "error")
      return
    }

    setIsSaving(true)
    try {
      const path = editingEmployee ? `employees/${editingEmployee.id}` : "employees"
      const response = await apiRequest<{ message: string; data: Employee }>(path, {
        method: editingEmployee ? "PUT" : "POST",
        body: form,
      })
      notify(response.message, "success")
      setDialogOpen(false)
      await loadData()
    } catch (error) {
      if (error instanceof ApiError) setErrors(error.errors)
      notify(error instanceof Error ? error.message : "Data pegawai gagal disimpan.", "error")
    } finally {
      setIsSaving(false)
    }
  }

  const confirmAction = async () => {
    if (!confirmation) return
    const { employee } = confirmation

    try {
      if (confirmation.type === "delete") {
        const response = await apiRequest<{ message: string }>(`employees/${employee.id}`, { method: "DELETE" })
        notify(response.message, "success")
      } else {
        const response = await apiRequest<{ message: string }>(`employees/${employee.id}/status`, {
          method: "PATCH",
          body: { active: confirmation.nextActive },
        })
        notify(response.message, "success")
      }
      await loadData()
    } catch (error) {
      notify(error instanceof Error ? error.message : "Perubahan data pegawai gagal.", "error")
    } finally {
      setConfirmation(null)
    }
  }

  return (
    <div className="mx-auto w-full max-w-[1500px] p-4 sm:p-6 lg:p-7">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-2xl font-medium tracking-tight">Data pegawai</h1>
          <p className="mt-1 text-xs text-muted-foreground">Kelola identitas, kategori, ID mesin, tipe MPP/STB, dan status pegawai.</p>
        </div>
        <Button type="button" className="h-9 rounded-lg" onClick={openCreate}><Plus data-icon="inline-start" />Tambah pegawai</Button>
      </header>

      <Card className="mt-5 gap-0 rounded-xl border border-border/80 py-0 shadow-sm ring-0">
        <CardHeader className="border-b px-4 py-4">
          <CardTitle>Daftar pegawai</CardTitle>
          <CardDescription>{filteredEmployees.length} dari {employees.length} pegawai ditampilkan</CardDescription>
          <div className="mt-3 grid gap-3 sm:grid-cols-[minmax(260px,480px)_minmax(220px,320px)]">
            <span className="relative block">
              <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1) }} placeholder="Cari nama, NIP/NID, jabatan, atau ID Mesin" className="h-9 rounded-lg pl-9" />
            </span>
            <Select value={categoryFilter} onValueChange={(value) => { setCategoryFilter(String(value)); setPage(1) }}>
              <SelectTrigger className="h-9 w-full rounded-lg bg-background"><SelectValue>{selectedCategoryLabel}</SelectValue></SelectTrigger>
              <SelectContent className="rounded-lg">
                <SelectItem value="all" className="rounded-md">Semua kategori</SelectItem>
                <SelectItem value="group:Tendik" className="rounded-md">Tendik — semua</SelectItem>
                <SelectItem value="group:Dosen" className="rounded-md">Dosen — semua</SelectItem>
                <SelectItem value="group:Pimpinan" className="rounded-md">Pimpinan</SelectItem>
                <SelectGroup>
                  <SelectLabel>Dosen setiap prodi</SelectLabel>
                  {categories.filter((category) => category.group === "Dosen").map((category) => <SelectItem key={category.id} value={`category:${category.id}`} className="rounded-md">{category.name}</SelectItem>)}
                </SelectGroup>
                <SelectGroup>
                  <SelectLabel>Tendik setiap prodi</SelectLabel>
                  {categories.filter((category) => category.group === "Tendik").map((category) => <SelectItem key={category.id} value={`category:${category.id}`} className="rounded-md">{category.name}</SelectItem>)}
                </SelectGroup>
              </SelectContent>
            </Select>
          </div>
        </CardHeader>
        <CardContent className="min-w-0 p-0">
          <div className="min-w-0 [&_[data-slot=table-container]]:max-h-[520px] [&_[data-slot=table-container]]:overflow-auto">
          <Table className="table-fixed border-separate border-spacing-0" style={{ minWidth: "1410px" }}>
            <TableHeader><TableRow className="hover:bg-transparent"><TableHead className="sticky top-0 left-0 z-50 h-12 w-32 min-w-32 max-w-32 border-r bg-muted px-3">NIP/NID</TableHead><TableHead className="sticky top-0 left-32 z-50 h-12 w-52 min-w-52 max-w-52 border-r bg-muted px-3 shadow-[7px_0_12px_-10px_rgba(0,0,0,.85)]">Nama</TableHead><TableHead className="sticky top-0 z-20 h-12 w-68 min-w-68 border-r bg-muted px-3">Jabatan</TableHead><TableHead className="sticky top-0 z-20 h-12 w-64 min-w-64 border-r bg-muted px-3">Kategori</TableHead><TableHead className="sticky top-0 z-20 h-12 w-24 min-w-24 border-r bg-muted px-3">ID Mesin</TableHead><TableHead className="sticky top-0 z-20 h-12 w-56 min-w-56 border-r bg-muted px-3">Tipe kehadiran</TableHead><TableHead className="sticky top-0 z-20 h-12 w-30 min-w-30 border-r bg-muted px-3">Status</TableHead><TableHead className="sticky top-0 z-20 h-12 w-24 min-w-24 bg-muted px-3 text-right">Aksi</TableHead></TableRow></TableHeader>
            <TableBody>
              {isLoading ? (
                <TableRow><TableCell colSpan={8} className="h-28 text-center text-muted-foreground">Memuat data pegawai...</TableCell></TableRow>
              ) : filteredEmployees.length === 0 ? (
                <TableRow><TableCell colSpan={8} className="h-28 text-center text-muted-foreground">Data pegawai tidak ditemukan.</TableCell></TableRow>
              ) : paginatedEmployees.map((employee) => (
                <TableRow key={employee.id} className="group">
                  <TableCell className="sticky left-0 z-30 w-32 min-w-32 max-w-32 border-r bg-card px-3 font-medium tabular-nums group-hover:bg-muted">{employee.nid_nip}</TableCell>
                  <TableCell className="sticky left-32 z-30 w-52 min-w-52 max-w-52 overflow-hidden border-r bg-card px-3 shadow-[7px_0_12px_-10px_rgba(0,0,0,.85)] group-hover:bg-muted"><span className="block truncate" title={employee.name}>{employee.name}</span></TableCell>
                  <TableCell className="w-68 min-w-68 max-w-68 border-r px-3"><span className="block truncate" title={employee.position}>{employee.position}</span></TableCell>
                  <TableCell className="w-64 min-w-64 max-w-64 border-r px-3"><div className="flex max-w-full flex-wrap gap-1 whitespace-normal">{employee.categories.map((category) => <Badge key={category.id} variant="secondary" className="rounded-full">{category.name}</Badge>)}</div></TableCell>
                  <TableCell className="w-24 min-w-24 max-w-24 border-r px-3 tabular-nums">{employee.machine_id}</TableCell>
                  <TableCell className="w-56 min-w-56 max-w-56 border-r px-3">{employee.attendance_type === "normal" ? <span className="text-muted-foreground">Normal</span> : <div className="grid gap-1"><Badge variant="secondary" className="w-fit rounded-full uppercase">{employee.attendance_type}</Badge><span className="text-[10px] text-muted-foreground">{employee.attendance_type_start_date} – {employee.attendance_type_end_date}</span></div>}</TableCell>
                  <TableCell className="w-30 min-w-30 max-w-30 border-r px-3">
                    <div className="flex items-center gap-2">
                      <Switch checked={employee.active} onCheckedChange={(checked) => setConfirmation({ type: "status", employee, nextActive: Boolean(checked) })} aria-label={`Ubah status ${employee.name}`} />
                      <span className="text-[11px] text-muted-foreground">{employee.active ? "Aktif" : "Inaktif"}</span>
                    </div>
                  </TableCell>
                  <TableCell className="w-24 min-w-24 max-w-24 px-3"><div className="flex justify-end gap-1"><Button type="button" variant="ghost" size="icon-sm" className="rounded-md" aria-label={`Ubah ${employee.name}`} onClick={() => openEdit(employee)}><Pencil /></Button><Button type="button" variant="ghost" size="icon-sm" className="rounded-md text-destructive" aria-label={`Hapus ${employee.name}`} onClick={() => setConfirmation({ type: "delete", employee })}><Trash2 /></Button></div></TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          </div>
          <TablePagination page={currentPage} pageSize={pageSize} total={filteredEmployees.length} onPageChange={setPage} onPageSizeChange={setPageSize} />
        </CardContent>
      </Card>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="max-h-[90vh] overflow-y-auto rounded-xl sm:max-w-2xl">
          <form onSubmit={saveEmployee} noValidate>
            <DialogHeader><DialogTitle>{editingEmployee ? "Ubah pegawai" : "Tambah pegawai"}</DialogTitle><DialogDescription>Lengkapi seluruh data wajib. Pegawai dapat memiliki lebih dari satu kategori.</DialogDescription></DialogHeader>
            <div className="grid gap-4 py-5 sm:grid-cols-2">
              <label className="grid gap-1.5"><span className="text-xs font-medium">NIP/NID *</span><Input value={form.nid_nip} onChange={(event) => setForm({ ...form, nid_nip: event.target.value })} className="h-9 rounded-lg" /><FieldError message={firstValidationError(errors, "nid_nip")} /></label>
              <label className="grid gap-1.5"><span className="text-xs font-medium">Nama pegawai *</span><Input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} className="h-9 rounded-lg" /><FieldError message={firstValidationError(errors, "name")} /></label>
              <label className="grid gap-1.5"><span className="text-xs font-medium">Jabatan *</span><Input value={form.position} onChange={(event) => setForm({ ...form, position: event.target.value })} className="h-9 rounded-lg" /><FieldError message={firstValidationError(errors, "position")} /></label>
              <label className="grid gap-1.5"><span className="text-xs font-medium">ID Mesin *</span><Input value={form.machine_id} onChange={(event) => setForm({ ...form, machine_id: event.target.value })} className="h-9 rounded-lg" /><span className="text-[10px] text-muted-foreground">Gunakan tanda - untuk pegawai yang tidak menggunakan mesin.</span><FieldError message={firstValidationError(errors, "machine_id")} /></label>
              <label className="grid gap-1.5"><span className="text-xs font-medium">Tanggal mulai bekerja *</span><Input type="date" value={form.employment_start_date} onChange={(event) => setForm({ ...form, employment_start_date: event.target.value })} className="h-9 rounded-lg" /><FieldError message={firstValidationError(errors, "employment_start_date")} /></label>
              <label className="flex items-center gap-3 self-end rounded-lg border px-3 py-2"><Switch checked={form.active} onCheckedChange={(checked) => setForm({ ...form, active: Boolean(checked) })} /><span className="text-xs">Pegawai aktif</span></label>
              <label className="grid gap-1.5 sm:col-span-2"><span className="text-xs font-medium">Tipe kehadiran pegawai *</span><Select value={form.attendance_type} onValueChange={(value) => { const type = String(value) as EmployeeForm["attendance_type"]; setForm({ ...form, attendance_type: type, attendance_type_start_date: type === "normal" ? "" : form.attendance_type_start_date, attendance_type_end_date: type === "normal" ? "" : defaultTypeEndDate(type, form.attendance_type_start_date) }) }}><SelectTrigger className="h-9 w-full rounded-lg"><SelectValue>{form.attendance_type === "normal" ? "Normal" : form.attendance_type.toUpperCase()}</SelectValue></SelectTrigger><SelectContent className="rounded-lg"><SelectItem value="normal">Normal</SelectItem><SelectItem value="mpp">MPP — Masa Persiapan Pensiun</SelectItem><SelectItem value="stb">STB — Surat Tugas Belajar</SelectItem></SelectContent></Select><span className="text-[10px] text-muted-foreground">MPP otomatis 6 bulan dan STB otomatis 4 tahun dari tanggal mulai. Tanggal akhir tetap dapat diubah.</span><FieldError message={firstValidationError(errors, "attendance_type")} /></label>
              {form.attendance_type !== "normal" ? <><label className="grid gap-1.5"><span className="text-xs font-medium">Tanggal mulai tipe *</span><Input type="date" value={form.attendance_type_start_date} onChange={(event) => setForm({ ...form, attendance_type_start_date: event.target.value, attendance_type_end_date: defaultTypeEndDate(form.attendance_type, event.target.value) })} className="h-9 rounded-lg" /><FieldError message={firstValidationError(errors, "attendance_type_start_date")} /></label><label className="grid gap-1.5"><span className="text-xs font-medium">Tanggal akhir tipe *</span><Input type="date" min={form.attendance_type_start_date || undefined} value={form.attendance_type_end_date} onChange={(event) => setForm({ ...form, attendance_type_end_date: event.target.value })} className="h-9 rounded-lg" /><FieldError message={firstValidationError(errors, "attendance_type_end_date")} /></label></> : null}
              <fieldset className="sm:col-span-2"><legend className="text-xs font-medium">Kategori pegawai *</legend><div className="mt-2 grid gap-2 rounded-lg border p-3 sm:grid-cols-2">{categories.map((category) => { const checked = form.category_ids.includes(category.id); return <label key={category.id} className="flex items-center gap-2 text-xs"><Checkbox checked={checked} onCheckedChange={(nextChecked) => setForm({ ...form, category_ids: nextChecked ? [...form.category_ids, category.id] : form.category_ids.filter((id) => id !== category.id) })} />{category.name}</label> })}</div><FieldError message={firstValidationError(errors, "category_ids")} /></fieldset>
            </div>
            <DialogFooter><Button type="button" variant="outline" className="rounded-lg" onClick={() => setDialogOpen(false)}>Batal</Button><Button type="submit" className="rounded-lg" disabled={isSaving}>{isSaving ? "Menyimpan..." : "Simpan pegawai"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <AlertDialog open={Boolean(confirmation)} onOpenChange={(open) => !open && setConfirmation(null)}>
        <AlertDialogContent className="rounded-xl">
          <AlertDialogHeader><AlertDialogTitle>{confirmation?.type === "delete" ? "Hapus data pegawai?" : "Ubah status pegawai?"}</AlertDialogTitle><AlertDialogDescription>{confirmation?.type === "delete" ? `Data ${confirmation.employee.name} akan dihapus. Tindakan ini memerlukan konfirmasi.` : `Status ${confirmation?.employee.name} akan diubah menjadi ${confirmation?.nextActive ? "Aktif" : "Inaktif"}.`}</AlertDialogDescription></AlertDialogHeader>
          <AlertDialogFooter><AlertDialogCancel className="rounded-lg">Batal</AlertDialogCancel><AlertDialogAction className="rounded-lg" onClick={() => void confirmAction()}>Ya, lanjutkan</AlertDialogAction></AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
