import { NextResponse, type NextRequest } from "next/server"

import {
  createSessionToken,
  SESSION_COOKIE_NAME,
  SESSION_MAX_AGE_SECONDS,
  verifyAdminCredentials,
} from "@/lib/auth"

export async function POST(request: NextRequest) {
  try {
    const body = (await request.json()) as { identity?: unknown; password?: unknown }
    const identity = typeof body.identity === "string" ? body.identity.trim() : ""
    const password = typeof body.password === "string" ? body.password : ""

    if (!identity || !password || identity.length > 120 || password.length > 200) {
      return NextResponse.json(
        { message: "Username/email dan password wajib diisi." },
        { status: 400 }
      )
    }

    const credentialsAreValid = await verifyAdminCredentials(identity, password)
    if (!credentialsAreValid) {
      return NextResponse.json(
        { message: "Username/email atau password tidak sesuai." },
        { status: 401 }
      )
    }

    const response = NextResponse.json({ success: true })
    response.cookies.set({
      name: SESSION_COOKIE_NAME,
      value: await createSessionToken(),
      httpOnly: true,
      secure: process.env.NODE_ENV === "production",
      sameSite: "lax",
      maxAge: SESSION_MAX_AGE_SECONDS,
      path: "/",
      priority: "high",
    })

    return response
  } catch (error) {
    console.error("Login configuration error", error)
    return NextResponse.json(
      { message: "Login belum dapat diproses. Periksa konfigurasi server." },
      { status: 500 }
    )
  }
}
