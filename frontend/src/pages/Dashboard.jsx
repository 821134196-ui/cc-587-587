import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api.js'
import { ErrorBox, OkBox, Modal } from '../components/Modal.jsx'
import { StatusBadge } from '../labels.jsx'
import { useAuth } from '../auth.jsx'

export default function Dashboard() {
  const { user } = useAuth()
  const [orders, setOrders] = useState([])
  const [batches, setBatches] = useState([])
  const [error, setError] = useState(null)
  const [showCreate, setShowCreate] = useState(false)
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState(null)

  async function load() {
    setError(null)
    try {
      const [os, bs] = await Promise.all([api('/orders'), api('/batches?active_only=1')])
      setOrders(os); setBatches(bs)
    } catch (e) { setError(e) }
  }
  useEffect(() => { load() }, [])

  async function release(id) {
    setMsg(null); setError(null)
    try { await api(`/orders/${id}/release`, { method: 'POST' }); setMsg('生产单已下发，首工位可接收'); load() }
    catch (e) { setError(e) }
  }

  const counts = batches.reduce((acc, b) => { acc[b.status] = (acc[b.status] || 0) + 1; return acc }, {})
  const totalWip = batches.filter(b => !['completed'].includes(b.status))
    .reduce((s, b) => s + b.qty, 0)

  return (
    <div>
      <div className="flex-between">
        <div>
          <h1 className="page-title">生产看板</h1>
          <p className="page-desc">生产单、批次分布与数量总览</p>
        </div>
        {['engineer', 'admin'].includes(user.role) && (
          <button className="btn" onClick={() => setShowCreate(true)}>+ 新建生产单</button>
        )}
      </div>

      <ErrorBox error={error} />
      <OkBox>{msg}</OkBox>

      <div className="grid grid-4" style={{ marginBottom: 18 }}>
        <div className="stat"><div className="n">{orders.length}</div><div className="l">生产单</div></div>
        <div className="stat"><div className="n">{counts.queued || 0}</div><div className="l">待接收批次</div></div>
        <div className="stat"><div className="n">{(counts.in_progress || 0) + (counts.qc_pending || 0) + (counts.released || 0)}</div><div className="l">在制 / 在途批次</div></div>
        <div className="stat"><div className="n">{counts.on_hold || 0}</div><div className="l">待处理批次</div></div>
      </div>
      <p className="hint" style={{ marginTop: -8, marginBottom: 18 }}>在制总量（未完工，含在途/挂起）：{totalWip} 件</p>

      <div className="card">
        <h2>生产单</h2>
        <table>
          <thead><tr>
            <th>生产单号</th><th>产品</th><th>计划</th><th>在制</th><th>完工</th><th>报废</th><th>状态</th><th></th>
          </tr></thead>
          <tbody>
            {orders.map(o => (
              <tr key={o.id}>
                <td className="mono">{o.order_no}</td>
                <td>{o.product_name}<div className="muted mono" style={{ fontSize: 11 }}>{o.product_code}</div></td>
                <td>{o.planned_qty}</td>
                <td>{o.quantity_summary?.wip_qty ?? '-'}</td>
                <td>{o.quantity_summary?.completed_qty ?? '-'}</td>
                <td style={{ color: o.quantity_summary?.scrapped_qty ? '#ff8b82' : undefined }}>
                  {o.quantity_summary?.scrapped_qty ?? '-'}
                </td>
                <td><StatusBadge status={o.status === 'released' ? 'in_progress' : 'queued'} /></td>
                <td className="flex">
                  {o.status === 'planned'
                    ? <button className="btn sm green" onClick={() => release(o.id)}>下发</button>
                    : <span className="muted">已下发</span>}
                  <Link className="btn sm secondary" to={`/trace/${o.id}`}>追溯链</Link>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {showCreate && <CreateOrder onClose={() => setShowCreate(false)} onDone={() => { setShowCreate(false); load() }} setError={setError} />}
    </div>
  )
}

function CreateOrder({ onClose, onDone }) {
  const [form, setForm] = useState({ product_code: 'P-AX100', product_name: 'AX100 传动轴组件', planned_qty: 50, note: '' })
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  async function submit() {
    setError(null); setBusy(true)
    try {
      await api('/orders', { method: 'POST', body: { ...form, planned_qty: Number(form.planned_qty) } })
      onDone()
    } catch (e) { setError(e) } finally { setBusy(false) }
  }
  return (
    <Modal title="新建生产单" onClose={onClose} footer={
      <>
        <button className="btn secondary" onClick={onClose}>取消</button>
        <button className="btn" disabled={busy} onClick={submit}>创建（自动建根批次）</button>
      </>
    }>
      <ErrorBox error={error} />
      <label>产品编码</label>
      <input value={form.product_code} onChange={e => setForm({ ...form, product_code: e.target.value })} />
      <label>产品名称</label>
      <input value={form.product_name} onChange={e => setForm({ ...form, product_name: e.target.value })} />
      <label>计划数量</label>
      <input type="number" min={1} value={form.planned_qty} onChange={e => setForm({ ...form, planned_qty: e.target.value })} />
      <label>备注</label>
      <input value={form.note} onChange={e => setForm({ ...form, note: e.target.value })} />
      <p className="hint">创建后生成同数量的根批次（按 P-AX100 工艺路线：下料 → 机加 → 焊接），需下发后首工位才能接收。</p>
    </Modal>
  )
}
