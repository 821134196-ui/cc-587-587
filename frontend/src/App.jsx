import { useCallback, useEffect, useState } from 'react'
import { api, getUserId, setUserId } from './api'
import BatchDetail from './BatchDetail'
import Batches from './pages/Batches'
import Orders from './pages/Orders'
import Parameters from './pages/Parameters'
import ProcessMap from './pages/ProcessMap'

const TABS = [
  ['map', '工序图'],
  ['batches', '批次台账'],
  ['orders', '生产单'],
  ['params', '参数版本'],
]

export default function App() {
  const [tab, setTab] = useState('map')
  const [users, setUsers] = useState([])
  const [currentUser, setCurrentUser] = useState(getUserId())
  const [processes, setProcesses] = useState([])
  const [map, setMap] = useState([])
  const [batches, setBatches] = useState([])
  const [orders, setOrders] = useState([])
  const [openBatchId, setOpenBatchId] = useState(null)

  const reload = useCallback(async () => {
    const [p, m, b, o] = await Promise.all([
      api('/api/processes'),
      api('/api/process-map'),
      api('/api/batches'),
      api('/api/orders'),
    ])
    setProcesses(p)
    setMap(m)
    setBatches(b)
    setOrders(o)
  }, [])

  useEffect(() => {
    api('/api/users').then((list) => {
      setUsers(list)
      if (!getUserId() && list.length > 0) {
        setUserId(String(list[0].id))
        setCurrentUser(String(list[0].id))
      }
    })
  }, [])

  useEffect(() => { reload() }, [reload])

  const pickUser = (id) => {
    setUserId(id)
    setCurrentUser(id)
  }

  return (
    <>
      <div className="topbar">
        <h1>🏭 半成品转序与工艺参数锁定</h1>
        <nav>
          {TABS.map(([key, label]) => (
            <button key={key} className={tab === key ? 'active' : ''} onClick={() => setTab(key)}>{label}</button>
          ))}
        </nav>
        <div className="user-picker">
          当前用户
          <select value={currentUser} onChange={(e) => pickUser(e.target.value)}>
            {users.map((u) => (
              <option key={u.id} value={u.id}>{u.name}</option>
            ))}
          </select>
        </div>
      </div>

      {tab === 'map' && <ProcessMap map={map} onOpenBatch={setOpenBatchId} />}
      {tab === 'batches' && <Batches batches={batches} onOpenBatch={setOpenBatchId} />}
      {tab === 'orders' && <Orders orders={orders} onChanged={reload} onOpenBatch={setOpenBatchId} />}
      {tab === 'params' && <Parameters processes={processes} onChanged={reload} />}

      {openBatchId && (
        <BatchDetail
          batchId={openBatchId}
          processes={processes}
          onClose={() => setOpenBatchId(null)}
          onChanged={reload}
          onOpenBatch={setOpenBatchId}
        />
      )}
    </>
  )
}
