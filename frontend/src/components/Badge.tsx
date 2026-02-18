import type { DispatchStatus, OccurrenceStatus } from '../api/cbm'

export function Badge({ status }: { status: OccurrenceStatus | DispatchStatus }) {
  return <span className={`badge badge-${status}`}>{label(status)}</span>
}

function label(s: string) {
  const map: Record<string, string> = {
    reported: 'Reportada',
    in_progress: 'Em atendimento',
    resolved: 'Encerrada',
    cancelled: 'Cancelada',

    assigned: 'Designado',
    en_route: 'A caminho',
    on_site: 'No local',
    closed: 'Finalizado',
  }

  return map[s] ?? s
}
