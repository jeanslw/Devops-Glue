/**
 * 内存版 Mock LDAP 服务器 —— 用于在无真实 OpenLDAP 的情况下，端到端验证
 * PHP ext-ldap 的完整登录流程（connect → admin bind → search → user bind）。
 *
 * 用法：
 *   cd tests/ldap-mock
 *   npm install
 *   node server.js [port]      # 默认 1389，监听 127.0.0.1
 *
 * 预置账号（对应 tests/ldap_login_smoke.php 的配置）：
 *   管理员： cn=admin,dc=example,dc=com  /  adminpass
 *   用户 alice： cn=alice,ou=users,dc=example,dc=com  /  alicepass
 *   用户 bob：   cn=bob,ou=users,dc=example,dc=com    /  bobpass
 *
 * 仅支持 simple bind + 单层子树搜索，足够覆盖 LdapService 的
 * 「管理员搜索模式」（bind_dn + base_dn + user_filter=(uid=%s)）。
 */

'use strict'

const ldap = require('ldapjs')

const BASE_DN = 'dc=example,dc=com'
const ADMIN_DN = 'cn=admin,dc=example,dc=com'
const ADMIN_PASSWORD = 'adminpass'

// 用户表：dn → { password, attributes }
const users = {
  'cn=alice,ou=users,dc=example,dc=com': {
    password: 'alicepass',
    attributes: {
      uid: ['alice'],
      cn: ['alice'],
      sn: ['Alice'],
      mail: ['alice@example.com'],
      objectClass: ['inetOrgPerson'],
    },
  },
  'cn=bob,ou=users,dc=example,dc=com': {
    password: 'bobpass',
    attributes: {
      uid: ['bob'],
      cn: ['bob'],
      sn: ['Bob'],
      mail: ['bob@example.com'],
      objectClass: ['inetOrgPerson'],
    },
  },
}

const server = ldap.createServer()

// ── bind：管理员 + 普通用户统一在此处理 ──
server.bind(BASE_DN, (req, res, next) => {
  const dn = String(req.dn).toLowerCase()

  // 管理员绑定
  if (dn === ADMIN_DN.toLowerCase()) {
    if (req.credentials === ADMIN_PASSWORD) {
      res.end()
      return next()
    }
    return next(new ldap.InvalidCredentialsError('invalid admin credentials'))
  }

  // 普通用户绑定（密码校验）
  const user = users[normalizeDn(String(req.dn))]
  if (user && req.credentials === user.password) {
    res.end()
    return next()
  }
  return next(new ldap.InvalidCredentialsError('invalid credentials'))
})

// ── search：按 (uid=xxx) 过滤返回用户条目 ──
server.search(BASE_DN, (req, res, next) => {
  const filterStr = req.filter ? String(req.filter) : ''
  // 粗解析 (uid=xxx)，够用即可；也兼容 (objectClass=*) 等
  const m = filterStr.match(/\(uid=([^)]*)\)/)
  const uid = m ? m[1] : ''

  for (const [dn, entry] of Object.entries(users)) {
    if (uid && entry.attributes.uid[0] !== uid) continue
    res.send({ dn, attributes: entry.attributes })
  }
  res.end()
  return next()
})

// 规范化 DN 大小写（ldapjs 的 toString 会保留值大小写、属性名小写）
function normalizeDn(dn) {
  return String(dn).toLowerCase().replace(/\s+/g, '')
}

const port = Number(process.argv[2]) || 1389
server.listen(port, '127.0.0.1', () => {
  console.log(`Mock LDAP server listening on ldap://127.0.0.1:${port}`)
  console.log(`  admin: ${ADMIN_DN} / ${ADMIN_PASSWORD}`)
  for (const dn of Object.keys(users)) console.log(`  user : ${dn}`)
})

// Ctrl+C 优雅退出
process.on('SIGINT', () => {
  console.log('\nShutting down mock LDAP server...')
  server.close(() => process.exit(0))
})
