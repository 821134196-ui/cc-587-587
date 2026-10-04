import { createContext, useContext, useState } from 'react'
import { api, clearAuth, getToken, setAuth } from './api.js'

const AuthCtx = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(() => {
    try { return JSON.parse(localStorage.getItem('user')) } catch { return null }
  })

  async function login(email, password) {
    const d = await api('/auth/login', { method: 'POST', body: { email, password } })
    setAuth(d.token, d.user)
    setUser(d.user)
  }
  function logout() {
    clearAuth()
    setUser(null)
  }
  const authed = !!getToken()
  return <AuthCtx.Provider value={{ user, authed, login, logout }}>{children}</AuthCtx.Provider>
}

export const useAuth = () => useContext(AuthCtx)
