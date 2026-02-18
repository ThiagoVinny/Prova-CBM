import { useMemo, useState } from 'react'
import { getApiBase, getApiKey, saveApiSettings } from './api/client'
import { Button } from './components/Button'
import { Modal } from './components/Modal'
import { Toasts, type ToastItem } from './components/Toast'
import { OccurrenceDetails } from './pages/OccurrenceDetails'
import { OccurrenceList } from './pages/OccurrenceList'

export default function App() {
  const [selected, setSelected] = useState<string | null>(null)

  const [toasts, setToasts] = useState<ToastItem[]>([])
  const pushToast = (message: string) => {
    setToasts((prev) => [...prev, { id: `${Date.now()}-${Math.random().toString(16).slice(2)}`, message }])
  }

  const [openSettings, setOpenSettings] = useState(false)
  const [base, setBase] = useState(getApiBase())
  const [key, setKey] = useState(getApiKey() ?? '')

  const hasKey = useMemo(() => Boolean(getApiKey()), [openSettings, key])

  function saveSettings() {
    saveApiSettings(base.trim(), key.trim())
    setOpenSettings(false)
    pushToast('Configuração salva')
  }

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
          <Button variant="ghost" onClick={() => setOpenSettings(true)}>
            Configurar API
          </Button>
        </div>
      </div>

      {!hasKey ? (
        <div className="error" style={{ marginTop: 14 }}>
          Falta configurar a <b>X-API-Key</b>. Clique em <b>Configurar API</b>.
        </div>
      ) : null}

      {selected ? (
        <OccurrenceDetails id={selected} onBack={() => setSelected(null)} pushToast={pushToast} />
      ) : (
        <OccurrenceList onOpen={setSelected} pushToast={pushToast} />
      )}

      <Modal open={openSettings} title="Configuração da API" onClose={() => setOpenSettings(false)}>
        <div className="field">
          <label className="label">Base URL</label>
          <input className="input" value={base} onChange={(e) => setBase(e.target.value)} />
          <div className="muted small">Ex.: http://localhost:8000/api</div>
        </div>

        <div className="field" style={{ marginTop: 12 }}>
          <label className="label">X-API-Key</label>
          <input className="input" value={key} onChange={(e) => setKey(e.target.value)} placeholder="Cole sua API_KEY do backend" />
        </div>

        <div className="modalActions">
          <Button onClick={saveSettings}>Salvar</Button>
          <Button variant="ghost" onClick={() => setOpenSettings(false)}>Cancelar</Button>
        </div>
      </Modal>

      <Toasts items={toasts} onRemove={(id) => setToasts((prev) => prev.filter((t) => t.id !== id))} />
    </div>
  )
}
