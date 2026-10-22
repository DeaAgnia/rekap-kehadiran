import type { Metadata } from "next"

import { AttendanceApp } from "@/components/attendance-app"

export const metadata: Metadata = { title: "Import Fingerprint | Kehadiran FTM" }

export default function FingerprintImportPage() {
  return <AttendanceApp />
}
