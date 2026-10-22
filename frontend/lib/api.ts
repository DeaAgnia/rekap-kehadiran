export type ApiValidationErrors = Record<string, string[]>

export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly errors: ApiValidationErrors = {}
  ) {
    super(message)
    this.name = "ApiError"
  }
}

type ApiOptions = Omit<RequestInit, "body"> & {
  body?: BodyInit | Record<string, unknown>
}

export async function apiRequest<T>(path: string, options: ApiOptions = {}): Promise<T> {
  const headers = new Headers(options.headers)
  let body = options.body

  if (body && !(body instanceof FormData) && typeof body !== "string") {
    headers.set("Content-Type", "application/json")
    body = JSON.stringify(body)
  }

  const response = await fetch(`/api/backend/${path.replace(/^\//, "")}`, {
    ...options,
    headers,
    body: body as BodyInit | undefined,
  })
  const payload = await response.json().catch(() => ({})) as {
    message?: string
    errors?: ApiValidationErrors
  }

  if (!response.ok) {
    throw new ApiError(
      payload.message ?? "Permintaan gagal diproses.",
      response.status,
      payload.errors ?? {}
    )
  }

  return payload as T
}

export function firstValidationError(errors: ApiValidationErrors, field: string) {
  return errors[field]?.[0]
}
