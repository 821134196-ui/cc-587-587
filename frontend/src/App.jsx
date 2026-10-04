import { NavLink, Route, Routes, Navigate } from 'react-router-dom'
import { useAuth } from './auth.jsx'
import Login from './pages/Login.jsx'
import Dashboard from './pages/Dashboard.jsx'
import Workstation from './pages/Workstation.jsx'
import QcBoard from './pages/QcBoard.jsx'
import Holds from './pages/Holds.jsx'
import OrderTrace from './pages/OrderTrace.jsx'
import Params from './pages/Params.jsx'
import { ROLE_LABELS } from './labels.jsx'

export default function App() {
  const { authed, user, logout } = useAuth()
  if (!authed) {
    return (
      <Routes>
        <Route path="*" element={<Login />} />
      </Routes>
    )
  }

  const nav = [
    ['/', '生产看板'],
    ['/workstation', '工位工作台'],
    ['/qc', '质检放行'],
    ['/holds', '待处理'],
    ['/trace', '追溯链'],
    ['/params', '工艺参数'],
  ]

  return (
    <div className="layout">
      <aside className="sidebar">
        <h1>半成品转序系统</h1>
        <div className="sub">工艺参数锁定 · 批次追溯</div>
        {nav.map(([to, label]) => (
          <NavLink key={to} to={to} end={to === '/'}
            className={({ isActive }) => 'nav-item' + (isActive ? ' active' : '')}>
            {label}
          </NavLink>
        ))}
        <div className="userbox">
          <b>{user.display_name}</b>
          <span className="badge b-role">{ROLE_LABELS[user.role] || user.role}</span>
          {user.station && <div style={{ marginTop: 6 }}>{user.station.name}（{user.station.code}）</div>}
          <button className="btn secondary sm" style={{ marginTop: 10 }} onClick={logout}>退出登录</button>
        </div>
      </aside>
      <main className="main">
        <Routes>
          <Route path="/" element={<Dashboard />} />
          <Route path="/workstation" element={<Workstation />} />
          <Route path="/qc" element={<QcBoard />} />
          <Route path="/holds" element={<Holds />} />
          <Route path="/trace" element={<OrderTrace />} />
          <Route path="/trace/:orderId" element={<OrderTrace />} />
          <Route path="/params" element={<Params />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </main>
    </div>
  )
}
