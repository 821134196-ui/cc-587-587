export function Modal({ title, onClose, children, footer }) {
  return (
    <div className="modal-bg" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose() }}>
      <div className="modal">
        <div className="flex-between">
          <h3>{title}</h3>
          <button className="btn secondary sm" onClick={onClose}>关闭</button>
        </div>
        {children}
        {footer && <div className="btn-row">{footer}</div>}
      </div>
    </div>
  )
}

export function ErrorBox({ error }) {
  if (!error) return null
  return <div className="error">⚠ {error.message || String(error)}</div>
}

export function OkBox({ children }) {
  if (!children) return null
  return <div className="ok">✓ {children}</div>
}
