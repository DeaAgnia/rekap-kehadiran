import type { Metadata } from "next"

import { AttendanceApp } from "@/components/attendance-app"

export const metadata: Metadata = {
  title: "Dashboard | Kehadiran FTM",
}

export default function DashboardPage() {
  return <AttendanceApp />
}
