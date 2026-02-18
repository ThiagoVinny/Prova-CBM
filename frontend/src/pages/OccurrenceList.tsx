import { useEffect, useMemo, useState } from 'react'
import type { Occurrence, OccurrenceStatus, Paginated } from '../api/cbm'
import { integrationCreateOccurrence, listOccurrences } from '../api/cbm'
import { Badge } from '../components/Badge'
import { Button } from '../components/Button'
import { Card } from '../components/Card'
import { Modal } from '../components/Modal'

const TYPES = [
  { value: '', label: 'Todos os tipos' },
  { value: 'incendio_urbano', label: 'Incêndio Urbano' },
  { value: 'resgate_veicular', label: 'Resgate Veicular' },
  { value: 'atendimento_pre_hospitalar', label: 'Atendimento Pré-Hospitalar' },
  { value: 'salvamento_aquatico', label: 'Salvamento Aquático' },
  { value: 'falso_chamado', label: 'Falso Chamado' },
]

const STATUSES: { value: '' | OccurrenceStatus; label: string }[] = [
  { value: '', label: 'Todos os status' },
  { value: 'reported', label: 'Reportada' },
  { value: 'in_progress', label: 'Em atendimento' },
  { value: 'resolved', label: 'Encerrada' },
  { value: 'cancelled', label: 'Cancelada' },
]

export function OccurrenceList({
  onOpen,
  pushToast,
}: {
  onOpen: (id: string) => void
  pushToast: (msg: string) => void
}) {
  const [page, setPage] = useState(1)
  const [perPage] = useState(12)
  const [status, setStatus] = useState('')
  const [type, setType] = useState('')
  const [q, setQ] = useState('')

  const [data, setData] = useState<Paginated<Occurrence> | null>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState<string | null>(null)

  // Modal "Nova ocorrência" (usa endpoint de integração)
  const [openNew, setOpenNew] = useState(false)
  const [newExternalId, setNewExternalId] = useState(() => `EXT-${new Date().getFullYear()}-${Date.now()}`)
  const [newType, setNewType] = useState('incendio_urbano')
  const [newDesc, setNewDesc] = useState('')
  const [creating, setCreating] = useState(false)

  async function load(silent = false) {
    if (!silent) {
      setLoading(true)
      setErr(null)
    }

    try {
      const resp = await listOccurrences({ page, perPage, status: status || undefined, type: type || undefined, q })
      setData(resp)
    } catch (e: any) {
      setErr(e?.message ?? 'Erro ao carregar')
    } finally {
      if (!silent) setLoading(false)
    }
  }

  useEffect(() => {
    load()
    // refresh silencioso
    const t = setInterval(() => load(true), 6000)
    return () => clearInterval(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, perPage, status, type])

  const filtered = useMemo(() => {
    const items = data?.data ?? []
    const s = q.trim().toLowerCase()
    if (!s) return items

    return items.filter((o) =>
      `${o.externalId ?? ''} ${o.type} ${o.description ?? ''}`.toLowerCase().includes(s),
    )
  }, [data, q])

  async function createNewOccurrence() {
    setCreating(true)
    try {
      await integrationCreateOccurrence({
        externalId: newExternalId,
        type: newType,
        description: newDesc || undefined,
        reportedAt: new Date().toISOString(),
      })

      pushToast('Ocorrência enviada para registro')
      setOpenNew(false)
      setNewExternalId(`EXT-${new Date().getFullYear()}-${Date.now()}`)
      setNewDesc('')

      // depois de alguns segundos o worker vai processar; fazemos refresh silencioso
      setTimeout(() => load(true), 1200)
    } catch (e: any) {
      pushToast('Não foi possível criar a ocorrência')
      setErr(e?.message ?? 'Erro')
    } finally {
      setCreating(false)
    }
  }

  return (
    <div className="page">
      <div className="row">
        <div>
          <div className="h1">Ocorrências</div>
          <div className="muted">Visão operacional • ESPECTRALL</div>
        </div>
        <Button onClick={() => setOpenNew(true)}>Nova ocorrência</Button>
      </div>

      <div className="toolbar">
        <div className="controls">
          <input
            className="input"
            placeholder="Buscar por externalId, tipo ou descrição..."
            value={q}
            onChange={(e) => setQ(e.target.value)}
          />

          <select className="select" value={status} onChange={(e) => { setPage(1); setStatus(e.target.value) }}>
            {STATUSES.map((s) => (
              <option key={s.value} value={s.value}>{s.label}</option>
            ))}
          </select>

          <select className="select" value={type} onChange={(e) => { setPage(1); setType(e.target.value) }}>
            {TYPES.map((t) => (
              <option key={t.value} value={t.value}>{t.label}</option>
            ))}
          </select>
        </div>

        <div className="muted small">
          {data?.meta ? `Página ${data.meta.currentPage} de ${data.meta.lastPage}` : ''}
        </div>
      </div>

      {err ? <div className="error">{err}</div> : null}
      {loading ? <div className="muted" style={{ marginTop: 12 }}>Carregando…</div> : null}

      <div className="grid">
        {filtered.map((o) => (
          <Card key={o.id}>
            <div className="row">
              <div>
                <div className="title">{prettyType(o.type)}</div>
                <div className="muted small">
                  {o.externalId ? `Ext: ${o.externalId}` : 'Sem externalId'}
                  {' • '}
                  {o.reportedAt ? fmt(o.reportedAt) : 'Sem data'}
                </div>
              </div>
              <Badge status={o.status} />
            </div>

            <div className="desc">{o.description ?? '—'}</div>

            <button className="link" onClick={() => onOpen(o.id)}>
              Abrir detalhes →
            </button>
          </Card>
        ))}
      </div>

      {data?.meta ? (
        <div className="pagination">
          <Button variant="ghost" disabled={data.meta.currentPage <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>
            ← Anterior
          </Button>

          <div className="muted small">
            {data.meta.total} registro(s)
          </div>

          <Button variant="ghost" disabled={data.meta.currentPage >= data.meta.lastPage} onClick={() => setPage((p) => p + 1)}>
            Próxima →
          </Button>
        </div>
      ) : null}

      <Modal open={openNew} title="Nova ocorrência" onClose={() => setOpenNew(false)}>
        <div className="field">
          <label className="label">ExternalId</label>
          <input className="input" value={newExternalId} onChange={(e) => setNewExternalId(e.target.value)} />
        </div>

        <div className="field" style={{ marginTop: 12 }}>
          <label className="label">Tipo</label>
          <select className="select" value={newType} onChange={(e) => setNewType(e.target.value)}>
            {TYPES.filter((t) => t.value).map((t) => (
              <option key={t.value} value={t.value}>{t.label}</option>
            ))}
          </select>
        </div>

        <div className="field" style={{ marginTop: 12 }}>
          <label className="label">Descrição (opcional)</label>
          <input className="input" value={newDesc} onChange={(e) => setNewDesc(e.target.value)} placeholder="Ex.: Incêndio em residência" />
          <div className="muted small">A data de relato será a hora atual.</div>
        </div>

        <div className="modalActions">
          <Button onClick={createNewOccurrence} loading={creating}>Registrar</Button>
          <Button variant="ghost" onClick={() => setOpenNew(false)}>Cancelar</Button>
        </div>
      </Modal>
    </div>
  )
}

function fmt(iso: string) {
  try {
    return new Date(iso).toLocaleString('pt-BR')
  } catch {
    return iso
  }
}

function prettyType(type: string) {
  const map: Record<string, string> = {
    incendio_urbano: 'Incêndio Urbano',
    resgate_veicular: 'Resgate Veicular',
    atendimento_pre_hospitalar: 'Atendimento Pré-Hospitalar',
    salvamento_aquatico: 'Salvamento Aquático',
    falso_chamado: 'Falso Chamado',
  }
  return map[type] ?? type
}
