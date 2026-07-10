// Thin wrapper around $fetch for talking to the Laravel API with Sanctum
// cookie auth: always sends credentials and mirrors the XSRF-TOKEN cookie
// into the X-XSRF-TOKEN header.
import type { NitroFetchOptions, NitroFetchRequest } from 'nitropack'

export function apiFetch<T>(
  path: string,
  options: NitroFetchOptions<NitroFetchRequest> = {},
): Promise<T> {
  const { apiBase } = useRuntimeConfig().public

  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...(options.headers as Record<string, string> | undefined),
  }

  const xsrf = getXsrfToken()
  if (xsrf) headers['X-XSRF-TOKEN'] = xsrf

  return $fetch<T>(path, {
    baseURL: apiBase as string,
    credentials: 'include',
    ...options,
    headers,
  }) as Promise<T>
}

function getXsrfToken(): string | null {
  if (typeof document === 'undefined') return null
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  return match?.[1] ? decodeURIComponent(match[1]) : null
}
