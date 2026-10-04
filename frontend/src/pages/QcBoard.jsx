import { useEffect, useState, useCallback } from 'react'
import { api } from '../api.js'
import { useAuth } from '../auth.jsx'
import { ErrorBox, OkBox, Modal } from '../components/Modal.jsx'

export default function QcBoard() {
  const { user } = useAuth()
  const [rows, setRows] = useState([])
  const [error, setError] = useState(null)
  const [msg, setMsg] = useState(null)
  const [target, setTarget] = useState(null)

  const load = useCallback(async () => {
    setError(null)
    try {
      const batches = await api('/batches?status=qc_pending')
      const full = await Promise.all(batches.map(b => api(`/batches/${b.id}`)))
      setRows(full)
    } catch (e) { setError(e) }
  }, [])
  useEffect(() => { load() }, [load])

  return (
    <div>
      <h1 className="page-title">质检放行</h1>
      <p className="page-desc">
        质检员核对数量与工艺参数后放行；放行后下一工位才能接收。
        <b style={{ color: '#f0c674' }}> 操作员不能批准自己登记的批次</b>，系统强制拦截。
      </p>
      <ErrorBox error={error} />
      <OkBox>{msg}</OkBox>

      <div className="card">
        <h2>待质检批次（{rows.length}）</h2>
        {rows.length === 0 && <p className="muted">暂无待质检批次。</p>}
        <table>
          <thead><tr>
            <th>批次号</th><th>生产单</th><th>工序/设备</th><th>投入</th>
            <th>合格/返工/报废</th><th>参数版本</th><th>登记人</th><th></th>
          </tr></thead>
          <tbody>
            {rows.map(b => {
              const record = b.records[b.records.length - 1]
              return (
                <tr key={b.id}>
                  <td className="mono">{b.batch_no}
                    {b.origin === 'rework' && <span className="badge b-in_transit" style={{ marginLeft: 6 }}>返工回流</span>}</td>
                  <td className="mono" style={{ fontSize: 12 }}>{b.order?.order_no}</td>
                  <td>{b.process?.code} · {record?.equipment?.code}</td>
                  <td><b>{record?.input_qty}</b></td>
                  <td>{record?.good_qty} / {record?.rework_qty} / {record?.scrap_qty}</td>
                  <td className="mono">{record?.param_version?.version}</td>
                  <td>{record?.operator?.name}</td>
                  <td><button className="btn sm" onClick={async () => {
                    setError(null)
                    try {
                      const procs = await api(`/processes?product_code=${b.product_code}`)
                      setTarget({ batch: b, processes: procs })
                    } catch (e) { setError(e) }
                  }}>质检处置</button></td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      {target && <QcModal data={target} onClose={() => setTarget(null)} onDone={(m) => { setTarget(null); setMsg(m); load() }} />}
    </div>
  )
}

function QcModal({ data, onClose, onDone }) {
  const { batch, processes } = data
  const record = batch.records[batch.records.length - 1]
  const me = useAuth().user
  const [passed, setPassed] = useState(record.good_qty)
  const [rework, setRework] = useState(record.rework_qty)
  const [scrap, setScrap] = useState(record.scrap_qty)
  const [reworkTo, setReworkTo] = useState('')
  const [note, setNote] = useState('')
  const [err, setErr] = useState(null)
  const [busy, setBusy] = useState(false)

  const sum = Number(passed) + Number(rework) + Number(scrap)
  const qtyOk = sum === record.input_qty
  const selfApproval = record.operator?.id === me?.id
  const waived = record.check_status === 'waived'
  const reworkOptions = processes.filter(p => p.sequence <= record.process.sequence)

  async function submit() {
    setErr(null); setBusy(true)
    try {
      await api(`/records/${record.id}/inspect`, {
        method: 'POST',
        body: {
          passed_qty: Number(passed), rework_qty: Number(rework), scrap_qty: Number(scrap),
          rework_to_process_id: rework > 0 ? Number(reworkTo) : null, note,
        },
      })
      onDone(`质检处置完成：放行 ${passed} / 返工 ${rework} / 报废 ${scrap}`)
    } catch (e) { setErr(e) } finally { setBusy(false) }
  }

  return (
    <Modal title={`质检处置 · ${batch.batch_no}`} onClose={onClose} footer={
      <>
        <button className="btn secondary" onClick={onClose}>取消</button>
        <button className="btn green"
          disabled={busy || !qtyOk || (rework > 0 && !reworkTo) || selfApproval}
          onClick={submit}>提交处置</button>
      </>
    }>
      <ErrorBox error={err} />
      <dl className="kv">
        <dt>工序/设备</dt><dd>{record.process?.code} {batch.process?.name} · {record.equipment?.code}</dd>
        <dt>登记人</dt><dd>{record.operator?.name}{selfApproval && <span className="check-fail">（即当前登录人，禁止自批）</span>}</dd>
        <dt>参数版本</dt><dd className="mono">{record.param_version?.version}</dd>
        <dt>投入数量</dt><dd><b>{record.input_qty}</b></dd>
        <dt>登记构成</dt><dd>合格 {record.good_qty} · 返工 {record.rework_qty} · 报废 {record.scrap_qty}</dd>
      </dl>

      {waived && <div className="error" style={{ marginTop: 10 }}>该登记曾参数超限，已由工艺工程师让步放行（留痕），请重点复核。</div>}
      {selfApproval && <div className="error" style={{ marginTop: 10 }}>该批次由你本人登记，不能自行批准，请使用其他质检员账号。</div>}

      <h2 style={{ marginTop: 16 }}>实测参数（锁定版本 {record.param_version?.version}）</h2>
      <table>
        <thead><tr><th>参数</th><th>实测值</th></tr></thead>
        <tbody>
          {Object.entries(record.actual_params || {}).map(([code, v]) => (
            <tr key={code}><td className="mono">{code}</td><td className="mono">{v}</td></tr>
          ))}
        </tbody>
      </table>

      <h2 style={{ marginTop: 16 }}>处置数量（合计必须等于投入 {record.input_qty}）</h2>
      <div className="grid grid-3">
        <div><label>放行数量</label><input type="number" min={0} value={passed} onChange={e => setPassed(e.target.value)} /></div>
        <div><label>返工数量</label><input type="number" min={0} value={rework} onChange={e => setRework(e.target.value)} /></div>
        <div><label>报废数量</label><input type="number" min={0} value={scrap} onChange={e => setScrap(e.target.value)} /></div>
      </div>
      <p style={{ color: qtyOk ? '#7ee787' : '#ff8b82' }} className="hint">
        合计 {sum} / {record.input_qty} {qtyOk ? '✓' : '✗ 必须全部处置（放行/返工/报废）'}
      </p>

      {rework > 0 && (
        <>
          <label>返工回流工序（沿原生产单回到该工序重新加工）</label>
          <select value={reworkTo} onChange={e => setReworkTo(e.target.value)}>
            <option value="">请选择…</option>
            {reworkOptions.map(p => <option key={p.id} value={p.id}>
              {p.code} {p.name}（{p.station?.code}）{p.sequence === record.process.sequence ? ' · 当前工序' : ''}
            </option>)}
          </select>
          <p className="hint">回流批次在该工序重新接收时锁定当时生效的参数版本；完工后自动沿正常路线继续，返工单随之关闭。</p>
        </>
      )}
      <label>质检意见</label>
      <textarea rows={2} value={note} onChange={e => setNote(e.target.value)} />
    </Modal>
  )
}
