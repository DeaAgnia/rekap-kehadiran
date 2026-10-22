import type { Metadata } from "next"

import { LoginForm } from "./login-form"

export const metadata: Metadata = {
  title: "Login | Kehadiran FTM",
  description: "Login Admin SDM Fakultas Teknologi Manufaktur",
}

export default function LoginPage() {
  return <LoginForm />
}
