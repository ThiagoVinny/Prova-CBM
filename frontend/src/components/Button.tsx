import React from 'react'

type Props = React.ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'primary' | 'ghost' | 'danger'
  loading?: boolean
}

export function Button({ variant = 'primary', loading = false, disabled, className = '', children, ...props }: Props) {
  const classes = [
    'btn',
    variant === 'primary' ? 'btnPrimary' : variant === 'danger' ? 'btnDanger' : 'btnGhost',
    (disabled || loading) ? 'btnDisabled' : '',
    className,
  ].join(' ')

  return (
    <button {...props} className={classes} disabled={disabled || loading}>
      {loading ? <span className="spinner" /> : null}
      {children}
    </button>
  )
}
