const LS_KEY = 'ESPECTRALL_API_KEY'
const LS_BASE = 'ESPECTRALL_API_BASE'

export function getApiBase(): string {
  return (import.meta.env.VITE_API_BASE as string | undefined)
    ?? localStorage.getItem(LS_BASE)
    ?? 'http://localhost:8000/api'
}

export function getApiKey(): string | null {
  return (import.meta.env.VITE_API_KEY as string | undefined)
    ?? localStorage.getItem(LS_KEY)
    ?? null
}

export function saveApiSettings(base: string, key: string) {
  localStorage.setItem(LS_BASE, base)
  localStorage.setItem(LS_KEY, key)
}

export function clearApiSettings() {
  localStorage.removeItem(LS_BASE)
  localStorage.removeItem(LS_KEY)
}

function buildHeaders(extra?: HeadersInit): HeadersInit {
  const apiKey = getApiKey()
  return {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    ...(apiKey ? { 'X-API-Key': apiKey } : {}),
    ...(extra ?? {}),
  }
}

export async function api<T>(path: string, init?: RequestInit): Promise<T> {
  const base = getApiBase()
  const res = await fetch(`${base}${path}`, {
    ...init,
    headers: buildHeaders(init?.headers),
  })

  if (!res.ok) {
    const txt = await res.text().catch(() => '')
    throw new Error(txt || `HTTP ${res.status}`)
  }

  if (res.status === 204) return undefined as T
  return (await res.json()) as T
}

export function makeIdempotencyKey(prefix = 'ESPECTRALL'): string {
  // crypto.randomUUID existe no Chrome/Edge
  const uuid = (globalThis.crypto && 'randomUUID' in globalThis.crypto)
    ? (globalThis.crypto as any).randomUUID()
    : `${Date.now()}-${Math.random().toString(16).slice(2)}`

  return `${prefix}-${uuid}`
}

export async function waitCommand(commandId: string, opts?: { timeoutMs?: number; intervalMs?: number }): Promise<'processed'|'failed'|'pending'> {
  const timeoutMs = opts?.timeoutMs ?? 9000
  const intervalMs = opts?.intervalMs ?? 600
  const started = Date.now()

  while (Date.now() - started < timeoutMs) {
    const cmd = await api<{ status: 'pending'|'processed'|'failed' }>(`/commands/${commandId}`)
    if (cmd.status === 'processed' || cmd.status === 'failed') return cmd.status
    await new Promise((r) => setTimeout(r, intervalMs))
  }

  return 'pending'
}
