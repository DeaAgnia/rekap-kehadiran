"use client"

import { type FormEvent, useCallback, useEffect, useMemo, useState } from "react"
import { Clock3, Save, TimerReset } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { ApiError, apiRequest, firstValidationError, type ApiValidationErrors } from "@/lib/api"
import type { AttendanceSetting, Notify } from "@/lib/admin-types"

type AttendanceSettingForm = Pick<
  AttendanceSetting,
  | "work_start"
  | "work_end"
  | "late_tolerance_minutes"
  | "overtime_starts_after_minutes"
  | "overtime_max_minutes"
>

const EMPTY_FORM: AttendanceSettingForm = {
  work_start: "08:00",
  work_end: "17:00",
  late_tolerance_minutes: 0,
  overtime_starts_after_minutes: 120,
  overtime_max_minutes: 240,
}

function addMinutes(time: string, minutes: number) {
  const [hours, minute] = time.split(":").map(Number)
  const total = (hours * 60 + minute + minutes) % (24 * 60)
  return `${String(Math.floor(total / 60)).padStart(2, "0")}:${String(total % 60).padStart(2, "0")}`
}

function ErrorText({ errors, field }: { errors: ApiValidationErrors; field: string }) {
  const message = firstValidationError(errors, field)
  return message ? <span className="text-[11px] text-destructive">{message}</span> : null
}

export function AttendanceSettings({ notify }: { notify: Notify }) {
  const [form, setForm] = useState<AttendanceSettingForm>(EMPTY_FORM)
  const [effectiveFrom, setEffectiveFrom] = useState("")
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState<ApiValidationErrors>({})

  const loadSetting = useCallback(async () => {
    try {
      const response = await apiRequest<{ data: AttendanceSetting }>("attendance-settings")
      setForm({
        work_start: response.data.work_start,
        work_end: response.data.work_end,
        late_tolerance_minutes: response.data.late_tolerance_minutes,
        overtime_starts_after_minutes: response.data.overtime_starts_after_minutes,
        overtime_max_minutes: response.data.overtime_max_minutes,
      })
      setEffectiveFrom(response.data.effective_from)
    } catch (error) {
      notify(error instanceof Error ? error.message : "Pengaturan kehadiran gagal dimuat.", "error")
    } finally {
      setLoading(false)
    }
  }, [notify])

  useEffect(() => {
    const timer = window.setTimeout(() => void loadSetting(), 0)
    return () => window.clearTimeout(timer)
  }, [loadSetting])

  const overtimeStartsAt = useMemo(
    () => addMinutes(form.work_end, Number(form.overtime_starts_after_minutes) || 0),
    [form.work_end, form.overtime_starts_after_minutes]
  )

  const validate = () => {
    const nextErrors: ApiValidationErrors = {}
    if (!form.work_start) nextErrors.work_start = ["Jam masuk wajib diisi."]
    if (!form.work_end) nextErrors.work_end = ["Jam pulang wajib diisi."]
    if (form.work_end <= form.work_start) nextErrors.work_end = ["Jam pulang harus setelah jam masuk."]
    if (form.late_tolerance_minutes < 0 || form.late_tolerance_minutes > 240) nextErrors.late_tolerance_minutes = ["Toleransi harus antara 0–240 menit."]
    if (form.overtime_starts_after_minutes < 0 || form.overtime_starts_after_minutes > 1440) nextErrors.overtime_starts_after_minutes = ["Ambang lembur harus antara 0–1440 menit."]
    if (form.overtime_max_minutes < 1 || form.overtime_max_minutes > 1440) nextErrors.overtime_max_minutes = ["Maksimal lembur harus antara 1–1440 menit."]
    setErrors(nextErrors)
    return Object.keys(nextErrors).length === 0
  }

  const save = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!validate()) {
      notify("Periksa kembali pengaturan kehadiran.", "error")
      return
    }

    setSaving(true)
    try {
      const response = await apiRequest<{ message: string; data: AttendanceSetting }>("attendance-settings", {
        method: "PUT",
        body: form,
      })
      notify(response.message, "success")
      setErrors({})
      await loadSetting()
    } catch (error) {
      if (error instanceof ApiError) setErrors(error.errors)
      notify(error instanceof Error ? error.message : "Pengaturan kehadiran gagal disimpan.", "error")
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card className="gap-0 rounded-xl border border-border/80 py-0 shadow-sm ring-0">
      <CardHeader className="border-b px-5 py-5">
        <div className="flex items-start gap-3">
          <span className="grid size-10 place-items-center rounded-xl bg-primary/8 text-primary"><Clock3 className="size-5" /></span>
          <div><CardTitle>Pengaturan kehadiran</CardTitle><CardDescription className="mt-1">Satu form untuk jam kerja, toleransi keterlambatan, dan aturan lembur aktif.</CardDescription></div>
        </div>
      </CardHeader>
      <CardContent className="px-5 py-5">
        {loading ? <p className="py-10 text-center text-xs text-muted-foreground">Memuat pengaturan...</p> : (
          <form onSubmit={save} noValidate>
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
              <label className="grid gap-1.5"><span className="text-xs font-medium">Jam masuk *</span><Input type="time" value={form.work_start} onChange={(event) => setForm({ ...form, work_start: event.target.value })} className="h-10 rounded-lg" /><ErrorText errors={errors} field="work_start" /></label>
              <label className="grid gap-1.5"><span className="text-xs font-medium">Jam pulang *</span><Input type="time" value={form.work_end} onChange={(event) => setForm({ ...form, work_end: event.target.value })} className="h-10 rounded-lg" /><ErrorText errors={errors} field="work_end" /></label>
              <label className="grid gap-1.5"><span className="text-xs font-medium">Toleransi terlambat *</span><Input type="number" min={0} max={240} value={form.late_tolerance_minutes} onChange={(event) => setForm({ ...form, late_tolerance_minutes: Number(event.target.value) })} className="h-10 rounded-lg" /><span className="text-[10px] text-muted-foreground">Dalam menit</span><ErrorText errors={errors} field="late_tolerance_minutes" /></label>
              <label className="grid gap-1.5"><span className="text-xs font-medium">Lembur mulai setelah *</span><Input type="number" min={0} max={1440} value={form.overtime_starts_after_minutes} onChange={(event) => setForm({ ...form, overtime_starts_after_minutes: Number(event.target.value) })} className="h-10 rounded-lg" /><span className="text-[10px] text-muted-foreground">Menit setelah jam pulang</span><ErrorText errors={errors} field="overtime_starts_after_minutes" /></label>
              <label className="grid gap-1.5"><span className="text-xs font-medium">Maksimal lembur *</span><Input type="number" min={1} max={1440} value={form.overtime_max_minutes} onChange={(event) => setForm({ ...form, overtime_max_minutes: Number(event.target.value) })} className="h-10 rounded-lg" /><span className="text-[10px] text-muted-foreground">Dalam menit</span><ErrorText errors={errors} field="overtime_max_minutes" /></label>
            </div>

            <div className="mt-5 grid gap-3 rounded-xl border bg-muted/30 p-4 sm:grid-cols-3">
              <div><span className="text-[10px] text-muted-foreground">Jam kerja aktif</span><strong className="mt-1 block text-sm">{form.work_start}–{form.work_end}</strong></div>
              <div><span className="text-[10px] text-muted-foreground">Lembur mulai dihitung</span><strong className="mt-1 block text-sm">Pukul {overtimeStartsAt}</strong></div>
              <div><span className="text-[10px] text-muted-foreground">Berlaku sejak</span><strong className="mt-1 block text-sm">{effectiveFrom || "—"}</strong></div>
            </div>

            <div className="mt-5 flex items-center justify-between gap-3 border-t pt-4">
              <p className="flex items-center gap-2 text-[11px] text-muted-foreground"><TimerReset className="size-4 text-primary" />Durasi lembur dihitung per menit tanpa pembulatan.</p>
              <Button type="submit" className="rounded-lg" disabled={saving}><Save data-icon="inline-start" />{saving ? "Menyimpan..." : "Simpan pengaturan"}</Button>
            </div>
          </form>
        )}
      </CardContent>
    </Card>
  )
}
