import { useMemo, useState } from 'react'
import { getApiBase, getApiKey } from './api/client'
import { Toasts, type ToastItem } from './components/Toast'
import { OccurrenceDetails } from './pages/OccurrenceDetails'
import { OccurrenceList } from './pages/OccurrenceList'

export default function App() {
  const [selected, setSelected] = useState<string | null>(null)

  const [toasts, setToasts] = useState<ToastItem[]>([])
  const pushToast = (message: string) => {
    setToasts((prev) => [...prev, { id: `${Date.now()}-${Math.random().toString(16).slice(2)}`, message }])
  }

  const hasKey = useMemo(() => Boolean(getApiKey()), [])
  const base = useMemo(() => getApiBase(), [])

  return (
      <div className="container">
        <div className="top">
          <div className="brand">
            <div className="brandMark">E</div>
            <div>
              <div className="brandName">ESPECTRALL</div>
              <div className="brandSub">Ocorrências • Despachos • Operacional</div>
            </div>
          </div>

          <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
            <div className="pill"><span className="pillDot" /> API</div>
            <div className="muted small">{base}</div>
          </div>
        </div>

        {!hasKey ? (
            <div className="error" style={{ marginTop: 14 }}>
              Falta configurar a <b>X-API-Key</b> no frontend.
              <div className="muted small" style={{ marginTop: 6 }}>
                Crie <b>frontend/.env</b> com <b>VITE_API_KEY</b> e reinicie o Vite.
              </div>
            </div>
        ) : null}

        {selected ? (
            <OccurrenceDetails id={selected} onBack={() => setSelected(null)} pushToast={pushToast} />
        ) : (
            <OccurrenceList onOpen={setSelected} pushToast={pushToast} />
        )}

        <Toasts items={toasts} onRemove={(id) => setToasts((prev) => prev.filter((t) => t.id !== id))} />
      </div>
  )
}