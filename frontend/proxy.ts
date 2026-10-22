import { NextResponse, type NextRequest } from "next/server"

import { SESSION_COOKIE_NAME, verifySessionToken } from "@/lib/auth"

const protectedPaths = [
  "/dashboard",
  "/pegawai",
  "/rekap-kehadiran",
  "/import-fingerprint",
  "/data-master",
  "/log-aktivitas",
]

export async function proxy(request: NextRequest) {
  const pathname = request.nextUrl.pathname
  const sessionToken = request.cookies.get(SESSION_COOKIE_NAME)?.value
  const hasValidSession = await verifySessionToken(sessionToken)
  const isProtectedPath =
    pathname === "/" ||
    protectedPaths.some((path) => pathname === path || pathname.startsWith(`${path}/`))

  if (isProtectedPath && !hasValidSession) {
    const loginUrl = new URL("/login", request.url)
    loginUrl.searchParams.set("next", pathname)
    return NextResponse.redirect(loginUrl)
  }

  if (pathname === "/login" && hasValidSession) {
    return NextResponse.redirect(new URL("/dashboard", request.url))
  }

  return NextResponse.next()
}

export const config = {
  matcher: [
    "/",
    "/login",
    "/dashboard/:path*",
    "/pegawai/:path*",
    "/rekap-kehadiran/:path*",
    "/import-fingerprint/:path*",
    "/data-master/:path*",
    "/log-aktivitas/:path*",
  ],
}
