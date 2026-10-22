import type { Metadata } from "next"

import { AttendanceApp } from "@/components/attendance-app"

export const metadata: Metadata = { title: "Log Aktivitas | Kehadiran FTM" }

export default function ActivityLogPage() {
  return <AttendanceApp />
}
