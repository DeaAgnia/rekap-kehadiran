"use client"

import { useCallback, useEffect, useState } from "react"
import { RefreshCw } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { TablePagination } from "@/components/table-pagination"
import { apiRequest } from "@/lib/api"
import type { ActivityLog, Notify } from "@/lib/admin-types"

export function ActivityLogView({ notify }: { notify: Notify }) {
  const [logs, setLogs] = useState<ActivityLog[]>([])
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(10)

  const currentPage = Math.min(page, Math.max(1, Math.ceil(logs.length / pageSize)))
  const paginatedLogs = logs.slice((currentPage - 1) * pageSize, currentPage * pageSize)

  const loadLogs = useCallback(async () => {
    setLoading(true)
    try {
      const response = await apiRequest<{ data: ActivityLog[] }>("activity-logs?limit=200")
      setLogs(response.data)
    } catch (error) {
      notify(error instanceof Error ? error.message : "Log aktivitas gagal dimuat.", "error")
    } finally {
      setLoading(false)
    }
  }, [notify])

  useEffect(() => {
    const timer = window.setTimeout(() => void loadLogs(), 0)
    return () => window.clearTimeout(timer)
  }, [loadLogs])

  return (
    <div className="mx-auto w-full max-w-[1500px] p-4 sm:p-6 lg:p-7">
      <header className="flex items-start justify-between gap-4"><div><h1 className="text-2xl font-medium tracking-tight">Log aktivitas</h1><p className="mt-1 text-xs text-muted-foreground">Riwayat tindakan berhasil dan kegagalan validasi pada aplikasi.</p></div><Button type="button" variant="outline" className="rounded-lg" onClick={() => void loadLogs()}><RefreshCw data-icon="inline-start" />Muat ulang</Button></header>
      <Card className="mt-5 gap-0 rounded-xl border border-border/80 py-0 shadow-sm ring-0"><CardHeader className="border-b px-4 py-4"><CardTitle>Aktivitas terbaru</CardTitle><CardDescription>{logs.length} aktivitas ditampilkan</CardDescription></CardHeader><CardContent className="p-0"><Table><TableHeader><TableRow><TableHead>Waktu</TableHead><TableHead>Status</TableHead><TableHead>Aktivitas</TableHead><TableHead>Keterangan</TableHead><TableHead>Aktor</TableHead><TableHead>IP</TableHead></TableRow></TableHeader><TableBody>{loading ? <TableRow><TableCell colSpan={6} className="h-28 text-center text-muted-foreground">Memuat log...</TableCell></TableRow> : logs.length === 0 ? <TableRow><TableCell colSpan={6} className="h-28 text-center text-muted-foreground">Belum ada aktivitas.</TableCell></TableRow> : paginatedLogs.map((log) => <TableRow key={log.id}><TableCell>{new Date(log.occurred_at).toLocaleString("id-ID")}</TableCell><TableCell><Badge variant={log.status === "success" ? "secondary" : "destructive"} className="rounded-full">{log.status === "success" ? "Berhasil" : "Error"}</Badge></TableCell><TableCell className="font-medium">{log.action}</TableCell><TableCell>{log.description}</TableCell><TableCell>{log.actor}</TableCell><TableCell>{log.ip_address ?? "—"}</TableCell></TableRow>)}</TableBody></Table><TablePagination page={currentPage} pageSize={pageSize} total={logs.length} onPageChange={setPage} onPageSizeChange={setPageSize} /></CardContent></Card>
    </div>
  )
}
