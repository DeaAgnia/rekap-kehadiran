"use client"

import { type FormEvent, type ReactNode, useCallback, useEffect, useState } from "react"
import { Pencil, Plus, Trash2 } from "lucide-react"

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
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Switch } from "@/components/ui/switch"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { ApiError, apiRequest, firstValidationError, type ApiValidationErrors } from "@/lib/api"
import type { Notify } from "@/lib/admin-types"
import { AttendanceSettings } from "@/components/attendance-settings"
import { TablePagination } from "@/components/table-pagination"

type MasterValue = string | number | boolean | null
type MasterRecord = { id: number; active: boolean; [key: string]: MasterValue }
type MasterForm = Record<string, MasterValue>

type FieldConfig = {
  name: string
  label: string
  type: "text" | "number" | "date" | "time" | "select"
  required?: boolean
  min?: number
  max?: number
  options?: Array<{ label: string; value: string }>
}

type ColumnConfig = {
  label: string
  render: (item: MasterRecord) => ReactNode
}

type SectionConfig = {
  title: string
  description: string
  singular: string
  endpoint: string
  fields: FieldConfig[]
  defaults: MasterForm
  columns: ColumnConfig[]
  validate?: (form: MasterForm) => ApiValidationErrors
}

type Confirmation =
  | { type: "delete"; item: MasterRecord }
  | { type: "status"; item: MasterRecord; nextActive: boolean }
  | null

function normalizeForForm(item: MasterRecord, fields: FieldConfig[]): MasterForm {
  return Object.fromEntries(fields.map((field) => {
    const value = item[field.name]
    if (field.type === "time" && typeof value === "string") return [field.name, value.slice(0, 5)]
    return [field.name, value ?? ""]
  }))
}

function MasterSection({ config, notify }: { config: SectionConfig; notify: Notify }) {
  const [items, setItems] = useState<MasterRecord[]>([])
  const [loading, setLoading] = useState(true)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editingItem, setEditingItem] = useState<MasterRecord | null>(null)
  const [form, setForm] = useState<MasterForm>({ ...config.defaults })
  const [errors, setErrors] = useState<ApiValidationErrors>({})
  const [saving, setSaving] = useState(false)
  const [confirmation, setConfirmation] = useState<Confirmation>(null)
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(10)

  const currentPage = Math.min(page, Math.max(1, Math.ceil(items.length / pageSize)))
  const paginatedItems = items.slice((currentPage - 1) * pageSize, currentPage * pageSize)

  const loadItems = useCallback(async () => {
    try {
      const response = await apiRequest<{ data: MasterRecord[] }>(config.endpoint)
      setItems(response.data)
    } catch (error) {
      notify(error instanceof Error ? error.message : `${config.title} gagal dimuat.`, "error")
    } finally {
      setLoading(false)
    }
  }, [config.endpoint, config.title, notify])

  useEffect(() => {
    const timer = window.setTimeout(() => void loadItems(), 0)
    return () => window.clearTimeout(timer)
  }, [loadItems])

  const openCreate = () => {
    setEditingItem(null)
    setForm({ ...config.defaults })
    setErrors({})
    setDialogOpen(true)
  }

  const openEdit = (item: MasterRecord) => {
    setEditingItem(item)
    setForm(normalizeForForm(item, config.fields))
    setErrors({})
    setDialogOpen(true)
  }

  const validate = () => {
    const nextErrors: ApiValidationErrors = {}
    for (const field of config.fields) {
      const value = form[field.name]
      if (field.required && (value === "" || value === null || value === undefined)) {
        nextErrors[field.name] = [`${field.label} wajib diisi.`]
      }
      if (field.type === "number" && value !== "" && value !== null) {
        const numericValue = Number(value)
        if (!Number.isFinite(numericValue)) nextErrors[field.name] = [`${field.label} harus berupa angka.`]
        if (field.min !== undefined && numericValue < field.min) nextErrors[field.name] = [`${field.label} minimal ${field.min}.`]
        if (field.max !== undefined && numericValue > field.max) nextErrors[field.name] = [`${field.label} maksimal ${field.max}.`]
      }
    }
    Object.assign(nextErrors, config.validate?.(form) ?? {})
    setErrors(nextErrors)
    return Object.keys(nextErrors).length === 0
  }

  const save = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!validate()) {
      notify("Periksa kembali data master yang wajib diisi.", "error")
      return
    }

    setSaving(true)
    try {
      const body = { ...form, active: editingItem?.active ?? true }
      const response = await apiRequest<{ message: string }>(editingItem ? `${config.endpoint}/${editingItem.id}` : config.endpoint, {
        method: editingItem ? "PUT" : "POST",
        body,
      })
      notify(response.message, "success")
      setDialogOpen(false)
      await loadItems()
    } catch (error) {
      if (error instanceof ApiError) setErrors(error.errors)
      notify(error instanceof Error ? error.message : `${config.singular} gagal disimpan.`, "error")
    } finally {
      setSaving(false)
    }
  }

  const confirmAction = async () => {
    if (!confirmation) return
    try {
      const response = await apiRequest<{ message: string }>(
        confirmation.type === "delete"
          ? `${config.endpoint}/${confirmation.item.id}`
          : `${config.endpoint}/${confirmation.item.id}/status`,
        confirmation.type === "delete"
          ? { method: "DELETE" }
          : { method: "PATCH", body: { active: confirmation.nextActive } }
      )
      notify(response.message, "success")
      await loadItems()
    } catch (error) {
      notify(error instanceof Error ? error.message : "Perubahan data master gagal.", "error")
    } finally {
      setConfirmation(null)
    }
  }

  return (
    <>
      <Card className="gap-0 rounded-xl border border-border/80 py-0 shadow-sm ring-0">
        <CardHeader className="border-b px-4 py-4">
          <CardTitle>{config.title}</CardTitle>
          <CardDescription>{config.description}</CardDescription>
          <Button type="button" className="mt-3 w-fit rounded-lg" onClick={openCreate}><Plus data-icon="inline-start" />Tambah {config.singular}</Button>
        </CardHeader>
        <CardContent className="p-0">
          <Table>
            <TableHeader><TableRow>{config.columns.map((column) => <TableHead key={column.label}>{column.label}</TableHead>)}<TableHead>Status</TableHead><TableHead className="text-right">Aksi</TableHead></TableRow></TableHeader>
            <TableBody>
              {loading ? <TableRow><TableCell colSpan={config.columns.length + 2} className="h-24 text-center text-muted-foreground">Memuat data...</TableCell></TableRow> : items.length === 0 ? <TableRow><TableCell colSpan={config.columns.length + 2} className="h-24 text-center text-muted-foreground">Belum ada data.</TableCell></TableRow> : paginatedItems.map((item) => (
                <TableRow key={item.id}>
                  {config.columns.map((column) => <TableCell key={column.label}>{column.render(item)}</TableCell>)}
                  <TableCell><div className="flex items-center gap-2"><Switch aria-label={`Ubah status ${config.singular} ${String(item.name ?? item.date ?? item.id)}`} checked={item.active} onCheckedChange={(checked) => setConfirmation({ type: "status", item, nextActive: Boolean(checked) })} /><span className="text-[11px] text-muted-foreground">{item.active ? "Aktif" : "Inaktif"}</span></div></TableCell>
                  <TableCell><div className="flex justify-end gap-1"><Button type="button" variant="ghost" size="icon-sm" className="rounded-md" onClick={() => openEdit(item)} aria-label={`Ubah ${config.singular}`}><Pencil /></Button><Button type="button" variant="ghost" size="icon-sm" className="rounded-md text-destructive" onClick={() => setConfirmation({ type: "delete", item })} aria-label={`Hapus ${config.singular}`}><Trash2 /></Button></div></TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          <TablePagination page={currentPage} pageSize={pageSize} total={items.length} onPageChange={setPage} onPageSizeChange={setPageSize} />
        </CardContent>
      </Card>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="max-h-[90vh] overflow-y-auto rounded-xl sm:max-w-xl">
          <form onSubmit={save} noValidate>
            <DialogHeader><DialogTitle>{editingItem ? `Ubah ${config.singular}` : `Tambah ${config.singular}`}</DialogTitle><DialogDescription>Kolom bertanda bintang wajib diisi.</DialogDescription></DialogHeader>
            <div className="grid gap-4 py-5 sm:grid-cols-2">
              {config.fields.map((field) => (
                <label key={field.name} className="grid gap-1.5">
                  <span className="text-xs font-medium">{field.label}{field.required ? " *" : ""}</span>
                  {field.type === "select" ? (
                    <Select value={String(form[field.name] ?? "")} onValueChange={(value) => setForm({ ...form, [field.name]: String(value) })}>
                      <SelectTrigger className="h-9 w-full rounded-lg"><SelectValue>{field.options?.find((option) => option.value === String(form[field.name]))?.label}</SelectValue></SelectTrigger>
                      <SelectContent className="rounded-lg">{field.options?.map((option) => <SelectItem key={option.value} value={option.value} className="rounded-md">{option.label}</SelectItem>)}</SelectContent>
                    </Select>
                  ) : (
                    <Input type={field.type} value={String(form[field.name] ?? "")} min={field.min} max={field.max} onChange={(event) => setForm({ ...form, [field.name]: field.type === "number" && event.target.value !== "" ? Number(event.target.value) : event.target.value })} className="h-9 rounded-lg" />
                  )}
                  {firstValidationError(errors, field.name) ? <span className="text-[11px] text-destructive">{firstValidationError(errors, field.name)}</span> : null}
                </label>
              ))}
            </div>
            <DialogFooter><Button type="button" variant="outline" className="rounded-lg" onClick={() => setDialogOpen(false)}>Batal</Button><Button type="submit" className="rounded-lg" disabled={saving}>{saving ? "Menyimpan..." : "Simpan"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <AlertDialog open={Boolean(confirmation)} onOpenChange={(open) => !open && setConfirmation(null)}>
        <AlertDialogContent className="rounded-xl">
          <AlertDialogHeader><AlertDialogTitle>{confirmation?.type === "delete" ? `Hapus ${config.singular}?` : `Ubah status ${config.singular}?`}</AlertDialogTitle><AlertDialogDescription>{confirmation?.type === "delete" ? "Data akan dihapus setelah Anda mengonfirmasi tindakan ini." : `Status akan diubah menjadi ${confirmation?.nextActive ? "Aktif" : "Inaktif"}.`}</AlertDialogDescription></AlertDialogHeader>
          <AlertDialogFooter><AlertDialogCancel className="rounded-lg">Batal</AlertDialogCancel><AlertDialogAction className="rounded-lg" onClick={() => void confirmAction()}>Ya, lanjutkan</AlertDialogAction></AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}

const configs: Record<string, SectionConfig> = {
  categories: {
    title: "Kategori pegawai", description: "Kategori dapat digunakan oleh lebih dari satu pegawai.", singular: "kategori", endpoint: "categories",
    defaults: { name: "", group: "Tendik" },
    fields: [{ name: "name", label: "Nama kategori", type: "text", required: true }, { name: "group", label: "Kelompok", type: "select", required: true, options: [{ label: "Tendik", value: "Tendik" }, { label: "Dosen", value: "Dosen" }, { label: "Pimpinan", value: "Pimpinan" }] }],
    columns: [{ label: "Nama", render: (item) => <span className="font-medium">{String(item.name)}</span> }, { label: "Kelompok", render: (item) => <Badge variant="secondary" className="rounded-full">{String(item.group)}</Badge> }, { label: "Jumlah pegawai", render: (item) => String(item.employees_count ?? 0) }],
  },
  machines: {
    title: "Mesin absensi", description: "Kelola identitas dan lokasi perangkat mesin absensi.", singular: "mesin absensi", endpoint: "attendance-machines",
    defaults: { name: "", serial_number: "", location: "" },
    fields: [{ name: "name", label: "Nama mesin", type: "text", required: true }, { name: "serial_number", label: "Kode SN mesin", type: "text", required: true }, { name: "location", label: "Lokasi mesin", type: "text", required: true }],
    columns: [{ label: "Nama mesin", render: (item) => <span className="font-medium">{String(item.name)}</span> }, { label: "Kode SN mesin", render: (item) => <span className="font-mono text-[11px]">{String(item.serial_number)}</span> }, { label: "Lokasi", render: (item) => String(item.location) }],
  },
  schedules: {
    title: "Jam kerja", description: "Aturan jam kerja berlaku berdasarkan periode efektif.", singular: "jam kerja", endpoint: "work-schedules",
    defaults: { name: "Jam Kerja Umum", work_start: "08:00", work_end: "17:00", break_start: "12:00", break_end: "13:00", late_tolerance_minutes: 0, early_leave_tolerance_minutes: 0, effective_from: "2026-01-01", effective_until: "" },
    fields: [{ name: "name", label: "Nama aturan", type: "text", required: true }, { name: "work_start", label: "Jam masuk", type: "time", required: true }, { name: "work_end", label: "Jam pulang", type: "time", required: true }, { name: "break_start", label: "Mulai istirahat", type: "time", required: true }, { name: "break_end", label: "Selesai istirahat", type: "time", required: true }, { name: "late_tolerance_minutes", label: "Toleransi terlambat (menit)", type: "number", required: true, min: 0, max: 240 }, { name: "early_leave_tolerance_minutes", label: "Toleransi pulang cepat (menit)", type: "number", required: true, min: 0, max: 240 }, { name: "effective_from", label: "Mulai berlaku", type: "date", required: true }, { name: "effective_until", label: "Selesai berlaku", type: "date" }],
    columns: [{ label: "Nama", render: (item) => String(item.name) }, { label: "Jam kerja", render: (item) => `${String(item.work_start).slice(0, 5)}–${String(item.work_end).slice(0, 5)}` }, { label: "Istirahat", render: (item) => `${String(item.break_start).slice(0, 5)}–${String(item.break_end).slice(0, 5)}` }, { label: "Toleransi", render: (item) => `${item.late_tolerance_minutes} / ${item.early_leave_tolerance_minutes} menit` }, { label: "Berlaku", render: (item) => `${item.effective_from}${item.effective_until ? ` – ${item.effective_until}` : ""}` }],
    validate: (form): ApiValidationErrors => {
      const errors: ApiValidationErrors = {}
      if (String(form.work_end) <= String(form.work_start)) errors.work_end = ["Jam pulang harus setelah jam masuk."]
      if (String(form.break_start) <= String(form.work_start) || String(form.break_start) >= String(form.work_end)) errors.break_start = ["Mulai istirahat harus berada di dalam jam kerja."]
      if (String(form.break_end) <= String(form.break_start) || String(form.break_end) >= String(form.work_end)) errors.break_end = ["Selesai istirahat harus setelah mulai istirahat dan sebelum jam pulang."]
      if (form.effective_until && String(form.effective_until) < String(form.effective_from)) errors.effective_until = ["Selesai berlaku tidak boleh sebelum mulai berlaku."]
      return errors
    },
  },
  overtime: {
    title: "Aturan lembur", description: "Durasi lembur dihitung apa adanya tanpa pembulatan.", singular: "aturan lembur", endpoint: "overtime-rules",
    defaults: { name: "Lembur Umum", starts_after_minutes: 120, max_minutes: 240, effective_from: "2026-01-01", effective_until: "" },
    fields: [{ name: "name", label: "Nama aturan", type: "text", required: true }, { name: "starts_after_minutes", label: "Ambang dari jam pulang (menit)", type: "number", required: true, min: 0, max: 1440 }, { name: "max_minutes", label: "Maksimal lembur (menit)", type: "number", required: true, min: 1, max: 1440 }, { name: "effective_from", label: "Mulai berlaku", type: "date", required: true }, { name: "effective_until", label: "Selesai berlaku", type: "date" }],
    columns: [{ label: "Nama", render: (item) => String(item.name) }, { label: "Mulai setelah", render: (item) => `${item.starts_after_minutes} menit` }, { label: "Maksimal", render: (item) => `${item.max_minutes} menit` }, { label: "Berlaku", render: (item) => `${item.effective_from}${item.effective_until ? ` – ${item.effective_until}` : ""}` }],
    validate: (form): ApiValidationErrors => form.effective_until && String(form.effective_until) < String(form.effective_from) ? { effective_until: ["Selesai berlaku tidak boleh sebelum mulai berlaku."] } : {},
  },
  holidays: {
    title: "Hari libur", description: "Sabtu dan Minggu otomatis libur; daftar ini untuk libur tambahan.", singular: "hari libur", endpoint: "holidays",
    defaults: { date: "", name: "", type: "internal" },
    fields: [{ name: "date", label: "Tanggal", type: "date", required: true }, { name: "name", label: "Nama hari libur", type: "text", required: true }, { name: "type", label: "Jenis", type: "select", required: true, options: [{ label: "Internal", value: "internal" }, { label: "Nasional", value: "national" }] }],
    columns: [{ label: "Tanggal", render: (item) => String(item.date) }, { label: "Nama", render: (item) => String(item.name) }, { label: "Jenis", render: (item) => <Badge variant="secondary" className="rounded-full">{item.type === "national" ? "Nasional" : "Internal"}</Badge> }],
  },
}

export function MasterDataManagement({ notify }: { notify: Notify }) {
  return (
    <div className="mx-auto w-full max-w-[1500px] p-4 sm:p-6 lg:p-7">
      <header><h1 className="text-2xl font-medium tracking-tight">Data master</h1><p className="mt-1 text-xs text-muted-foreground">Kelola kategori, mesin absensi, jam kerja, aturan lembur, dan hari libur.</p></header>
      <Tabs defaultValue="settings" className="mt-5">
        <TabsList className="flex w-full flex-wrap rounded-lg sm:w-fit"><TabsTrigger value="settings">Pengaturan Kehadiran</TabsTrigger><TabsTrigger value="categories">Kategori</TabsTrigger><TabsTrigger value="machines">Mesin absensi</TabsTrigger><TabsTrigger value="schedules">Jam kerja</TabsTrigger><TabsTrigger value="overtime">Lembur</TabsTrigger><TabsTrigger value="holidays">Hari libur</TabsTrigger></TabsList>
        <TabsContent value="settings" className="mt-4"><AttendanceSettings notify={notify} /></TabsContent>
        {Object.entries(configs).map(([key, config]) => <TabsContent key={key} value={key} className="mt-4"><MasterSection config={config} notify={notify} /></TabsContent>)}
      </Tabs>
    </div>
  )
}
