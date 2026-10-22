import { cookies } from "next/headers"
import type { NextRequest } from "next/server"

import { SESSION_COOKIE_NAME, verifySessionToken } from "@/lib/auth"

type BackendRouteContext = {
  params: Promise<{ path: string[] }>
}

async function forward(request: NextRequest, context: BackendRouteContext) {
  const session = (await cookies()).get(SESSION_COOKIE_NAME)?.value
  if (!(await verifySessionToken(session))) {
    return Response.json({ message: "Sesi login tidak valid atau sudah berakhir." }, { status: 401 })
  }

  const apiBaseUrl = process.env.LARAVEL_API_URL
  const apiKey = process.env.LARAVEL_INTERNAL_API_KEY
  if (!apiBaseUrl || !apiKey) {
    return Response.json({ message: "Konfigurasi backend belum lengkap." }, { status: 500 })
  }

  const { path } = await context.params
  const targetUrl = new URL(`${apiBaseUrl.replace(/\/$/, "")}/${path.join("/")}`)
  request.nextUrl.searchParams.forEach((value, key) => targetUrl.searchParams.append(key, value))

  const headers = new Headers({
    Accept: "application/json",
    "X-Internal-Api-Key": apiKey,
  })
  const contentType = request.headers.get("content-type")
  if (contentType) headers.set("Content-Type", contentType)

  try {
    const backendResponse = await fetch(targetUrl, {
      method: request.method,
      headers,
      body: request.method === "GET" || request.method === "HEAD"
        ? undefined
        : await request.arrayBuffer(),
      cache: "no-store",
    })
    const responseHeaders = new Headers()
    const responseContentType = backendResponse.headers.get("content-type")
    if (responseContentType) responseHeaders.set("Content-Type", responseContentType)

    return new Response(backendResponse.body, {
      status: backendResponse.status,
      headers: responseHeaders,
    })
  } catch (error) {
    console.error("Laravel API connection failed", error)
    return Response.json(
      { message: "Backend Laravel tidak dapat dihubungi. Pastikan server backend sedang berjalan." },
      { status: 502 }
    )
  }
}

export const GET = forward
export const POST = forward
export const PUT = forward
export const PATCH = forward
export const DELETE = forward
