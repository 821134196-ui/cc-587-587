const KEY = 'fm_user_id'

export function getUserId() {
  return localStorage.getItem(KEY) || ''
}

export function setUserId(id) {
  localStorage.setItem(KEY, id)
}

export async function api(path, { method = 'GET', body } = {}) {
  const headers = { Accept: 'application/json' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  const uid = getUserId()
  if (uid) headers['X-User-Id'] = uid

  const res = await fetch(path, {
    method,
    headers,
    body: body !== undefined ? JSON.stringify(body) : undefined,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const err = new Error(data.message || `请求失败 (${res.status})`)
    err.status = res.status
    err.errors = data.errors || {}
    throw err
  }
  return data
}
