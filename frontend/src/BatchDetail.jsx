import { useCallback, useEffect, useState } from 'react'
import { api } from './api'
import { ErrorBox, EVENT_LABELS, Modal, OriginBadge, Qty, StatusBadge } from './ui'

/**
 * 批次详情抽屉:当前工序/设备/锁定参数版本、数量、设备读数、
 * 按状态可用操作(登记产出/质检/接收/拆分/返工)、完整追溯链。
 */
export default function BatchDetail({ batchId, processes, onClose, onChanged, onOpenBatch }) {
  const [batch, setBatch] = useState(null)
  const [trace, setTrace] = useState(null)
  const [error, setError] = useState(null)
  const [action, setAction] = useState(null) // output | receive | split | rework | null

  const load = useCallback(async () => {
    setError(null)
    try {
      const [b, t] = await Promise.all([
        api(`/api/batches/${batchId}`),
        api(`/api/batches/${batchId}/trace`),
      ])
      setBatch(b)
      setTrace(t)
    } catch (e) {
      setError(e)
    }
  }, [batchId])

  useEffect(() => { load() }, [load])

  const run = async (fn) => {
    setError(null)
    try {
      await fn()
      setAction(null)
      await load()
      onChanged?.()
    } catch (e) {
      setError(e)
    }
  }

  if (!batch) {
    return (
      <>
        <div className="drawer-mask" onClick={onClose} />
        <div className="drawer"><ErrorBox error={error} /><p className="muted">加载中…</p></div>
      </>
    )
  }

  const pv = batch.parameter_version
  const nextProcess = processes.find((p) => p.sequence > batch.process.sequence)
    ?? processes.filter((p) => p.sequence > batch.process.sequence)[0]

  return (
    <>
      <div className="drawer-mask" onClick={onClose} />
      <div className="drawer">
        <div className="drawer-head">
          <h2>{batch.code} <OriginBadge origin={batch.origin} /> <StatusBadge status={batch.status} /></h2>
          <button className="btn ghost" onClick={onClose}>✕</button>
        </div>

        <ErrorBox error={error} onClose={() => setError(null)} />
        {batch.hold_reason && <div className="hold-banner">⛔ {batch.hold_reason}(待处理,不得转给下一工位)</div>}

        <div className="panel">
          <div className="info-grid">
            <span className="k">生产单</span><span>{batch.order.code} · {batch.order.product_name}</span>
            <span className="k">当前工序</span><span>{batch.process.name}({batch.process.code})</span>
            <span className="k">当前设备</span><span>{batch.equipment?.name || '—'}</span>
            <span className="k">锁定参数版本</span>
            <span>{pv ? `v${pv.version}(${pv.status === 'active' ? '现行' : '已换版,本批仍按此版生产'})` : '—'}</span>
            {batch.parent && (<><span className="k">父批次</span>
              <span className="link" style={{ color: 'var(--accent)', cursor: 'pointer' }} onClick={() => onOpenBatch(batch.parent.id)}>{batch.parent.code}</span></>)}
            {batch.registrar && (<><span className="k">产出登记人</span><span>{batch.registrar.name}</span></>)}
            {batch.qc_inspector && (<><span className="k">质检人</span><span>{batch.qc_inspector.name}</span></>)}
          </div>
          <div className="section-title">数量</div>
          <div className="qty-row">
            <Qty label="本工序投入" value={batch.qty_received} />
            <Qty label="合格" value={batch.qty_qualified} tone="good" />
            <Qty label="待返工" value={batch.qty_rework} tone="rework" />
            <Qty label="报废" value={batch.qty_scrap} tone="scrap" />
          </div>
        </div>

        {pv && (
          <div className="panel">
            <h3>工艺参数(锁定版本 v{pv.version})</h3>
            <table className="grid">
              <thead><tr><th>指标</th><th>下限</th><th>目标</th><th>上限</th><th>单位</th></tr></thead>
              <tbody>
                {Object.entries(pv.params).map(([m, l]) => (
                  <tr key={m}><td>{m}</td><td>{l.min}</td><td>{l.target ?? '—'}</td><td>{l.max}</td><td>{l.unit || ''}</td></tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {batch.readings?.length > 0 && (
          <div className="panel">
            <h3>最近设备读数(本地模拟)</h3>
            <table className="grid">
              <thead><tr><th>指标</th><th>读数</th><th>限值</th><th>判定</th><th>时间</th></tr></thead>
              <tbody>
                {batch.readings.map((r) => (
                  <tr key={r.id}>
                    <td>{r.metric}</td>
                    <td className={r.in_limit ? 'reading-ok' : 'reading-bad'}>{r.value}</td>
                    <td>{r.min_value} ~ {r.max_value}</td>
                    <td>{r.in_limit ? <span className="reading-ok">✓ 合格</span> : <span className="reading-bad">✗ 超限</span>}</td>
                    <td className="muted">{r.created_at?.replace('T', ' ').slice(0, 19)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <div className="panel">
          <h3>操作</h3>
          <div className="actions">
            {['in_production', 'on_hold'].includes(batch.status) && (
              <button className="btn" onClick={() => setAction('output')}>登记产出</button>
            )}
            {batch.status === 'pending_qc' && (
              <>
                <button className="btn green" onClick={() => run(() => api(`/api/batches/${batch.id}/qc`, { method: 'POST', body: { action: 'release' } }))}>质检放行</button>
                <button className="btn red" onClick={() => run(() => api(`/api/batches/${batch.id}/qc`, { method: 'POST', body: { action: 'reject', note: '质检不合格' } }))}>质检驳回</button>
              </>
            )}
            {batch.status === 'released' && nextProcess && (
              <button className="btn" onClick={() => setAction('receive')}>下一工位接收</button>
            )}
            {batch.status === 'in_production' && (
              <button className="btn amber" onClick={() => setAction('split')}>拆分批次</button>
            )}
            {batch.qty_rework > 0 && ['pending_qc', 'released', 'on_hold'].includes(batch.status) && (
              <button className="btn amber" onClick={() => setAction('rework')}>返工回流(余 {batch.qty_rework})</button>
            )}
          </div>
          {batch.status === 'released' && !nextProcess && <p className="muted">已是末工序。</p>}
          {batch.status === 'split' && <p className="muted">本批已拆分,数量转入子批,见下方追溯树。</p>}
          {batch.status === 'completed' && <p className="muted">批次已完成,合格品入库。</p>}
        </div>

        {trace && <TracePanel trace={trace} onOpenBatch={onOpenBatch} />}

        {action === 'output' && (
          <OutputModal batch={batch} onClose={() => setAction(null)} onDone={run} />
        )}
        {action === 'receive' && (
          <ReceiveModal batch={batch} processes={processes} onClose={() => setAction(null)} onDone={run} />
        )}
        {action === 'split' && (
          <SplitModal batch={batch} onClose={() => setAction(null)} onDone={run} />
        )}
        {action === 'rework' && (
          <ReworkModal batch={batch} processes={processes} onClose={() => setAction(null)} onDone={run} />
        )}
      </div>
    </>
  )
}

function TracePanel({ trace, onOpenBatch }) {
  const r = trace.reconciliation
  return (
    <>
      <div className="panel">
        <h3>全链数量核对(生产单 {trace.order.code})</h3>
        <div className="recon">
          <Qty label="投入" value={r.total_in} />
          <Qty label="在手" value={r.in_hand} />
          <Qty label="完成入库" value={r.completed_good} />
          <Qty label="累计报废" value={r.scrapped} tone="scrap" />
          <Qty label="核对合计" value={r.accounted} />
        </div>
        <p className={r.balanced ? 'recon-balanced' : 'recon-broken'}>
          {r.balanced ? '✓ 数量守恒:投入 = 在手 + 完成 + 报废' : '✗ 数量不守恒,请检查异常环节'}
        </p>
      </div>

      <div className="panel">
        <h3>批次树(拆分 / 返工沿原生产单关联)</h3>
        <div className="tree">
          <TreeNodes nodes={trace.tree} onOpenBatch={onOpenBatch} />
        </div>
      </div>

      <div className="panel">
        <h3>追溯时间线</h3>
        <ul className="timeline">
          {trace.events.map((ev) => (
            <li key={ev.id} className={`ev-${ev.type}`}>
              <div className="t-head">
                <b>{EVENT_LABELS[ev.type] || ev.type}</b>
                <span>{trace.batches.find((b) => b.id === ev.batch_id)?.code}</span>
                {ev.from_process && ev.to_process && <span className="muted">{ev.from_process.name} → {ev.to_process.name}</span>}
                {!ev.from_process && ev.to_process && <span className="muted">→ {ev.to_process.name}</span>}
                {ev.actor && <span className="muted">{ev.actor.name}</span>}
                <span className="t-time">{ev.created_at?.replace('T', ' ').slice(5, 19)}</span>
              </div>
              {(ev.qty != null || ev.qty_qualified != null) && (
                <div className="t-note">
                  {ev.qty != null && `数量 ${ev.qty} `}
                  {ev.qty_qualified != null && `合格 ${ev.qty_qualified} / 返工 ${ev.qty_rework} / 报废 ${ev.qty_scrap}`}
                </div>
              )}
              {ev.note && <div className="t-note">{ev.note}</div>}
            </li>
          ))}
        </ul>
      </div>
    </>
  )
}

function TreeNodes({ nodes, onOpenBatch }) {
  return (
    <ul>
      {nodes.map((n) => (
        <li key={n.id}>
          <div className="node">
            <span className="link" onClick={() => onOpenBatch(n.id)}>{n.code}</span>
            <OriginBadge origin={n.origin} />
            <StatusBadge status={n.status} />
            <span className="muted">{n.process}{n.equipment ? ` · ${n.equipment}` : ''}{n.parameter_version ? ` · ${n.parameter_version}` : ''} · {n.qty_received} 件</span>
          </div>
          {n.children.length > 0 && <TreeNodes nodes={n.children} onOpenBatch={onOpenBatch} />}
        </li>
      ))}
    </ul>
  )
}

/** 登记产出:数量核对 + 设备读数(可指定读数复现超限) */
function OutputModal({ batch, onClose, onDone }) {
  const [form, setForm] = useState({ qty_qualified: batch.qty_received, qty_rework: 0, qty_scrap: 0 })
  const [overrides, setOverrides] = useState({})
  const params = batch.parameter_version?.params || {}
  const total = Number(form.qty_qualified) + Number(form.qty_rework) + Number(form.qty_scrap)
  const mismatch = total !== batch.qty_received

  const submit = () => onDone(() => api(`/api/batches/${batch.id}/output`, {
    method: 'POST',
    body: {
      qty_qualified: Number(form.qty_qualified),
      qty_rework: Number(form.qty_rework),
      qty_scrap: Number(form.qty_scrap),
      ...(Object.keys(overrides).length ? { readings: overrides } : {}),
    },
  }))

  return (
    <Modal title={`登记产出 · ${batch.code} · 投入 ${batch.qty_received} 件`} onClose={onClose}>
      <div className="form-row">
        {[['qty_qualified', '合格'], ['qty_rework', '返工'], ['qty_scrap', '报废']].map(([k, label]) => (
          <div className="field" key={k}>
            <label>{label}数量</label>
            <input type="number" min="0" value={form[k]}
              onChange={(e) => setForm({ ...form, [k]: e.target.value })} />
          </div>
        ))}
      </div>
      <p className={mismatch ? 'recon-broken' : 'recon-balanced'}>
        合计 {total} / 投入 {batch.qty_received} {mismatch ? '· 不符,提交后将停在待处理' : '· 守恒 ✓'}
      </p>
      {Object.keys(params).length > 0 && (
        <>
          <div className="section-title">设备读数(留空则自动模拟;可填入数值复现超限)</div>
          <div className="form-row">
            {Object.entries(params).map(([m, l]) => (
              <div className="field" key={m}>
                <label>{m}({l.min}~{l.max} {l.unit || ''})</label>
                <input type="number" step="any" placeholder="自动"
                  value={overrides[m] ?? ''}
                  onChange={(e) => {
                    const next = { ...overrides }
                    if (e.target.value === '') delete next[m]
                    else next[m] = Number(e.target.value)
                    setOverrides(next)
                  }} />
              </div>
            ))}
          </div>
          <button className="btn ghost sm" onClick={() => {
            const [m, l] = Object.entries(params)[0]
            setOverrides({ ...overrides, [m]: Math.round(l.max * 1.15 * 100) / 100 })
          }}>⚡ 模拟首项超限</button>
        </>
      )}
      <div className="actions" style={{ marginTop: 14 }}>
        <button className="btn" onClick={submit}>提交登记</button>
        <button className="btn ghost" onClick={onClose}>取消</button>
      </div>
    </Modal>
  )
}

function ReceiveModal({ batch, processes, onClose, onDone }) {
  const next = processes
    .filter((p) => p.sequence > batch.process.sequence)
    .sort((a, b) => a.sequence - b.sequence)[0]
  const [equipmentId, setEquipmentId] = useState(next?.equipments?.[0]?.id || '')
  if (!next) return null

  return (
    <Modal title={`下一工位接收 · ${batch.code}`} onClose={onClose}>
      <p className="muted">
        {batch.process.name} → <b style={{ color: 'var(--text)' }}>{next.name}</b>,
        接收合格品 {batch.qty_qualified} 件
        {batch.qty_rework > 0 && `,剩余 ${batch.qty_rework} 件返工将自动生成返工子批留在 ${batch.process.name}`}
      </p>
      <div className="form-row">
        <div className="field">
          <label>接收设备(接收时锁定该设备现行参数版本)</label>
          <select value={equipmentId} onChange={(e) => setEquipmentId(e.target.value)}>
            {next.equipments.map((eq) => (
              <option key={eq.id} value={eq.id}>{eq.name}(现行 {eq.active_parameter_version ? `v${eq.active_parameter_version.version}` : '无参数'})</option>
            ))}
          </select>
        </div>
      </div>
      <div className="actions">
        <button className="btn" disabled={!equipmentId}
          onClick={() => onDone(() => api(`/api/batches/${batch.id}/receive`, { method: 'POST', body: { equipment_id: Number(equipmentId) } }))}>
          确认接收
        </button>
        <button className="btn ghost" onClick={onClose}>取消</button>
      </div>
    </Modal>
  )
}

function SplitModal({ batch, onClose, onDone }) {
  const [parts, setParts] = useState([Math.ceil(batch.qty_received / 2), Math.floor(batch.qty_received / 2)])
  const total = parts.reduce((a, b) => a + Number(b || 0), 0)
  const valid = parts.length >= 2 && total === batch.qty_received && parts.every((p) => Number(p) >= 1)

  return (
    <Modal title={`拆分批次 · ${batch.code} · 在手 ${batch.qty_received} 件`} onClose={onClose}>
      {parts.map((p, i) => (
        <div className="form-row" key={i}>
          <div className="field">
            <label>子批 {i + 1} 数量</label>
            <input type="number" min="1" value={p}
              onChange={(e) => setParts(parts.map((x, j) => (j === i ? e.target.value : x)))} />
          </div>
          {parts.length > 2 && (
            <button className="btn ghost" onClick={() => setParts(parts.filter((_, j) => j !== i))}>移除</button>
          )}
        </div>
      ))}
      <div className="actions">
        <button className="btn ghost sm" onClick={() => setParts([...parts, 1])}>+ 增加子批</button>
      </div>
      <p className={total === batch.qty_received ? 'recon-balanced' : 'recon-broken'}>
        合计 {total} / {batch.qty_received} {total === batch.qty_received ? '· 守恒 ✓' : '· 拆分数量之和必须等于在手数量'}
      </p>
      <div className="actions">
        <button className="btn" disabled={!valid}
          onClick={() => onDone(() => api(`/api/batches/${batch.id}/split`, { method: 'POST', body: { quantities: parts.map(Number) } }))}>
          确认拆分
        </button>
        <button className="btn ghost" onClick={onClose}>取消</button>
      </div>
    </Modal>
  )
}

function ReworkModal({ batch, processes, onClose, onDone }) {
  const candidates = processes
    .filter((p) => p.sequence <= batch.process.sequence)
    .sort((a, b) => a.sequence - b.sequence)
  const [processId, setProcessId] = useState(candidates[0]?.id || '')
  const proc = processes.find((p) => p.id === Number(processId))
  const [equipmentId, setEquipmentId] = useState('')
  const [qty, setQty] = useState(batch.qty_rework)
  const eq = equipmentId || proc?.equipments?.[0]?.id || ''

  return (
    <Modal title={`返工回流 · ${batch.code} · 待返工 ${batch.qty_rework} 件`} onClose={onClose}>
      <div className="form-row">
        <div className="field">
          <label>回流到工序(当前工序或之前)</label>
          <select value={processId} onChange={(e) => { setProcessId(e.target.value); setEquipmentId('') }}>
            {candidates.map((p) => <option key={p.id} value={p.id}>{p.name}({p.code})</option>)}
          </select>
        </div>
        <div className="field">
          <label>设备</label>
          <select value={eq} onChange={(e) => setEquipmentId(e.target.value)}>
            {(proc?.equipments || []).map((e) => <option key={e.id} value={e.id}>{e.name}</option>)}
          </select>
        </div>
        <div className="field">
          <label>返工数量</label>
          <input type="number" min="1" max={batch.qty_rework} value={qty} onChange={(e) => setQty(e.target.value)} />
        </div>
      </div>
      <p className="muted">返工子批沿原生产单保存关联,父批返工数量相应扣减。</p>
      <div className="actions">
        <button className="btn" disabled={!eq || Number(qty) < 1 || Number(qty) > batch.qty_rework}
          onClick={() => onDone(() => api(`/api/batches/${batch.id}/rework`, {
            method: 'POST',
            body: { to_process_id: Number(processId), equipment_id: Number(eq), qty: Number(qty) },
          }))}>
          确认返工
        </button>
        <button className="btn ghost" onClick={onClose}>取消</button>
      </div>
    </Modal>
  )
}
