import React, { useEffect } from 'react'

export type ToastItem = { id: string; message: string }

export function Toasts({ items, onRemove }: { items: ToastItem[]; onRemove: (id: string) => void }) {
  useEffect(() => {
    const timers = items.map((t) => setTimeout(() => onRemove(t.id), 2800))
    return () => timers.forEach(clearTimeout)
  }, [items, onRemove])

  if (items.length === 0) return null

  return (
    <div className="toastWrap">
      {items.map((t) => (
        <div key={t.id} className="toast">{t.message}</div>
      ))}
    </div>
  )
}
