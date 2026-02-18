import { api, makeIdempotencyKey } from './client'

export type OccurrenceStatus = 'reported' | 'in_progress' | 'resolved' | 'cancelled'
export type DispatchStatus = 'assigned' | 'en_route' | 'on_site' | 'closed'

export type Dispatch = {
  id: string
  resourceCode: string
  status: DispatchStatus
  createdAt?: string | null
  updatedAt?: string | null
}

export type Occurrence = {
  id: string
  externalId: string | null
  type: string
  status: OccurrenceStatus
  description: string | null
  reportedAt: string | null
  updatedAt?: string | null
}

export type Paginated<T> = {
  data: T[]
  meta: {
    currentPage: number
    perPage: number
    total: number
    lastPage: number
    from: number | null
    to: number | null
  }
  links: { next: string | null; prev: string | null }
}

export async function listOccurrences(params: { page: number; perPage: number; status?: string; type?: string; q?: string }): Promise<Paginated<Occurrence>> {
  const qs = new URLSearchParams()
  qs.set('page', String(params.page))
  qs.set('perPage', String(params.perPage))
  if (params.status) qs.set('status', params.status)
  if (params.type) qs.set('type', params.type)
  // backend não tem q=, então filtramos no front (q fica aqui só pra assinatura)

  return api<Paginated<Occurrence>>(`/occurrences?${qs.toString()}`)
}

export async function getOccurrence(id: string): Promise<Occurrence & { dispatches: Dispatch[] }> {
  return api<Occurrence & { dispatches: Dispatch[] }>(`/occurrences/${id}`)
}

export async function integrationCreateOccurrence(payload: { externalId: string; type: string; description?: string; reportedAt?: string }): Promise<{ commandId: string; status: 'accepted' }> {
  const idem = makeIdempotencyKey('INT')
  return api<{ commandId: string; status: 'accepted' }>(`/integrations/occurrences`, {
    method: 'POST',
    headers: { 'Idempotency-Key': idem },
    body: JSON.stringify(payload),
  })
}

export async function startOccurrence(id: string): Promise<{ commandId: string }>{
  const idem = makeIdempotencyKey('START')
  return api<{ commandId: string }>(`/occurrences/${id}/start`, { method: 'POST', headers: { 'Idempotency-Key': idem } })
}

export async function resolveOccurrence(id: string): Promise<{ commandId: string }>{
  const idem = makeIdempotencyKey('RESOLVE')
  return api<{ commandId: string }>(`/occurrences/${id}/resolve`, { method: 'POST', headers: { 'Idempotency-Key': idem } })
}

export async function cancelOccurrence(id: string): Promise<{ commandId: string }>{
  const idem = makeIdempotencyKey('CANCEL')
  return api<{ commandId: string }>(`/occurrences/${id}/status`, {
    method: 'PATCH',
    headers: { 'Idempotency-Key': idem },
    body: JSON.stringify({ status: 'cancelled' }),
  })
}

export async function createDispatch(occurrenceId: string, resourceCode: string): Promise<{ commandId: string }>{
  const idem = makeIdempotencyKey('DISPATCH')
  return api<{ commandId: string }>(`/occurrences/${occurrenceId}/dispatches`, {
    method: 'POST',
    headers: { 'Idempotency-Key': idem },
    body: JSON.stringify({ resourceCode }),
  })
}

export async function updateDispatchStatus(dispatchId: string, status: DispatchStatus): Promise<{ commandId: string }>{
  const idem = makeIdempotencyKey('DSTATUS')
  return api<{ commandId: string }>(`/dispatches/${dispatchId}/status`, {
    method: 'PATCH',
    headers: { 'Idempotency-Key': idem },
    body: JSON.stringify({ status }),
  })
}
