import { useState } from 'react'
import { api } from '../api'
import { ErrorBox, StatusBadge } from '../ui'

/** 生产单:列表 + 新建(可同时创建首个批次投入首工序) */
export default function Orders({ orders, onChanged, onOpenBatch }) {
  const [form, setForm] = useState({ code: '', product_name: '', planned_qty: 100, first_batch_qty: 100 })
  const [error, setError] = useState(null)
  const [ok, setOk] = useState('')

  const submit = async () => {
    setError(null)
    try {
      const res = await api('/api/orders', {
        method: 'POST',
        body: {
          code: form.code,
          product_name: form.product_name,
          planned_qty: Number(form.planned_qty),
          ...(form.first_batch_qty ? { first_batch_qty: Number(form.first_batch_qty) } : {}),
        },
      })
      setOk(`生产单 ${res.order.code} 已创建${res.batch ? `,批次 ${res.batch.code} 已投入首工序` : ''}`)
      setForm({ code: '', product_name: '', planned_qty: 100, first_batch_qty: 100 })
      onChanged()
    } catch (e) {
      setError(e)
    }
  }

  return (
    <div className="page">
      <h2>生产单</h2>
      <div className="panel">
        <h3>新建生产单</h3>
        <ErrorBox error={error} onClose={() => setError(null)} />
        {ok && <div className="ok-box">{ok}</div>}
        <div className="form-row">
          <div className="field"><label>单号</label>
            <input value={form.code} placeholder="MO-2026-002" onChange={(e) => setForm({ ...form, code: e.target.value })} /></div>
          <div className="field"><label>产品</label>
            <input value={form.product_name} placeholder="齿轮 M2.5×40" onChange={(e) => setForm({ ...form, product_name: e.target.value })} /></div>
          <div className="field"><label>计划数量</label>
            <input type="number" min="1" value={form.planned_qty} onChange={(e) => setForm({ ...form, planned_qty: e.target.value })} /></div>
          <div className="field"><label>首批投入(可空)</label>
            <input type="number" min="0" value={form.first_batch_qty} onChange={(e) => setForm({ ...form, first_batch_qty: e.target.value })} /></div>
          <button className="btn" disabled={!form.code || !form.product_name} onClick={submit}>创建并投产</button>
        </div>
      </div>

      <div className="panel">
        <h3>生产单列表</h3>
        <table className="grid">
          <thead><tr><th>单号</th><th>产品</th><th>计划数量</th><th>批次数</th><th>各状态批次</th><th>状态</th></tr></thead>
          <tbody>
            {orders.map((o) => {
              const byStatus = {}
              ;(o.batches || []).forEach((b) => { byStatus[b.status] = (byStatus[b.status] || 0) + 1 })
              return (
                <tr key={o.id}>
                  <td>{o.code}</td>
                  <td>{o.product_name}</td>
                  <td>{o.planned_qty}</td>
                  <td>{o.batches_count}</td>
                  <td>
                    {Object.entries(byStatus).map(([s, n]) => (
                      <span key={s} style={{ marginRight: 6 }}><StatusBadge status={s} /> ×{n}</span>
                    ))}
                  </td>
                  <td>{o.status === 'open' ? '进行中' : '已关闭'}</td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </div>
  )
}
