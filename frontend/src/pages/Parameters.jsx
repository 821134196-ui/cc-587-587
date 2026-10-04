import { useEffect, useState } from 'react'
import { api } from '../api'
import { ErrorBox, Modal } from '../ui'

/** 参数版本管理:查看各设备版本历史,发起换版;在制批次仍锁定原版本 */
export default function Parameters({ processes, onChanged }) {
  const [selected, setSelected] = useState(null) // equipment
  const [versions, setVersions] = useState([])
  const [showRevise, setShowRevise] = useState(false)
  const [error, setError] = useState(null)

  const loadVersions = async (eq) => {
    setVersions(await api(`/api/equipments/${eq.id}/parameter-versions`))
  }

  const openEquipment = async (eq) => {
    setSelected(eq)
    setError(null)
    await loadVersions(eq)
  }

  return (
    <div className="page">
      <h2>工艺参数版本</h2>
      <div className="panel">
        <h3>设备清单(点击查看版本历史 / 换版)</h3>
        <table className="grid">
          <thead><tr><th>工序</th><th>设备</th><th>现行版本</th><th>操作</th></tr></thead>
          <tbody>
            {processes.flatMap((p) => p.equipments.map((eq) => (
              <tr key={eq.id}>
                <td>{p.name}</td>
                <td>{eq.name}({eq.code})</td>
                <td>{eq.active_parameter_version ? `v${eq.active_parameter_version.version}` : '—'}</td>
                <td><button className="btn sm" onClick={() => openEquipment(eq)}>版本管理</button></td>
              </tr>
            )))}
          </tbody>
        </table>
      </div>

      {selected && (
        <div className="panel">
          <h3>{selected.name} · 版本历史</h3>
          <ErrorBox error={error} onClose={() => setError(null)} />
          <div className="actions" style={{ marginBottom: 12 }}>
            <button className="btn" onClick={() => setShowRevise(true)}>发起换版(新版本生效,在制批次不受影响)</button>
          </div>
          {versions.map((v) => (
            <div key={v.id} className="panel" style={{ background: 'var(--panel-2)' }}>
              <h3>
                v{v.version}{' '}
                <span className={`badge ${v.status === 'active' ? 'st-released' : 'st-split'}`}>
                  {v.status === 'active' ? '现行' : '已作废'}
                </span>
                <span className="muted" style={{ marginLeft: 8 }}>
                  {v.creator?.name || '系统'} · {v.created_at?.replace('T', ' ').slice(0, 19)}
                </span>
              </h3>
              <table className="grid">
                <thead><tr><th>指标</th><th>下限</th><th>目标</th><th>上限</th><th>单位</th></tr></thead>
                <tbody>
                  {Object.entries(v.params).map(([m, l]) => (
                    <tr key={m}><td>{m}</td><td>{l.min}</td><td>{l.target ?? '—'}</td><td>{l.max}</td><td>{l.unit || ''}</td></tr>
                  ))}
                </tbody>
              </table>
            </div>
          ))}
        </div>
      )}

      {showRevise && selected && (
        <ReviseModal
          equipment={selected}
          latest={versions.find((v) => v.status === 'active')}
          onClose={() => setShowRevise(false)}
          onDone={async (params) => {
            setError(null)
            try {
              await api(`/api/equipments/${selected.id}/parameter-versions`, { method: 'POST', body: { params } })
              setShowRevise(false)
              await loadVersions(selected)
              onChanged()
            } catch (e) {
              setError(e)
              setShowRevise(false)
            }
          }}
        />
      )}
    </div>
  )
}

function ReviseModal({ equipment, latest, onClose, onDone }) {
  const [rows, setRows] = useState(
    Object.entries(latest?.params || { 温度: { min: 0, max: 100, target: 50, unit: '' } })
      .map(([metric, l]) => ({ metric, ...l }))
  )

  const update = (i, key, val) => setRows(rows.map((r, j) => (j === i ? { ...r, [key]: val } : r)))

  const submit = () => {
    const params = {}
    for (const r of rows) {
      if (!r.metric) return
      params[r.metric] = {
        min: Number(r.min),
        max: Number(r.max),
        ...(r.target !== '' && r.target != null ? { target: Number(r.target) } : {}),
        ...(r.unit ? { unit: r.unit } : {}),
      }
    }
    onDone(params)
  }

  return (
    <Modal title={`${equipment.name} · 参数换版(当前 v${latest?.version ?? 0} → v${(latest?.version ?? 0) + 1})`} onClose={onClose}>
      {rows.map((r, i) => (
        <div className="form-row" key={i}>
          <div className="field"><label>指标</label>
            <input value={r.metric} onChange={(e) => update(i, 'metric', e.target.value)} /></div>
          <div className="field"><label>下限</label>
            <input type="number" step="any" value={r.min} onChange={(e) => update(i, 'min', e.target.value)} /></div>
          <div className="field"><label>目标</label>
            <input type="number" step="any" value={r.target ?? ''} onChange={(e) => update(i, 'target', e.target.value)} /></div>
          <div className="field"><label>上限</label>
            <input type="number" step="any" value={r.max} onChange={(e) => update(i, 'max', e.target.value)} /></div>
          <div className="field"><label>单位</label>
            <input value={r.unit || ''} onChange={(e) => update(i, 'unit', e.target.value)} /></div>
          {rows.length > 1 && <button className="btn ghost" onClick={() => setRows(rows.filter((_, j) => j !== i))}>✕</button>}
        </div>
      ))}
      <div className="actions" style={{ marginBottom: 10 }}>
        <button className="btn ghost sm" onClick={() => setRows([...rows, { metric: '', min: 0, max: 0, target: '', unit: '' }])}>+ 增加指标</button>
      </div>
      <p className="muted">换版后旧版本作废;已锁定旧版本的在制批次仍按原版本判定。</p>
      <div className="actions">
        <button className="btn" onClick={submit}>确认换版</button>
        <button className="btn ghost" onClick={onClose}>取消</button>
      </div>
    </Modal>
  )
}
