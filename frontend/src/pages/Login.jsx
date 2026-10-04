import { useState } from 'react'
import { useAuth } from '../auth.jsx'
import { ErrorBox } from '../components/Modal.jsx'

const ACCOUNTS = [
  ['cut@factory.local', '下料工位操作员'],
  ['cnc@factory.local', '机加工位操作员'],
  ['weld@factory.local', '焊接工位操作员'],
  ['qc@factory.local', '质检员'],
  ['eng@factory.local', '工艺工程师'],
  ['admin@factory.local', '管理员'],
]

export default function Login() {
  const { login } = useAuth()
  const [email, setEmail] = useState('cut@factory.local')
  const [password, setPassword] = useState('password')
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  async function submit(e) {
    e.preventDefault()
    setError(null); setBusy(true)
    try { await login(email, password) } catch (err) { setError(err) } finally { setBusy(false) }
  }

  return (
    <div className="login-wrap">
      <div className="card">
        <h1 className="page-title">半成品转序与工艺参数锁定系统</h1>
        <p className="page-desc" style={{ marginBottom: 18 }}>请使用工位账号登录</p>
        <form onSubmit={submit}>
          <ErrorBox error={error} />
          <label>邮箱</label>
          <input value={email} onChange={(e) => setEmail(e.target.value)} />
          <label>密码</label>
          <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} />
          <div className="btn-row">
            <button className="btn" disabled={busy}>{busy ? '登录中…' : '登 录'}</button>
          </div>
        </form>
        <div className="accounts">
          <div style={{ marginBottom: 6 }}>演示账号（密码均为 <code onClick={() => setPassword('password')}>password</code>，点击填充）：</div>
          {ACCOUNTS.map(([em, label]) => (
            <div key={em}><code onClick={() => setEmail(em)}>{em}</code> — {label}</div>
          ))}
        </div>
      </div>
    </div>
  )
}
