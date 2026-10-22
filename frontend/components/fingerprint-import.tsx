"use client"

import { useCallback, useEffect, useState } from "react"
import { AlertCircle, Download, FileText, Upload } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { TablePagination } from "@/components/table-pagination"
import { ApiError, apiRequest } from "@/lib/api"
import type { ImportBatch, Notify } from "@/lib/admin-types"

const EXPECTED_HEADER = "ID,NAMA,TANGGAL,STATUS,STAT NAMA,EVALUASI,SUHU,JAM KERJA,MESIN,VERIFY"

type Preview = {
  rows: number
  ids: number
  valid: boolean
  message: string
}

export function FingerprintImport({ notify }: { notify: Notify }) {
  const [file, setFile] = useState<File | null>(null)
  const [preview, setPreview] = useState<Preview | null>(null)
  const [history, setHistory] = useState<ImportBatch[]>([])
  const [uploading, setUploading] = useState(false)
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(10)

  const currentPage = Math.min(page, Math.max(1, Math.ceil(history.length / pageSize)))
  const paginatedHistory = history.slice((currentPage - 1) * pageSize, currentPage * pageSize)

  const loadHistory = useCallback(async () => {
    try {
      const response = await apiRequest<{ data: ImportBatch[] }>("fingerprint-imports")
      setHistory(response.data)
    } catch (error) {
      notify(error instanceof Error ? error.message : "Riwayat import gagal dimuat.", "error")
    }
  }, [notify])

  useEffect(() => {
    const timer = window.setTimeout(() => void loadHistory(), 0)
    return () => window.clearTimeout(timer)
  }, [loadHistory])

  const inspectFile = async (selectedFile: File | null) => {
    setFile(selectedFile)
    setPreview(null)
    if (!selectedFile) return
    if (!selectedFile.name.toLowerCase().endsWith(".csv")) {
      const result = { rows: 0, ids: 0, valid: false, message: "File harus menggunakan format CSV." }
      setPreview(result)
      notify(result.message, "error")
      return
    }
    if (selectedFile.size > 10 * 1024 * 1024) {
      const result = { rows: 0, ids: 0, valid: false, message: "Ukuran file maksimal 10 MB." }
      setPreview(result)
      notify(result.message, "error")
      return
    }

    const text = await selectedFile.text()
    const lines = text.replace(/^\uFEFF/, "").split(/\r?\n/).filter((line) => line.trim())
    const headerValid = lines[0]?.trim().toUpperCase() === EXPECTED_HEADER
    const ids = new Set(lines.slice(1).map((line) => line.split(",")[0]?.trim()).filter(Boolean))
    const result = {
      rows: Math.max(0, lines.length - 1),
      ids: ids.size,
      valid: headerValid && lines.length > 1,
      message: headerValid ? (lines.length > 1 ? "File siap diimport." : "File tidak memiliki baris data.") : "Header CSV tidak sesuai format mesin fingerprint.",
    }
    setPreview(result)
    notify(result.message, result.valid ? "info" : "error")
  }

  const uploadFile = async () => {
    if (!file || !preview?.valid) {
      notify("Pilih file CSV yang valid terlebih dahulu.", "error")
      return
    }

    setUploading(true)
    try {
      const formData = new FormData()
      formData.append("file", file)
      const response = await apiRequest<{ message: string; data: ImportBatch }>("fingerprint-imports", { method: "POST", body: formData })
      notify(`${response.message} ${response.data.imported_count} scan masuk, ${response.data.skipped_count} dilewati, ${response.data.error_count} error.`, response.data.error_count > 0 ? "info" : "success")
      setFile(null)
      setPreview(null)
      await loadHistory()
    } catch (error) {
      notify(error instanceof ApiError ? error.message : "Import fingerprint gagal.", "error")
    } finally {
      setUploading(false)
    }
  }

  const downloadTemplate = () => {
    const blob = new Blob([`${EXPECTED_HEADER}\r\n10,10,01/09/2026 07:45:00,0,Masuk,No Jadwal,0,,SERIAL-MESIN,1\r\n`], { type: "text/csv;charset=utf-8" })
    const url = URL.createObjectURL(blob)
    const anchor = document.createElement("a")
    anchor.href = url
    anchor.download = "template-import-fingerprint.csv"
    anchor.click()
    URL.revokeObjectURL(url)
    notify("Template CSV berhasil diunduh.", "success")
  }

  return (
    <div className="mx-auto w-full max-w-[1300px] p-4 sm:p-6 lg:p-7">
      <header><h1 className="text-2xl font-medium tracking-tight">Import fingerprint</h1><p className="mt-1 text-xs text-muted-foreground">Import log scan mesin dan gabungkan scan pertama/terakhir menjadi kehadiran harian.</p></header>

      <div className="mt-5 grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
        <Card className="gap-0 rounded-xl border border-border/80 py-0 shadow-sm ring-0">
          <CardHeader className="border-b px-5 py-5"><CardTitle>Pilih file CSV</CardTitle><CardDescription>Kolom harus sama dengan file keluaran mesin fingerprint.</CardDescription></CardHeader>
          <CardContent className="px-5 py-5">
            <label className="grid min-h-44 place-items-center rounded-xl border border-dashed border-primary/35 bg-primary/3 p-6 text-center">
              <span><Upload className="mx-auto size-7 text-primary" /><span className="mt-3 block text-sm font-medium">Pilih file fingerprint</span><span className="mt-1 block text-xs text-muted-foreground">CSV, maksimal 10 MB</span><Input type="file" accept=".csv,text/csv" className="mt-4 h-9 max-w-sm rounded-lg bg-card" onChange={(event) => void inspectFile(event.target.files?.[0] ?? null)} /></span>
            </label>

            {preview ? <div className="mt-4 grid gap-3 rounded-xl border bg-muted/30 p-4 sm:grid-cols-3"><div><span className="text-[10px] text-muted-foreground">Baris data</span><strong className="mt-1 block text-sm">{preview.rows}</strong></div><div><span className="text-[10px] text-muted-foreground">ID Mesin unik</span><strong className="mt-1 block text-sm">{preview.ids}</strong></div><div><span className="text-[10px] text-muted-foreground">Validasi</span><strong className={preview.valid ? "mt-1 block text-sm text-emerald-700" : "mt-1 block text-sm text-destructive"}>{preview.message}</strong></div></div> : null}

            <div className="mt-4 flex flex-wrap gap-2"><Button type="button" className="rounded-lg" disabled={!preview?.valid || uploading} onClick={() => void uploadFile()}>{uploading ? "Mengimport..." : "Import file"}</Button><Button type="button" variant="outline" className="rounded-lg" onClick={downloadTemplate}><Download data-icon="inline-start" />Unduh template</Button></div>
          </CardContent>
        </Card>

        <Card className="gap-0 rounded-xl border border-border/80 py-0 shadow-sm ring-0">
          <CardHeader className="border-b px-4 py-4"><CardTitle>Aturan import</CardTitle><CardDescription>Data diproses tanpa menghapus scan lama.</CardDescription></CardHeader>
          <CardContent className="grid gap-3 px-4 py-4 text-xs text-muted-foreground">
            <p className="flex gap-2"><FileText className="mt-0.5 size-4 shrink-0 text-primary" />Scan pertama menjadi jam masuk dan scan terakhir menjadi jam pulang.</p>
            <p className="flex gap-2"><AlertCircle className="mt-0.5 size-4 shrink-0 text-primary" />Satu scan ditandai data tidak lengkap dan tidak menghitung total jam/lembur.</p>
            <p className="flex gap-2"><AlertCircle className="mt-0.5 size-4 shrink-0 text-primary" />Scan duplikat dilewati berdasarkan pegawai, waktu scan, dan mesin.</p>
          </CardContent>
        </Card>
      </div>

      <Card className="mt-4 gap-0 rounded-xl border border-border/80 py-0 shadow-sm ring-0">
        <CardHeader className="border-b px-4 py-4"><CardTitle>Riwayat import</CardTitle><CardDescription>50 proses import terakhir</CardDescription></CardHeader>
        <CardContent className="p-0"><Table><TableHeader><TableRow><TableHead>File</TableHead><TableHead>Waktu</TableHead><TableHead>Baris</TableHead><TableHead>Masuk</TableHead><TableHead>Dilewati</TableHead><TableHead>Error</TableHead><TableHead>Status</TableHead></TableRow></TableHeader><TableBody>{history.length === 0 ? <TableRow><TableCell colSpan={7} className="h-24 text-center text-muted-foreground">Belum ada riwayat import.</TableCell></TableRow> : paginatedHistory.map((batch) => <TableRow key={batch.id}><TableCell className="font-medium">{batch.filename}</TableCell><TableCell>{new Date(batch.created_at).toLocaleString("id-ID")}</TableCell><TableCell>{batch.row_count}</TableCell><TableCell>{batch.imported_count}</TableCell><TableCell>{batch.skipped_count}</TableCell><TableCell>{batch.error_count}</TableCell><TableCell><Badge variant={batch.status === "completed" ? "secondary" : "outline"} className="rounded-full">{batch.status}</Badge></TableCell></TableRow>)}</TableBody></Table><TablePagination page={currentPage} pageSize={pageSize} total={history.length} onPageChange={setPage} onPageSizeChange={setPageSize} /></CardContent>
      </Card>
    </div>
  )
}
