import type { Metadata } from "next"

import { AttendanceApp } from "@/components/attendance-app"

export const metadata: Metadata = {
  title: "Rekap Kehadiran | Kehadiran FTM",
}

export default function AttendanceRecapPage() {
  return <AttendanceApp />
}
