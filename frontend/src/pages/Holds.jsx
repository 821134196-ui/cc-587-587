import { useEffect, useState, useCallback } from 'react'
import { api } from '../api.js'
import { useAuth } from '../auth.jsx'
import { ErrorBox, OkBox, Modal } from '../components/Modal.jsx'

export default function Holds() {
  const { user } = useAuth()
  const [holds, setHolds] = useState([])
  const [error, setError] = useState(null)
  const [msg, setMsg] = useState(null)
  const [target, setTarget] = useState(null)

  const load = useCallback(async () => {
    setError(null)
    try { setHolds(await api('/holds')) } catch (e) { setError(e) }
  }, [])
  useEffect(() => { load() }, [load])

  return (
    <div>
      <h1 className="page-title">待处理批次</h1>
      <p className="page-desc">
        数量不符或工艺参数超限时批次停在此状态，<b style={{ color: '#ff8b82' }}>不得转给下一工位</b>，
        必须按规则处置后才能恢复流转。
      </p>
      <ErrorBox error={error} />
      <OkBox>{msg}</OkBox>

      <div className="card">
        <h2>挂起队列（{holds.length}）</h2>
        {holds.length === 0 && <p className="muted">没有挂起批次，生产流转正常。</p>}
        <table>
          <thead><tr>
            <th>批次</th><th>生产单</th><th>工序/设备</th><th>挂起原因</th>
            <th>数量明细</th><th>说明</th><th></th>
          </tr></thead>
          <tbody>
            {holds.map(r => (
              <tr key={r.id}>
                <td className="mono">{r.batch?.batch_no}<div className="muted" style={{ fontSize: 11 }}>登记 #{r.id}</div></td>
                <td className="mono" style={{ fontSize: 12 }}>{r.batch?.order?.order_no}</td>
                <td>{r.process?.code} · {r.equipment?.code}</td>
                <td><span className="badge b-on_hold">
                  {r.check_status === 'qty_mismatch' ? '数量不符' : '参数超限'}
                </span></td>
                <td>投入 {r.input_qty} = {r.good_qty}+{r.rework_qty}+{r.scrap_qty}</td>
                <td style={{ maxWidth: 260, fontSize: 12 }} className="muted">{r.check_detail}</td>
                <td><button className="btn sm amber" onClick={async () => {
                  try {
                    const procs = await api(`/processes?product_code=${r.batch.order.product_code}`)
                    setTarget({ record: r, processes: procs })
                  } catch (e) { setError(e) }
                }}>处置</button></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {target && <HoldModal data={target} onClose={() => setTarget(null)} onDone={(m) => { setTarget(null); setMsg(m); load() }} />}
    </div>
  )
}

function HoldModal({ data, onClose, onDone }) {
  const { record, processes } = data
  const me = useAuth().user
  const isEngineer = ['engineer', 'admin'].includes(me.role)
  const isQcOrEng = ['qc', 'engineer', 'admin'].includes(me.role)
  const [err, setErr] = useState(null)
  const [busy, setBusy] = useState(false)

  const isQty = record.check_status === 'qty_mismatch'
  const [good, setGood] = useState(record.good_qty)
  const [rework, setRework] = useState(record.rework_qty)
  const [scrap, setScrap] = useState(record.scrap_qty)
  const [note, setNote] = useState('')
  const [reworkTo, setReworkTo] = useState('')

  const sum = Number(good) + Number(rework) + Number(scrap)
  const shortfall = record.input_qty - (record.good_qty + record.rework_qty + record.scrap_qty)

  async function resolve(action, body) {
    setErr(null); setBusy(true)
    try {
      await api(`/records/${record.id}/resolve-hold`, { method: 'POST', body: { action, ...body } })
      onDone(`挂起已解除（${action}），批次恢复流转`)
    } catch (e) { setErr(e) } finally { setBusy(false) }
  }

  return (
    <Modal title={`挂起处置 · 登记 #${record.id}`} onClose={onClose}>
      <ErrorBox error={err} />
      <dl className="kv">
        <dt>批次</dt><dd className="mono">{record.batch?.batch_no}</dd>
        <dt>位置</dt><dd>{record.process?.code} · {record.station?.code} · {record.equipment?.code}</dd>
        <dt>参数版本</dt><dd className="mono">{record.param_version?.version}</dd>
        <dt>登记人</dt><dd>{record.operator?.name}</dd>
        <dt>原因</dt><dd className="check-fail">{record.check_detail}</dd>
      </dl>

      {isQty ? (
        <>
          <h2 style={{ marginTop: 16 }}>方式一：更正登记数量（合计 = 投入 {record.input_qty}）</h2>
          <div className="grid grid-3">
            <div><label>合格</label><input type="number" value={good} onChange={e => setGood(e.target.value)} /></div>
            <div><label>返工</label><input type="number" value={rework} onChange={e => setRework(e.target.value)} /></div>
            <div><label>报废</label><input type="number" value={scrap} onChange={e => setScrap(e.target.value)} /></div>
          </div>
          <p style={{ color: sum === record.input_qty ? '#7ee787' : '#ff8b82' }} className="hint">
            合计 {sum} / {record.input_qty}
          </p>
          <div className="btn-row">
            <button className="btn" disabled={busy || sum !== record.input_qty}
              onClick={() => resolve('correct', { good_qty: Number(good), rework_qty: Number(rework), scrap_qty: Number(scrap) })}>
              按更正数量恢复
            </button>
          </div>

          {shortfall > 0 && (
            <>
              <h2 style={{ marginTop: 18 }}>方式二：短缺 {shortfall} 件裁定计报废{!isQcOrEng && '（需质检/工程师权限）'}</h2>
              <div className="btn-row">
                <button className="btn red" disabled={busy || !isQcOrEng}
                  onClick={() => resolve('shortfall_as_scrap', {})}>短缺 {shortfall} 件计报废并恢复</button>
              </div>
            </>
          )}
        </>
      ) : (
        <>
          <h2 style={{ marginTop: 16 }}>实测参数</h2>
          <table>
            <thead><tr><th>参数</th><th>实测值</th></tr></thead>
            <tbody>
              {Object.entries(record.actual_params || {}).map(([code, v]) => (
                <tr key={code}><td className="mono">{code}</td>
                  <td className={'mono ' + (record.check_detail?.includes(code) ? 'check-fail' : '')}>{v}</td></tr>
              ))}
            </tbody>
          </table>
          <h2 style={{ marginTop: 16 }}>处置方式（需工艺工程师）</h2>
          <label>处置说明 / 让步理由（让步放行必填，永久留痕）</label>
          <textarea rows={2} value={note} onChange={e => setNote(e.target.value)}
            placeholder="如：该偏差不影响关键配合尺寸，经评估让步接收" />
          <div className="btn-row">
            <button className="btn amber" disabled={busy || !isEngineer || !note}
              onClick={() => resolve('waive', { note })}>让步放行（超限事实保留）</button>
          </div>
          <label style={{ marginTop: 14 }}>整批返工回流工序</label>
          <select value={reworkTo} onChange={e => setReworkTo(e.target.value)}>
            <option value="">请选择…</option>
            {processes.filter(p => p.sequence <= record.process.sequence)
              .map(p => <option key={p.id} value={p.id}>{p.code} {p.name}（{p.station?.code}）</option>)}
          </select>
          <div className="btn-row">
            <button className="btn" disabled={busy || !isEngineer || !reworkTo}
              onClick={() => resolve('rework', { rework_to_process_id: Number(reworkTo) })}>
              整批返工回流
            </button>
            <button className="btn red" disabled={busy || !isEngineer}
              onClick={() => resolve('scrap', { note: note || '参数超限判废' })}>整批判废</button>
          </div>
        </>
      )}
    </Modal>
  )
}
