export const STATUS_LABELS = {
  in_production: '在制',
  pending_qc: '待质检',
  released: '已放行',
  on_hold: '待处理',
  split: '已拆分',
  completed: '已完成',
}

export const EVENT_LABELS = {
  create: '创建',
  output: '产出登记',
  hold: '待处理',
  qc_release: '质检放行',
  qc_reject: '质检驳回',
  receive: '接收',
  split: '拆分',
  rework: '返工',
  complete: '完成',
}

export const ORIGIN_LABELS = { root: '原始批', split: '拆分批', rework: '返工批' }

export function StatusBadge({ status }) {
  return <span className={`badge st-${status}`}>{STATUS_LABELS[status] || status}</span>
}

export function OriginBadge({ origin }) {
  return <span className={`badge og-${origin}`}>{ORIGIN_LABELS[origin] || origin}</span>
}

export function Modal({ title, onClose, children }) {
  return (
    <div className="modal-mask" onClick={onClose}>
      <div className="modal" onClick={(e) => e.stopPropagation()}>
        <div className="modal-head">
          <h3>{title}</h3>
          <button className="btn ghost" onClick={onClose}>✕</button>
        </div>
        <div className="modal-body">{children}</div>
      </div>
    </div>
  )
}

export function ErrorBox({ error, onClose }) {
  if (!error) return null
  const details = Object.values(error.errors || {}).flat()
  return (
    <div className="error-box">
      <span>⚠ {error.message}{details.length > 0 && `:${details.join(';')}`}</span>
      {onClose && <button className="btn ghost" onClick={onClose}>✕</button>}
    </div>
  )
}

export function Qty({ label, value, tone }) {
  return (
    <div className={`qty ${tone || ''}`}>
      <div className="qty-num">{value ?? 0}</div>
      <div className="qty-label">{label}</div>
    </div>
  )
}
