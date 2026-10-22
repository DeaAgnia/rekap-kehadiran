export type Category = {
  id: number
  name: string
  group: "Tendik" | "Dosen" | "Pimpinan"
  active: boolean
  employees_count?: number
}

export type Employee = {
  id: number
  nid_nip: string
  name: string
  position: string
  machine_id: string
  employment_start_date: string
  attendance_type: "normal" | "mpp" | "stb"
  attendance_type_start_date: string | null
  attendance_type_end_date: string | null
  active: boolean
  categories: Category[]
}

export type AttendanceStatus =
  | "present"
  | "leave"
  | "duty"
  | "sick"
  | "permission"
  | "absent"

export type EffectiveAttendanceStatus = AttendanceStatus | "mpp" | "stb"

export type AttendanceRecord = {
  id: number
  employee_id: number
  attendance_date: string
  status: AttendanceStatus
  effective_status: EffectiveAttendanceStatus
  manual_status: AttendanceStatus | null
  check_in: string | null
  check_out: string | null
  source: "manual" | "fingerprint" | "mixed" | "system"
  incomplete: boolean
  note: string | null
  machine_location: string | null
  attachment: {
    name: string | null
    mime_type: string | null
    size: number | null
    url: string
  } | null
  employee: Employee
  metrics: {
    total_work_minutes: number | null
    late_minutes: number | null
    early_leave_minutes: number | null
    overtime_minutes: number | null
    is_weekend: boolean
    is_holiday: boolean
  }
}

export type WorkSchedule = {
  id: number
  name: string
  work_start: string
  work_end: string
  break_start: string
  break_end: string
  late_tolerance_minutes: number
  early_leave_tolerance_minutes: number
  effective_from: string
  effective_until: string | null
  active: boolean
}

export type OvertimeRule = {
  id: number
  name: string
  starts_after_minutes: number
  max_minutes: number
  effective_from: string
  effective_until: string | null
  active: boolean
}

export type Holiday = {
  id: number
  date: string
  name: string
  type: "internal" | "national"
  active: boolean
}

export type AttendanceSetting = {
  work_schedule_id: number
  overtime_rule_id: number
  work_start: string
  work_end: string
  late_tolerance_minutes: number
  overtime_starts_after_minutes: number
  overtime_max_minutes: number
  effective_from: string
}

export type ImportBatch = {
  id: number
  filename: string
  row_count: number
  imported_count: number
  skipped_count: number
  error_count: number
  status: "processing" | "completed" | "partial" | "failed"
  errors: Array<{ row: number; message: string }> | null
  created_at: string
}

export type ActivityLog = {
  id: number
  action: string
  entity_type: string | null
  entity_id: number | null
  status: "success" | "error"
  description: string
  actor: string
  ip_address: string | null
  occurred_at: string
}

export type Notify = (message: string, type?: "success" | "error" | "info") => void
