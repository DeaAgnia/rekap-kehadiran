import type { Metadata } from "next"

import { AttendanceApp } from "@/components/attendance-app"

export const metadata: Metadata = { title: "Data Master | Kehadiran FTM" }

export default function MasterDataPage() {
  return <AttendanceApp />
}
