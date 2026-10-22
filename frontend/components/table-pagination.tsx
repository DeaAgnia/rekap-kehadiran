"use client"

import { ChevronLeft, ChevronRight } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"

const PAGE_SIZE_OPTIONS = [5, 10, 25, 50]

export function TablePagination({
  page,
  pageSize,
  total,
  onPageChange,
  onPageSizeChange,
}: {
  page: number
  pageSize: number
  total: number
  onPageChange: (page: number) => void
  onPageSizeChange: (pageSize: number) => void
}) {
  const totalPages = Math.max(1, Math.ceil(total / pageSize))
  const currentPage = Math.min(Math.max(page, 1), totalPages)
  const start = total === 0 ? 0 : (currentPage - 1) * pageSize + 1
  const end = Math.min(currentPage * pageSize, total)

  return (
    <div className="flex flex-col gap-3 border-t px-4 py-3 text-[11px] text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
      <div className="flex items-center gap-2">
        <span>Baris per halaman</span>
        <Select
          value={String(pageSize)}
          onValueChange={(value) => {
            onPageSizeChange(Number(value))
            onPageChange(1)
          }}
        >
          <SelectTrigger className="h-8 w-20 rounded-lg bg-background">
            <SelectValue>{pageSize}</SelectValue>
          </SelectTrigger>
          <SelectContent className="rounded-lg">
            {PAGE_SIZE_OPTIONS.map((option) => (
              <SelectItem key={option} value={String(option)} className="rounded-md">
                {option}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="flex items-center justify-between gap-3 sm:justify-end">
        <span className="tabular-nums">{start}–{end} dari {total} data</span>
        <div className="flex items-center gap-1">
          <Button
            type="button"
            variant="outline"
            size="icon-sm"
            className="rounded-md"
            disabled={currentPage <= 1}
            onClick={() => onPageChange(currentPage - 1)}
            aria-label="Halaman sebelumnya"
          >
            <ChevronLeft />
          </Button>
          <span className="min-w-16 text-center tabular-nums">{currentPage} / {totalPages}</span>
          <Button
            type="button"
            variant="outline"
            size="icon-sm"
            className="rounded-md"
            disabled={currentPage >= totalPages}
            onClick={() => onPageChange(currentPage + 1)}
            aria-label="Halaman berikutnya"
          >
            <ChevronRight />
          </Button>
        </div>
      </div>
    </div>
  )
}
