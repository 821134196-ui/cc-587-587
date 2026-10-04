const STATE_LABELS = {
  pending: '未到达',
  waiting: '排队等待',
  in_transit: '转序在途',
  running: '加工中',
  qc_pending: '待质检',
  on_hold: '待处理',
  done: '已完成',
}

export default function RouteDiagram({ route, current }) {
  return (
    <div className="route">
      {route.map(s => (
        <div key={s.code} className={`route-step current-${s.is_current} state-${s.state}`}>
          <div className="seq">工序 {s.sequence} · {s.station_code}</div>
          <div className="nm">{s.code} {s.name}</div>
          <div className="st">
            <span className={`badge b-${s.state}`}>{STATE_LABELS[s.state] || s.state}</span>
            {s.is_current && s.param_version && (
              <span className="badge b-released" style={{ marginLeft: 6 }}>参数 {s.param_version}</span>
            )}
          </div>
          {s.is_current && current?.equipment && (
            <div className="st" style={{ marginTop: 6 }}>设备：{current.equipment.code} {current.equipment.name}</div>
          )}
        </div>
      ))}
    </div>
  )
}
