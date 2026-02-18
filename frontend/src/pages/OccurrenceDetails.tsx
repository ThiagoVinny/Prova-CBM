import { useEffect, useMemo, useState } from 'react'
import type { Dispatch, DispatchStatus, OccurrenceStatus } from '../api/cbm'
import { cancelOccurrence, createDispatch, getOccurrence, resolveOccurrence, startOccurrence, updateDispatchStatus } from '../api/cbm'
import { waitCommand } from '../api/client'
import { Badge } from '../components/Badge'
import { Button } from '../components/Button'
import { Card } from '../components/Card'
import { Modal } from '../components/Modal'

const DISPATCH_STATUSES: { value: DispatchStatus; label: string }[] = [
  { value: 'assigned', label: 'Designado' },
  { value: 'en_route', label: 'A caminho' },
  { value: 'on_site', label: 'No local' },
  { value: 'closed', label: 'Finalizado' },
]

export function OccurrenceDetails({
  id,
  onBack,
  pushToast,
}: {
  id: string
  onBack: () => void
  pushToast: (msg: string) => void
}) {
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState<string | null>(null)
  const [occ, setOcc] = useState<any>(null)

  const [openDispatch, setOpenDispatch] = useState(false)
  const [resourceCode, setResourceCode] = useState('ABT-12')

  const [busy, setBusy] = useState<string | null>(null)

  async function load(silent = false) {
    if (!silent) {
      setLoading(true)
      setErr(null)
    }

    try {
      const o = await getOccurrence(id)
      setOcc(o)
    } catch (e: any) {
      setErr(e?.message ?? 'Erro ao carregar')
    } finally {
      if (!silent) setLoading(false)
    }
  }

  useEffect(() => {
    load()
    const t = setInterval(() => load(true), 6000)
    return () => clearInterval(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  const status: OccurrenceStatus | null = occ?.status ?? null
  const canStart = status === 'reported'
  const canResolve = status === 'in_progress'
  const canCancel = status !== 'resolved' && status !== 'cancelled'

  async function runAction(label: string, fn: () => Promise<{ commandId: string }>) {
    setBusy(label)
    setErr(null)

    try {
      const { commandId } = await fn()
      // Não mostramos commandId: só aguardamos e atualizamos
      const result = await waitCommand(commandId)

      if (result === 'failed') {
        pushToast('Ação falhou. Verifique o backend/worker.')
      } else {
        pushToast('Atualizado')
      }

      await load(true)
    } catch (e: any) {
      setErr(e?.message ?? 'Erro')
      pushToast('Erro ao executar ação')
    } finally {
      setBusy(null)
    }
  }

  async function onCreateDispatch() {
    await runAction('dispatch', () => createDispatch(id, resourceCode))
    setOpenDispatch(false)
  }

  if (loading) {
    return (
      <div className="page">
        <div className="muted">Carregando…</div>
      </div>
    )
  }

  if (err) {
    return (
      <div className="page">
        <div className="topbar">
          <Button variant="ghost" onClick={onBack}>← Voltar</Button>
        </div>
        <div className="error">{err}</div>
      </div>
    )
  }

  if (!occ) return null

  const dispatches: Dispatch[] = occ.dispatches ?? []

  return (
    <div className="page">
      <div className="topbar">
        <Button variant="ghost" onClick={onBack}>← Voltar</Button>
        <div className="muted small">Detalhes da ocorrência</div>
      </div>

      <div className="headerLine">
        <div>
          <div className="h1">{prettyType(occ.type)}</div>
          <div className="muted small">
            {occ.externalId ? `ID Externo: ${occ.externalId}` : 'Sem ID Externo'}
            {' • '}
            {occ.reportedAt ? `Relato: ${fmt(occ.reportedAt)}` : 'Sem data de relato'}
          </div>
        </div>
        <Badge status={occ.status} />
      </div>

      {err ? <div className="error">{err}</div> : null}

      <Card>
        <div className="two">
          <div>
            <div className="label">Descrição</div>
            <div className="value">{occ.description ?? '—'}</div>
          </div>
          <div>
            <div className="label">ID (interno)</div>
            <div className="value">{shortId(occ.id)}</div>
          </div>
        </div>

        <div className="actions">
          <Button
            onClick={() => runAction('start', () => startOccurrence(id))}
            disabled={!canStart}
            loading={busy === 'start'}
          >
            Iniciar atendimento
          </Button>

          <Button
            variant="ghost"
            onClick={() => setOpenDispatch(true)}
            loading={busy === 'dispatch'}
          >
            Criar despacho
          </Button>

          <Button
            onClick={() => runAction('resolve', () => resolveOccurrence(id))}
            disabled={!canResolve}
            loading={busy === 'resolve'}
          >
            Encerrar
          </Button>

          <Button
            variant="danger"
            onClick={() => runAction('cancel', () => cancelOccurrence(id))}
            disabled={!canCancel}
            loading={busy === 'cancel'}
          >
            Cancelar
          </Button>
        </div>

        <div className="muted small" style={{ marginTop: 10 }}>
          * As ações são processadas via fila (assíncrono). A tela só atualiza quando o comando finaliza.
        </div>
      </Card>

      <div className="sectionTitle">Despachos</div>

      {dispatches.length === 0 ? (
        <Card>
          <div className="muted">Nenhum despacho registrado ainda.</div>
        </Card>
      ) : (
        <div className="grid">
          {dispatches.map((d) => (
            <DispatchCard
              key={d.id}
              d={d}
              busy={busy}
              onUpdate={async (next) => {
                await runAction(`d:${d.id}`, () => updateDispatchStatus(d.id, next))
              }}
            />
          ))}
        </div>
      )}

      <Modal open={openDispatch} title="Novo despacho" onClose={() => setOpenDispatch(false)}>
        <div className="field">
          <label className="label">Código do recurso</label>
          <input
            className="input"
            value={resourceCode}
            onChange={(e) => setResourceCode(e.target.value)}
            placeholder="Ex.: ABT-12, UR-05, ASU-02"
          />
          <div className="muted small">Sugestão: ABT-12 / UR-05 / ASU-02</div>
        </div>

        <div className="modalActions">
          <Button onClick={onCreateDispatch} loading={busy === 'dispatch'}>
            Confirmar
          </Button>
          <Button variant="ghost" onClick={() => setOpenDispatch(false)}>
            Cancelar
          </Button>
        </div>
      </Modal>
    </div>
  )
}

function DispatchCard({
  d,
  onUpdate,
  busy,
}: {
  d: Dispatch
  onUpdate: (s: DispatchStatus) => Promise<void>
  busy: string | null
}) {
  const [next, setNext] = useState<DispatchStatus>(d.status)
  const saving = busy === `d:${d.id}`

  useEffect(() => {
    setNext(d.status)
  }, [d.status])

  const changed = next !== d.status

  return (
    <Card>
      <div className="row">
        <div>
          <div className="title">{d.resourceCode}</div>
          <div className="muted small">Criado: {d.createdAt ? fmt(d.createdAt) : '—'}</div>
        </div>
        <Badge status={d.status} />
      </div>

      <div style={{ marginTop: 12, display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
        <select className="select" value={next} onChange={(e) => setNext(e.target.value as DispatchStatus)}>
          {DISPATCH_STATUSES.map((s) => (
            <option key={s.value} value={s.value}>{s.label}</option>
          ))}
        </select>

        <Button
          variant={changed ? 'primary' : 'ghost'}
          disabled={!changed}
          loading={saving}
          onClick={() => onUpdate(next)}
        >
          Atualizar status
        </Button>
      </div>
    </Card>
  )
}

function fmt(iso: string) {
  try {
    return new Date(iso).toLocaleString('pt-BR')
  } catch {
    return iso
  }
}

function shortId(id: string) {
  return id?.slice(0, 8) ?? id
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
