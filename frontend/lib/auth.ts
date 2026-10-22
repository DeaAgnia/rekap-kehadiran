const encoder = new TextEncoder()
const decoder = new TextDecoder()

export const SESSION_COOKIE_NAME = "ftm_admin_session"
export const SESSION_MAX_AGE_SECONDS = 60 * 60 * 8

type SessionPayload = {
  sub: "admin-sdm"
  role: "admin"
  exp: number
}

function bytesToBase64Url(bytes: Uint8Array) {
  const binary = Array.from(bytes, (byte) => String.fromCharCode(byte)).join("")
  return btoa(binary).replaceAll("+", "-").replaceAll("/", "_").replaceAll("=", "")
}

function base64UrlToBytes(value: string) {
  const base64 = value.replaceAll("-", "+").replaceAll("_", "/")
  const padded = base64.padEnd(Math.ceil(base64.length / 4) * 4, "=")
  return Uint8Array.from(atob(padded), (character) => character.charCodeAt(0))
}

async function createHmacKey(secret: string) {
  return crypto.subtle.importKey(
    "raw",
    encoder.encode(secret),
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["sign", "verify"]
  )
}

async function hashPassword(password: string) {
  const digest = await crypto.subtle.digest("SHA-256", encoder.encode(password))
  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, "0")).join("")
}

function constantTimeEqual(left: string, right: string) {
  const length = Math.max(left.length, right.length)
  let difference = left.length ^ right.length

  for (let index = 0; index < length; index += 1) {
    difference |= (left.charCodeAt(index) || 0) ^ (right.charCodeAt(index) || 0)
  }

  return difference === 0
}

function getSessionSecret() {
  const secret = process.env.FTM_SESSION_SECRET
  if (!secret || secret.length < 32) {
    throw new Error("FTM_SESSION_SECRET belum dikonfigurasi dengan benar.")
  }
  return secret
}

export async function verifyAdminCredentials(identity: string, password: string) {
  const configuredUsername = process.env.FTM_ADMIN_USERNAME
  const configuredEmail = process.env.FTM_ADMIN_EMAIL
  const configuredPasswordHash = process.env.FTM_ADMIN_PASSWORD_HASH

  if (!configuredUsername || !configuredEmail || !configuredPasswordHash) {
    throw new Error("Konfigurasi akun admin belum lengkap.")
  }

  const normalizedIdentity = identity.trim().toLowerCase()
  const identityMatches =
    constantTimeEqual(normalizedIdentity, configuredUsername.toLowerCase()) ||
    constantTimeEqual(normalizedIdentity, configuredEmail.toLowerCase())
  const submittedPasswordHash = await hashPassword(password)

  return identityMatches && constantTimeEqual(submittedPasswordHash, configuredPasswordHash)
}

/** Membuat sesi stateless yang ditandatangani. Password tidak pernah dimasukkan ke payload. */
export async function createSessionToken() {
  const payload: SessionPayload = {
    sub: "admin-sdm",
    role: "admin",
    exp: Math.floor(Date.now() / 1000) + SESSION_MAX_AGE_SECONDS,
  }
  const encodedPayload = bytesToBase64Url(encoder.encode(JSON.stringify(payload)))
  const key = await createHmacKey(getSessionSecret())
  const signature = await crypto.subtle.sign("HMAC", key, encoder.encode(encodedPayload))

  return `${encodedPayload}.${bytesToBase64Url(new Uint8Array(signature))}`
}

/** Memverifikasi tanda tangan dan masa berlaku token untuk Proxy maupun Route Handler. */
export async function verifySessionToken(token: string | undefined) {
  if (!token) return false

  try {
    const [encodedPayload, encodedSignature, extraPart] = token.split(".")
    if (!encodedPayload || !encodedSignature || extraPart) return false

    const key = await createHmacKey(getSessionSecret())
    const signatureIsValid = await crypto.subtle.verify(
      "HMAC",
      key,
      base64UrlToBytes(encodedSignature),
      encoder.encode(encodedPayload)
    )
    if (!signatureIsValid) return false

    const payload = JSON.parse(decoder.decode(base64UrlToBytes(encodedPayload))) as SessionPayload
    return (
      payload.sub === "admin-sdm" &&
      payload.role === "admin" &&
      Number.isFinite(payload.exp) &&
      payload.exp > Math.floor(Date.now() / 1000)
    )
  } catch {
    return false
  }
}
