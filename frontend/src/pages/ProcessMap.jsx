import { OriginBadge, StatusBadge } from '../ui'

/** 工序图:每道工序一列,卡片显示批次当前所在设备、工序和参数版本 */
export default function ProcessMap({ map, onOpenBatch }) {
  return (
    <div className="page">
      <h2>工序图 · 在制批次实时位置</h2>
      <div className="map-row">
        {map.map((station, i) => (
          <div key={station.id} style={{ display: 'flex', gap: 14, flex: 1 }}>
            <div className="station">
              <div className="station-head">
                <h3>{station.name}</h3>
                <span className="station-seq">{station.code}</span>
              </div>
              <div className="station-eq">
                {station.equipments.map((e) => e.name).join(' / ') || '无设备'}
              </div>
              {station.batches.length === 0 && <div className="empty-tip">暂无在制批次</div>}
              {station.batches.map((b) => (
                <div className="batch-card" key={b.id} onClick={() => onOpenBatch(b.id)}>
                  <div className="code">
                    {b.code} <OriginBadge origin={b.origin} /> <StatusBadge status={b.status} />
                  </div>
                  <div className="meta">
                    设备 <b>{b.equipment?.name || '—'}</b> · 参数 <b>{b.parameter_version ? `v${b.parameter_version.version}` : '—'}</b><br />
                    在手 <b>{b.qty_received}</b> 件
                    {b.status === 'pending_qc' && ` · 合格 ${b.qty_qualified} / 返工 ${b.qty_rework} / 报废 ${b.qty_scrap}`}
                    {b.hold_reason && <div style={{ color: 'var(--red)' }}>⛔ {b.hold_reason}</div>}
                  </div>
                </div>
              ))}
            </div>
            {i < map.length - 1 && <div className="station-arrow">→</div>}
          </div>
        ))}
      </div>
    </div>
  )
}
