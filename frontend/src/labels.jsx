export const STATUS_LABELS = {
  queued: '待接收',
  in_progress: '加工中',
  qc_pending: '待质检',
  on_hold: '待处理',
  released: '在途放行',
  completed: '已完工',
  split: '已拆分',
  scrapped: '已报废',
  rework_done: '返工完成',
}

export const HOLD_LABELS = {
  qty_mismatch: '数量不符',
  params_out_of_range: '参数超限',
  qc_rejected: '质检拒收',
}

export const EVENT_LABELS = {
  order_created: '生产单创建',
  order_released: '生产单下发',
  received: '工位接收',
  production: '登记产出',
  qc: '质检处置',
  transfer: '放行转序',
  transfer_received: '到达接收',
  split: '批次拆分',
  scrap: '报废',
  rework_created: '返工回流',
  completed: '完工入库',
  hold: '挂起待处理',
  hold_resolved: '挂起解除',
}

export const ROLE_LABELS = {
  operator: '操作员',
  qc: '质检员',
  engineer: '工艺工程师',
  admin: '管理员',
}

export function StatusBadge({ status }) {
  const label = STATUS_LABELS[status] || status
  return <span className={`badge b-${status}`}>{label}</span>
}

export function HoldBadge({ reason }) {
  if (!reason) return null
  return <span className="badge b-on_hold">{HOLD_LABELS[reason] || reason}</span>
}
