import { OriginBadge, StatusBadge } from '../ui'

/** 批次列表:全部批次(含已拆分/已完成),点击进追溯 */
export default function Batches({ batches, onOpenBatch }) {
  return (
    <div className="page">
      <h2>批次台账</h2>
      <div className="panel">
        <table className="grid">
          <thead>
            <tr>
              <th>批次号</th><th>来源</th><th>生产单</th><th>当前工序</th><th>设备</th>
              <th>参数版本</th><th>投入</th><th>合格</th><th>返工</th><th>报废</th><th>状态</th>
            </tr>
          </thead>
          <tbody>
            {batches.map((b) => (
              <tr key={b.id} className="clickable" onClick={() => onOpenBatch(b.id)}>
                <td>{b.code}</td>
                <td><OriginBadge origin={b.origin} /></td>
                <td>{b.order?.code}</td>
                <td>{b.process?.name}</td>
                <td>{b.equipment?.name || '—'}</td>
                <td>{b.parameter_version ? `v${b.parameter_version.version}` : '—'}</td>
                <td>{b.qty_received}</td>
                <td>{b.qty_qualified}</td>
                <td>{b.qty_rework}</td>
                <td>{b.qty_scrap}</td>
                <td><StatusBadge status={b.status} /></td>
              </tr>
            ))}
          </tbody>
        </table>
        {batches.length === 0 && <div className="empty-tip">暂无批次</div>}
      </div>
    </div>
  )
}
