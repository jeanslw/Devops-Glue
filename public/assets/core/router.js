// assets/core/router.js
export const ROUTES = {
  monitor:   { title: 'admin.sidebar_monitor', icon: '📡', parent: null },
  mapping:   { title: 'admin.sidebar_mapping', icon: '📋', parent: null },
  security:  { title: 'admin.sidebar_security', icon: '🛡️', parent: null },
  versions:  { title: 'admin.sidebar_versions', icon: '🧩', parent: null },
  mode:      { title: 'admin.sidebar_mode', icon: '🔧', parent: null },

  users:     { title: 'admin.sidebar_user_list', icon: '👤', parent: 'users-group' },
  roles:     { title: 'admin.sidebar_roles', icon: '🔐', parent: 'users-group' },
  password:  { title: 'admin.sidebar_password', icon: '🔑', parent: 'users-group' },
  'users-group': { title: 'admin.sidebar_users', icon: '👥', parent: null },

  'perm-list':     { title: 'admin.sidebar_perm_list', icon: '📋', parent: 'perms-group' },
  'perm-register': { title: 'admin.sidebar_perm_register', icon: '➕', parent: 'perms-group' },
  'implied-rules': { title: 'admin.sidebar_implied_rules', icon: '🔗', parent: 'perms-group' },
  'perms-group':   { title: 'admin.sidebar_permissions', icon: '🔐', parent: null },

  'pull-records': { title: 'admin.sidebar_pull_records', icon: '📥', parent: 'build-group' },
  'push-records': { title: 'admin.sidebar_push_records', icon: '📤', parent: 'build-group' },
  'build-group':  { title: 'admin.sidebar_build_records', icon: '📊', parent: null },

  'api-tokens':     { title: 'api_token.menu', icon: '🔑', parent: null },
  'operation-logs': { title: 'oplog.menu', icon: '📝', parent: null },

  'platform-config': { title: 'sys.tab_platform', icon: '🔌', parent: 'settings-group' },
  'system-info':     { title: 'sys.tab_system_info', icon: '🗄️', parent: 'settings-group' },
  'settings-group':  { title: 'sys.menu', icon: '⚙️', parent: null },
};

export function resolveTab() {
    const name = (location.hash || '').replace(/^#\//, '');
    return name || 'monitor';
}