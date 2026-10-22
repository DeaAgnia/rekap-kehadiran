"use client"

import { type FormEvent, useState } from "react"
import { Eye, EyeOff, LoaderCircle, LockKeyhole, ShieldCheck } from "lucide-react"
import { useRouter } from "next/navigation"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"

export function LoginForm() {
  const router = useRouter()
  const [identity, setIdentity] = useState("")
  const [password, setPassword] = useState("")
  const [showPassword, setShowPassword] = useState(false)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [error, setError] = useState("")

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setError("")
    setIsSubmitting(true)

    try {
      const response = await fetch("/api/auth/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ identity, password }),
      })
      const result = (await response.json()) as { message?: string }

      if (!response.ok) {
        setError(result.message ?? "Login gagal. Silakan coba kembali.")
        return
      }

      router.replace("/dashboard")
      router.refresh()
    } catch {
      setError("Tidak dapat terhubung ke server. Silakan coba kembali.")
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <main className="grid min-h-screen bg-background lg:grid-cols-[minmax(340px,.85fr)_minmax(0,1.15fr)]">
      <section className="relative hidden overflow-hidden bg-primary p-10 text-primary-foreground lg:flex lg:flex-col lg:justify-between">
        <div className="absolute -top-28 -right-24 size-80 rounded-full border border-white/10" />
        <div className="absolute -right-20 -bottom-32 size-96 rounded-full bg-white/5" />

        <div className="relative flex items-center gap-3">
          <span className="grid size-11 place-items-center rounded-xl border border-white/20 bg-white/10">
            <ShieldCheck className="size-5" aria-hidden="true" />
          </span>
          <div>
            <p className="font-medium">Kehadiran FTM</p>
            <p className="mt-0.5 text-xs text-white/65">Administrasi SDM</p>
          </div>
        </div>

        <div className="relative max-w-md">
          <p className="text-sm text-white/65">Fakultas Teknologi Manufaktur</p>
          <h1 className="mt-3 text-4xl font-medium leading-tight tracking-tight">
            Rekap kehadiran yang rapi, aman, dan mudah diperiksa.
          </h1>
          <p className="mt-5 max-w-sm text-sm leading-6 text-white/70">
            Masuk sebagai Admin SDM untuk mengelola pegawai, memeriksa absensi, dan menyiapkan laporan bulanan.
          </p>
        </div>

        <p className="relative text-xs text-white/55">Sistem internal · Zona waktu Asia/Jakarta</p>
      </section>

      <section className="flex items-center justify-center p-5 sm:p-8">
        <div className="w-full max-w-md">
          <div className="mb-6 flex items-center gap-3 lg:hidden">
            <span className="grid size-10 place-items-center rounded-xl bg-primary text-primary-foreground">
              <ShieldCheck className="size-5" aria-hidden="true" />
            </span>
            <div><p className="text-sm font-medium">Kehadiran FTM</p><p className="text-xs text-muted-foreground">Administrasi SDM</p></div>
          </div>

          <Card className="gap-0 rounded-2xl border border-border/80 py-0 shadow-xl shadow-primary/5 ring-0">
            <CardHeader className="px-6 pt-7 pb-5">
              <div className="mb-4 grid size-10 place-items-center rounded-xl bg-primary/8 text-primary">
                <LockKeyhole className="size-5" aria-hidden="true" />
              </div>
              <CardTitle className="text-xl">Masuk ke sistem</CardTitle>
              <CardDescription>Gunakan username atau email akun Admin SDM.</CardDescription>
            </CardHeader>
            <CardContent className="px-6 pb-7">
              <form onSubmit={handleSubmit} className="grid gap-4" noValidate>
                <label className="grid gap-1.5">
                  <span className="text-xs font-medium">Username atau email</span>
                  <Input
                    value={identity}
                    onChange={(event) => setIdentity(event.target.value)}
                    autoComplete="username"
                    placeholder="Masukkan username atau email"
                    className="h-10 rounded-lg bg-background px-3"
                    disabled={isSubmitting}
                    required
                  />
                </label>

                <label className="grid gap-1.5">
                  <span className="text-xs font-medium">Password</span>
                  <span className="relative block">
                    <Input
                      type={showPassword ? "text" : "password"}
                      value={password}
                      onChange={(event) => setPassword(event.target.value)}
                      autoComplete="current-password"
                      placeholder="Masukkan password"
                      className="h-10 rounded-lg bg-background px-3 pr-11"
                      disabled={isSubmitting}
                      required
                    />
                    <button
                      type="button"
                      aria-label={showPassword ? "Sembunyikan password" : "Tampilkan password"}
                      onClick={() => setShowPassword((value) => !value)}
                      className="absolute top-1/2 right-1 grid size-8 -translate-y-1/2 place-items-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                    >
                      {showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                    </button>
                  </span>
                </label>

                {error ? (
                  <p role="alert" className="rounded-lg border border-destructive/20 bg-destructive/8 px-3 py-2.5 text-xs text-destructive">
                    {error}
                  </p>
                ) : null}

                <Button type="submit" className="mt-1 h-10 rounded-lg" disabled={isSubmitting}>
                  {isSubmitting ? <LoaderCircle className="size-4 animate-spin" aria-hidden="true" /> : null}
                  {isSubmitting ? "Memeriksa akun..." : "Masuk"}
                </Button>
              </form>
            </CardContent>
          </Card>

          <p className="mt-5 text-center text-[11px] text-muted-foreground">
            Akses hanya untuk pegawai SDM yang berwenang.
          </p>
        </div>
      </section>
    </main>
  )
}
