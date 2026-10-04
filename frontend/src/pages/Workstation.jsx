import { useEffect, useState, useCallback } from 'react'
import { api } from '../api.js'
import { useAuth } from '../auth.jsx'
import { ErrorBox, OkBox, Modal } from '../components/Modal.jsx'
import RouteDiagram from '../components/RouteDiagram.jsx'
import { StatusBadge, HoldBadge } from '../labels.jsx'

export default function Workstation() {
  const { user } = useAuth()
  const [batches, setBatches] = useState([])
  const [error, setError] = useState(null)
  const [msg, setMsg] = useState(null)
  const [detail, setDetail] = useState(null) // {batch, route}

  const load = useCallback(async () => {
    try { setBatches(await api('/batches?my_station=1')) }
    catch (e) { setError(e) }
  }, [])

  useEffect(() => { load() }, [load])

  async function action(fn, ok) {
    setError(null); setMsg(null)
    try { await fn(); setMsg(ok); setDetail(null); load() } catch (e) { setError(e); load() }
  }

  async function openDetail(id) {
    setError(null)
    try {
      const [b, r] = await Promise.all([api(`/batches/${id}`), api(`/batches/${id}/route`)])
      setDetail({ batch: b, route: r })
    } catch (e) { setError(e) }
  }

  return (
    <div>
      <h1 className="page-title">工位工作台</h1>
      <p className="page-desc">
        {user.station ? `${user.station.name}（${user.station.code}）` : '未分配工位'} ·
        接收排队/在途批次，登记合格·返工·报废数量；批次锁定的工艺参数版本在工序图中显示
      </p>
      <ErrorBox error={error} />
      <OkBox>{msg}</OkBox>

      <div className="card">
        <h2>本工位批次</h2>
        {batches.length === 0 && <p className="muted">暂无可处理批次。放行到本工位的批次会出现在这里。</p>}
        <table>
          <thead><tr>
            <th>批次号</th><th>生产单/产品</th><th>数量</th><th>工序</th>
            <th>设备</th><th>参数版本</th><th>状态</th><th>操作</th>
          </tr></thead>
          <tbody>
            {batches.map(b => (
              <tr key={b.id}>
                <td className="mono">
                  {b.batch_no}
                  {b.origin === 'rework' && <span className="badge b-in_transit" style={{ marginLeft: 6 }}>返工回流</span>}
                </td>
                <td>{b.order?.order_no}<div className="muted" style={{ fontSize: 11 }}>{b.order?.product_code}</div></td>
                <td><b>{b.qty}</b></td>
                <td>{b.process?.code} {b.process?.name}</td>
                <td>{b.equipment?.code || '—'}</td>
                <td>{b.param_version?.version
                  ? <span className="mono">{b.param_version.version}</span>
                  : <span className="muted">接收时锁定</span>}</td>
                <td><StatusBadge status={b.status} /> <HoldBadge reason={b.on_hold_reason} /></td>
                <td className="flex">
                  {['queued', 'released'].includes(b.status) && (
                    <button className="btn sm" onClick={() => action(() => api(`/batches/${b.id}/receive`, { method: 'POST' }),
                      `批次 ${b.batch_no} 已接收并锁定参数版本`)}>接收</button>
                  )}
                  <button className="btn sm secondary" onClick={() => openDetail(b.id)}>详情/工序图</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {detail && (
        <BatchDetail
          data={detail}
          onClose={() => setDetail(null)}
          onChanged={(m) => { setDetail(null); setMsg(m); load() }}
          setError={setError}
        />
      )}
    </div>
  )
}

function BatchDetail({ data, onClose, onChanged, setError }) {
  const { batch, route } = data
  const [tab, setTab] = useState('route')
  return (
    <Modal title={`批次 ${batch.batch_no}`} onClose={onClose}>
      <dl className="kv">
        <dt>生产单</dt><dd className="mono">{batch.order?.order_no}（{batch.order?.product_name}）</dd>
        <dt>来源</dt><dd>{batch.origin === 'root' ? '根批次' : batch.origin === 'rework' ? '返工回流' : '拆分/分流'}（深度 {batch.depth}）{batch.parent_batch && <> · 父批次 <span className="mono">{batch.parent_batch.batch_no}</span></>}</dd>
        <dt>当前数量</dt><dd><b>{batch.qty}</b></dd>
        <dt>当前位置</dt><dd>{route.current.process?.code} {route.current.process?.name} · {route.current.station?.code || '—'} · {route.current.equipment?.code || '未分配设备'}</dd>
        <dt>参数版本</dt><dd>{route.current.param_version
          ? <span className="mono">{route.current.param_version.version}（{route.current.param_version.status === 'active' ? '启用中' : '已退役但锁定'}）</span>
          : <span className="muted">尚未接收，接收时锁定本工序生效版本</span>}</dd>
      </dl>

      <div className="btn-row" style={{ borderBottom: '1px solid var(--border)', paddingBottom: 8 }}>
        <button className={`btn sm ${tab === 'route' ? '' : 'secondary'}`} onClick={() => setTab('route')}>工序图</button>
        {batch.status === 'in_progress' && <button className={`btn sm ${tab === 'produce' ? 'green' : 'secondary'}`} onClick={() => setTab('produce')}>登记产出</button>}
        {['queued', 'in_progress'].includes(batch.status) && <button className={`btn sm ${tab === 'split' ? 'amber' : 'secondary'}`} onClick={() => setTab('split')}>批次拆分</button>}
        {['queued', 'in_progress'].includes(batch.status) && <button className={`btn sm ${tab === 'scrap' ? 'red' : 'secondary'}`} onClick={() => setTab('scrap')}>报废</button>}
      </div>

      <div style={{ marginTop: 14 }}>
        {tab === 'route' && <RouteTab route={route} />}
        {tab === 'produce' && <ProduceTab batch={batch} items={route.current.param_items} onDone={onChanged} setError={setError} />}
        {tab === 'split' && <SplitTab batch={batch} onDone={onChanged} setError={setError} />}
        {tab === 'scrap' && <ScrapTab batch={batch} onDone={onChanged} setError={setError} />}
      </div>
    </Modal>
  )
}

function RouteTab({ route }) {
  return (
    <>
      <RouteDiagram route={route.route} current={route.current} />
      {route.pending_transfer && (
        <p className="hint">在途预定：{route.pending_transfer.to_station} / {route.pending_transfer.to_process}，
          携带参数版本 <b className="mono">{route.pending_transfer.carried_param_version}</b>（其他工位不可占用）</p>
      )}
      {route.current.param_items && route.current.param_items.length > 0 && (
        <>
          <h2 style={{ marginTop: 16 }}>锁定的工艺参数（{route.current.param_version.version}）</h2>
          <table>
            <thead><tr><th>参数</th><th>设定值</th><th>允许范围</th><th>单位</th></tr></thead>
            <tbody>
              {route.current.param_items.map(i => (
                <tr key={i.code}>
                  <td>{i.name} <span className="muted mono">{i.code}</span></td>
                  <td className="mono">{i.set_value}</td>
                  <td className="mono">[{i.min_value}, {i.max_value}]</td>
                  <td>{i.unit}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </>
      )}
    </>
  )
}

function ProduceTab({ batch, items, onDone, setError }) {
  const [equipmentId, setEquipmentId] = useState('')
  const [good, setGood] = useState(batch.qty)
  const [rework, setRework] = useState(0)
  const [scrap, setScrap] = useState(0)
  const [params, setParams] = useState(() => Object.fromEntries((items || []).map(i => [i.code, i.set_value])))
  const [auto, setAuto] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)

  const sum = Number(good) + Number(rework) + Number(scrap)
  const qtyOk = sum === batch.qty
  const inRange = (i, v) => Number(v) >= i.min_value && Number(v) <= i.max_value

  async function simulate() {
    setErr(null)
    try {
      const d = await api(`/batches/${batch.id}/reading-preview`)
      setParams(p => ({ ...p, ...d.payload }))
      setAuto(false)
    } catch (e) { setErr(e) }
  }

  async function submit() {
    setErr(null); setBusy(true)
    try {
      const body = {
        equipment_id: Number(equipmentId),
        good_qty: Number(good), rework_qty: Number(rework), scrap_qty: Number(scrap),
        shift: 'DAY',
      }
      if (!auto) body.actual_params = Object.fromEntries(Object.entries(params).map(([k, v]) => [k, Number(v)]))
      await api(`/batches/${batch.id}/production`, { method: 'POST', body })
      if (!qtyOk) onDone('登记成功但数量不符，批次已进入待处理状态（不得转序，等待处置）')
      else if (Object.entries(params).some(([code, v]) => { const it = items.find(x => x.code === code); return it && !inRange(it, v) }))
        onDone('登记成功但参数超限，批次已进入待处理状态（等待工艺工程师处置）')
      else onDone('产出登记成功，已转入待质检')
    } catch (e) { setErr(e) } finally { setBusy(false) }
  }

  return (
    <div>
      <ErrorBox error={err} />
      <label>生产设备</label>
      <StationEquipmentPicker stationId={batch.station_id} value={equipmentId} onChange={setEquipmentId} />

      <div className="grid grid-3" style={{ marginTop: 8 }}>
        <div><label>合格数量</label><input type="number" min={0} value={good} onChange={e => setGood(e.target.value)} /></div>
        <div><label>返工数量</label><input type="number" min={0} value={rework} onChange={e => setRework(e.target.value)} /></div>
        <div><label>报废数量</label><input type="number" min={0} value={scrap} onChange={e => setScrap(e.target.value)} /></div>
      </div>
      <p className={qtyOk ? 'hint' : ''} style={!qtyOk ? { color: '#ff8b82' } : undefined}>
        合计 {sum} / 投入 {batch.qty} {qtyOk ? '✓ 守恒' : '✗ 不符将挂起，不得转序'}
      </p>

      <div className="flex-between" style={{ marginTop: 6 }}>
        <h2 style={{ margin: 0 }}>设备实测参数</h2>
        <span className="flex">
          <label style={{ margin: 0 }} className="flex"><input type="checkbox" style={{ width: 'auto' }} checked={auto} onChange={e => setAuto(e.target.checked)} /> 本地自动采集（模拟）</label>
          <button className="btn sm secondary" onClick={simulate}>生成一次模拟读数</button>
        </span>
      </div>
      {!auto && items && (
        <table>
          <thead><tr><th>参数</th><th>实测值</th><th>允许范围</th><th>判定</th></tr></thead>
          <tbody>
            {items.map(i => {
              const v = params[i.code]
              const ok = inRange(i, v)
              return (
                <tr key={i.code}>
                  <td>{i.name} <span className="muted mono">{i.code}</span></td>
                  <td style={{ width: 150 }}><input type="number" step="0.01" value={v}
                    onChange={e => setParams({ ...params, [i.code]: e.target.value })} /></td>
                  <td className="mono">[{i.min_value}, {i.max_value}] {i.unit}</td>
                  <td>{ok ? <span className="check-pass">范围内</span> : <span className="check-fail">超限→挂起</span>}</td>
                </tr>
              )
            })}
          </tbody>
        </table>
      )}
      {auto && <p className="hint">提交时由设备模拟器按锁定版本设定值附近漂移自动生成读数（无需手填）。</p>}

      <div className="btn-row">
        <button className="btn" disabled={busy || !equipmentId} onClick={submit}>登记产出</button>
      </div>
    </div>
  )
}

function StationEquipmentPicker({ stationId, value, onChange }) {
  const [list, setList] = useState([])
  useEffect(() => {
    if (stationId) api(`/stations/${stationId}/equipment`).then(setList).catch(() => {})
  }, [stationId])
  return (
    <select value={value} onChange={e => onChange(e.target.value)} style={{ marginTop: 4 }}>
      <option value="">请选择设备…</option>
      {list.map(eq => <option key={eq.id} value={eq.id}>{eq.code} · {eq.name}</option>)}
    </select>
  )
}

function SplitTab({ batch, onDone, setError }) {
  const [text, setText] = useState(`${Math.ceil(batch.qty / 2)}, ${Math.floor(batch.qty / 2)}`)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)
  const qs = text.split(/[,\s]+/).filter(Boolean).map(Number)
  const sum = qs.reduce((a, b) => a + (Number.isFinite(b) ? b : 0), 0)
  async function submit() {
    setErr(null); setBusy(true)
    try { await api(`/batches/${batch.id}/split`, { method: 'POST', body: { quantities: qs } }); onDone(`批次已拆分为 ${qs.join(' + ')}，沿原生产单关联`) }
    catch (e) { setErr(e) } finally { setBusy(false) }
  }
  return (
    <div>
      <ErrorBox error={err} />
      <p className="hint">拆分仅允许在当前工序开工前进行；各子批数量之和必须等于原批次数量 <b>{batch.qty}</b>。</p>
      <label>子批次数量（逗号分隔）</label>
      <input value={text} onChange={e => setText(e.target.value)} />
      <p style={{ color: sum === batch.qty ? '#7ee787' : '#ff8b82' }} className="hint">合计 {sum} / {batch.qty}</p>
      <div className="btn-row"><button className="btn amber" disabled={busy || sum !== batch.qty || qs.length < 2} onClick={submit}>确认拆分</button></div>
    </div>
  )
}

function ScrapTab({ batch, onDone, setError }) {
  const [qty, setQty] = useState(batch.qty)
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)
  async function submit() {
    setErr(null); setBusy(true)
    try { await api(`/batches/${batch.id}/scrap`, { method: 'POST', body: { quantity: Number(qty), reason } }); onDone(`已报废 ${qty} 件（沿原生产单留痕）`) }
    catch (e) { setErr(e) } finally { setBusy(false) }
  }
  return (
    <div>
      <ErrorBox error={err} />
      <label>报废数量（1 ~ {batch.qty}）</label>
      <input type="number" min={1} max={batch.qty} value={qty} onChange={e => setQty(e.target.value)} />
      <label>原因</label>
      <input value={reason} onChange={e => setReason(e.target.value)} placeholder="如：原材料裂纹" />
      <div className="btn-row"><button className="btn red" disabled={busy} onClick={submit}>确认报废</button></div>
    </div>
  )
}
