import { useEffect, useState, useCallback } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../api.js'
import { ErrorBox } from '../components/Modal.jsx'
import { StatusBadge, EVENT_LABELS } from '../labels.jsx'

export default function OrderTrace() {
  const { orderId } = useParams()
  const [orders, setOrders] = useState([])
  const [sel, setSel] = useState(orderId || '')
  const [trace, setTrace] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    api('/orders').then(setOrders).catch(setError)
  }, [])
  useEffect(() => { if (orderId) setSel(orderId) }, [orderId])

  const load = useCallback(async (id) => {
    setError(null); setTrace(null)
    try { setTrace(await api(`/orders/${id}/trace`)) } catch (e) { setError(e) }
  }, [])
  useEffect(() => { if (sel) load(sel) }, [sel, load])

  return (
    <div>
      <h1 className="page-title">追溯链</h1>
      <p className="page-desc">
        生产单下所有批次沿同一生产单关联：拆分、报废、返工回流形成批次树；
        事件时间线记录每次移动与参数版本，保证追溯不中断。
      </p>

      <div className="card">
        <label>选择生产单</label>
        <select value={sel} onChange={e => setSel(e.target.value)}>
          <option value="">请选择…</option>
          {orders.map(o => <option key={o.id} value={o.id}>{o.order_no} · {o.product_name}（{o.planned_qty}）</option>)}
        </select>
      </div>

      <ErrorBox error={error} />
      {trace && (
        <>
          <Reconciliation r={trace.reconciliation} />
          <div className="card">
            <h2>工序路线</h2>
            <div className="flex" style={{ flexWrap: 'wrap' }}>
              {trace.process_route.map((p, i) => (
                <span key={p.code} className="pill-param">
                  {p.sequence} · {p.code} {p.name}（{p.station.code}）{p.is_final ? ' · 末道' : ''}
                  {i < trace.process_route.length - 1 ? ' →' : ''}
                </span>
              ))}
            </div>
          </div>

          <div className="card">
            <h2>批次树（拆分 / 返工 / 报废 去向）</h2>
            <div className="tree"><BatchTree nodes={trace.tree} /></div>
          </div>

          <div className="card">
            <h2>事件时间线（{trace.events.length}）</h2>
            <div className="tl">
              {trace.events.map(e => (
                <div key={e.id} className="tl-item">
                  <div className="t">
                    {EVENT_LABELS[e.event_type] || e.event_type}
                    {e.batch_no && <> · <span className="mono">{e.batch_no}</span></>}
                    {e.quantity != null && <> · {e.quantity} 件</>}
                  </div>
                  <div className="m">
                    {e.from_node && <span>{e.from_node} → </span>}
                    {e.to_node}
                    {e.payload?.param_version && <> · 参数版本 <b className="mono">{e.payload.param_version}</b></>}
                    {e.payload?.carried_param_version && <> · 携带版本 <b className="mono">{e.payload.carried_param_version}</b></>}
                    {e.actor && <> · {e.actor}</>} · {e.occurred_at}
                  </div>
                </div>
              ))}
            </div>
          </div>
        </>
      )}
    </div>
  )
}

function Reconciliation({ r }) {
  return (
    <div className="card" style={{ borderColor: r.all_pass ? '#235c33' : '#6e262b' }}>
      <h2>数量守恒核对 {r.all_pass
        ? <span className="check-pass">✓ 全部通过</span>
        : <span className="check-fail">✗ 存在断裂</span>}</h2>
      <div className="grid grid-4" style={{ marginBottom: 12 }}>
        <div className="stat"><div className="n">{r.totals.planned}</div><div className="l">计划</div></div>
        <div className="stat"><div className="n">{r.totals.wip}</div><div className="l">在制</div></div>
        <div className="stat"><div className="n">{r.totals.completed}</div><div className="l">完工</div></div>
        <div className="stat"><div className="n">{r.totals.scrapped}</div><div className="l">报废</div></div>
      </div>
      <table>
        <tbody>
          {r.checks.map((c, i) => (
            <tr key={i}>
              <td style={{ width: 30 }} className={c.pass ? 'check-pass' : 'check-fail'}>{c.pass ? '✓' : '✗'}</td>
              <td>{c.name}</td>
              <td className="muted mono" style={{ fontSize: 12 }}>{c.detail}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function BatchTree({ nodes }) {
  return (
    <ul>
      {nodes.map(n => (
        <li key={n.id}>
          <span className="tnode">
            <span className="mono">{n.batch_no}</span>
            <StatusBadge status={n.status} />
            <b>{n.qty}</b>
            <span className="muted" style={{ fontSize: 11 }}>
              {n.origin === 'rework' ? '返工回流' : n.origin === 'scrap' ? '判废' : n.origin === 'root' ? '根批' : '拆分'}
            </span>
          </span>
          <span className="muted" style={{ fontSize: 12, marginLeft: 8 }}>
            {n.location.process?.code} · {n.location.station?.code || '—'} · {n.location.equipment?.code || '—'}
            {n.location.param_version && <> · 参数 <b className="mono">{n.location.param_version}</b></>}
            {n.rework_of && <> · 回流自 <span className="mono">{n.rework_of.source_batch_no}</span>
              （{n.rework_of.from_process} → {n.rework_of.to_process}，{n.rework_of.status}）</>}
          </span>
          {n.records.length > 0 && (
            <div className="muted" style={{ fontSize: 11, margin: '3px 0 0 4px' }}>
              {n.records.map(rec => (
                <span key={rec.id} className="pill-param">
                  {rec.process} @{rec.equipment} <b className="mono">{rec.param_version}</b>：
                  投{rec.input_qty}/合{rec.good_qty}/返{rec.rework_qty}/废{rec.scrap_qty}
                  {rec.check_status !== 'normal' && <span className="check-fail"> [{rec.check_status}]</span>}
                  {rec.inspections?.map((ins, j) =>
                    <span key={j}> | 质检({ins.inspector}) {ins.decision}</span>)}
                </span>
              ))}
            </div>
          )}
          {n.children.length > 0 && <BatchTree nodes={n.children} />}
        </li>
      ))}
    </ul>
  )
}
