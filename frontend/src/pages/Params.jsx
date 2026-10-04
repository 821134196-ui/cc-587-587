import { useEffect, useState, useCallback } from 'react'
import { api } from '../api.js'
import { useAuth } from '../auth.jsx'
import { ErrorBox, OkBox, Modal } from '../components/Modal.jsx'

export default function Params() {
  const { user } = useAuth()
  const canEdit = ['engineer', 'admin'].includes(user.role)
  const [processes, setProcesses] = useState([])
  const [error, setError] = useState(null)
  const [msg, setMsg] = useState(null)
  const [target, setTarget] = useState(null)

  const load = useCallback(async () => {
    setError(null)
    try { setProcesses(await api('/processes')) } catch (e) { setError(e) }
  }, [])
  useEffect(() => { load() }, [load])

  async function activate(v) {
    setError(null); setMsg(null)
    if (!confirm(`确认激活版本 ${v.version}？同工序旧版本将退役（已锁定旧版本的在制批次不受影响）。`)) return
    try { await api(`/parameter-versions/${v.id}/activate`, { method: 'POST' }); setMsg(`版本 ${v.version} 已激活`); load() }
    catch (e) { setError(e) }
  }

  // 按产品分组
  const products = {}
  for (const p of processes) (products[p.product_code] ||= []).push(p)

  return (
    <div>
      <h1 className="page-title">工艺参数版本</h1>
      <p className="page-desc">
        版本一旦激活即不可变；<b>换版 = 新建版本并激活</b>。
        已在制批次继续使用其锁定的旧版本，新接收批次才使用新版本——实现参数锁定与可追溯。
      </p>
      <ErrorBox error={error} />
      <OkBox>{msg}</OkBox>

      {Object.entries(products).map(([code, procs]) => (
        <div className="card" key={code}>
          <h2>产品 {code}</h2>
          {procs.map(p => {
            const active = p.versions?.find(v => v.status === 'active')
            return (
              <div key={p.id} style={{ borderTop: '1px solid var(--border)', paddingTop: 12, marginTop: 12 }}>
                <div className="flex-between">
                  <div>
                    <b>{p.code} {p.name}</b>
                    <span className="muted"> · {p.station?.name}（{p.station?.code}）</span>
                    <span className="pill-param" style={{ marginLeft: 8 }}>
                      参数项 {p.templates?.length || 0}
                    </span>
                  </div>
                  <span className="flex">
                    {active
                      ? <span className="badge b-completed">当前生效：{active.version}</span>
                      : <span className="badge b-on_hold">无生效版本</span>}
                    {canEdit && <button className="btn sm" style={{ marginLeft: 8 }} onClick={async () => {
                      try {
                        const t = await api(`/processes/${p.id}/templates`)
                        setTarget({ templates: t, process: p })
                      } catch (e) { setError(e) }
                    }}>+ 新版本换版</button>}
                  </span>
                </div>
                <table style={{ marginTop: 8 }}>
                  <thead><tr><th>版本</th><th>状态</th><th>变更说明</th><th>激活时间</th><th></th></tr></thead>
                  <tbody>
                    {(p.versions || []).map(v => (
                      <tr key={v.id}>
                        <td className="mono">{v.version}</td>
                        <td><span className={`badge ${v.status === 'active' ? 'b-completed' : v.status === 'retired' ? 'b-split' : 'b-queued'}`}>
                          {v.status === 'active' ? '生效中' : v.status === 'retired' ? '已退役' : '草稿'}
                        </span></td>
                        <td className="muted" style={{ fontSize: 12 }}>{v.change_note}</td>
                        <td className="muted" style={{ fontSize: 12 }}>{v.activated_at || '—'}</td>
                        <td>
                          {canEdit && v.status !== 'active' && v.items?.length > 0 && (
                            <button className="btn sm secondary" onClick={() => activate(v)}>激活</button>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )
          })}
        </div>
      ))}

      {target && <NewVersionModal data={target} onClose={() => setTarget(null)}
        onDone={(v) => { setTarget(null); setMsg(`版本 ${v} 已创建并激活，换版完成`); load() }} />}
    </div>
  )
}

function NewVersionModal({ data, onClose, onDone }) {
  const { templates, process } = data
  const tpls = templates.templates || []
  const active = templates.active_version
  const [version, setVersion] = useState(active ? bumpVersion(active.version) : 'v1')
  const [note, setNote] = useState('')
  const [activateNow, setActivateNow] = useState(true)
  const [items, setItems] = useState(() =>
    tpls.map(t => {
      const old = active?.items?.find(i => i.parameter_template_id === t.id)
      return {
        parameter_template_id: t.id,
        code: t.code, name: t.name, unit: t.unit,
        set_value: old ? Number(old.set_value) : Number(t.min_value),
        min_value: old ? Number(old.min_value) : Number(t.min_value),
        max_value: old ? Number(old.max_value) : Number(t.max_value),
      }
    }))
  const [err, setErr] = useState(null)
  const [busy, setBusy] = useState(false)

  function upd(id, key, val) {
    setItems(items.map(i => i.parameter_template_id === id ? { ...i, [key]: Number(val) } : i))
  }

  async function submit() {
    setErr(null); setBusy(true)
    try {
      const d = await api('/parameter-versions', {
        method: 'POST',
        body: {
          process_id: process.id, version, change_note: note, activate: activateNow,
          items: items.map(({ parameter_template_id, set_value, min_value, max_value }) =>
            ({ parameter_template_id, set_value, min_value, max_value })),
        },
      })
      onDone(d.version)
    } catch (e) { setErr(e) } finally { setBusy(false) }
  }

  return (
    <Modal title={`新版本换版 · ${process.code} ${process.name}`} onClose={onClose} footer={
      <>
        <button className="btn secondary" onClick={onClose}>取消</button>
        <button className="btn" disabled={busy} onClick={submit}>创建版本{activateNow ? '并激活（换版）' : '（存草稿）'}</button>
      </>
    }>
      <ErrorBox error={err} />
      <div className="grid grid-2">
        <div><label>新版本号</label><input value={version} onChange={e => setVersion(e.target.value)} /></div>
        <div>
          <label>基于</label>
          <div style={{ paddingTop: 8 }} className="muted">
            {active ? `当前生效版本 ${active.version}（可改其上下限/设定值）` : '参数模板默认范围'}
          </div>
        </div>
      </div>
      <label>变更说明</label>
      <input value={note} onChange={e => setNote(e.target.value)} placeholder="如：收紧温度上限" />
      <label className="flex" style={{ marginTop: 10 }}>
        <input type="checkbox" style={{ width: 'auto', marginRight: 8 }} checked={activateNow}
          onChange={e => setActivateNow(e.target.checked)} />
        立即激活（旧版本转为已退役，在制批次仍锁定旧版本）
      </label>

      <table style={{ marginTop: 12 }}>
        <thead><tr><th>参数</th><th>设定值</th><th>下限</th><th>上限</th></tr></thead>
        <tbody>
          {items.map(i => (
            <tr key={i.parameter_template_id}>
              <td>{i.name} <span className="muted mono">{i.code}</span></td>
              <td><input type="number" step="0.01" value={i.set_value} onChange={e => upd(i.parameter_template_id, 'set_value', e.target.value)} /></td>
              <td><input type="number" step="0.01" value={i.min_value} onChange={e => upd(i.parameter_template_id, 'min_value', e.target.value)} /></td>
              <td><input type="number" step="0.01" value={i.max_value} onChange={e => upd(i.parameter_template_id, 'max_value', e.target.value)} /></td>
            </tr>
          ))}
        </tbody>
      </table>
    </Modal>
  )
}

function bumpVersion(v) {
  const m = /^v?(\d+)$/.exec(v)
  return m ? `v${Number(m[1]) + 1}` : v + '-new'
}
