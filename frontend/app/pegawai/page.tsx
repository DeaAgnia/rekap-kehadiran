import type { Metadata } from "next"

import { AttendanceApp } from "@/components/attendance-app"

export const metadata: Metadata = { title: "Pegawai | Kehadiran FTM" }

export default function EmployeePage() {
  return <AttendanceApp />
}
