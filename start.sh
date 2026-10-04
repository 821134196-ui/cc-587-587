#!/usr/bin/env bash
# =============================================================================
# 工厂半成品转序与工艺参数锁定系统 —— 一键启动
#
#   ./start.sh            启动（首次自动安装依赖、迁移、种子）
#   ./start.sh --fresh    清空 SQLite 并重新种子后启动（演示/测试用）
#   ./start.sh --verify   仅运行端到端业务检验（数量核对/参数换版/返工回流/追溯链）
#
# 启动后：
#   前端  http://localhost:5173   （React + Vite，/api 代理到后端）
#   后端  http://localhost:8000   （Laravel，SQLite 存于 backend/database/database.sqlite）
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND="$ROOT/backend"
FRONTEND="$ROOT/frontend"

# ---- 选择 PHP：优先系统 PHP，否则使用已下载的静态 PHP ----
if command -v php >/dev/null 2>&1; then
  PHP_BIN="php"
elif [ -x "$HOME/.local/php/php" ]; then
  PHP_BIN="$HOME/.local/php/php"
  export PATH="$HOME/.local/php:$PATH"
else
  echo "== 未发现 PHP，下载静态 PHP 8.4（含 pdo_sqlite/mbstring）到 ~/.local/php =="
  mkdir -p "$HOME/.local/php"
  PHP_URL="https://dl.static-php.dev/static-php-cli/common/php-8.4.23-cli-linux-x86_64.tar.gz"
  curl -sL --fail --max-time 180 -o /tmp/sphp.tar.gz "$PHP_URL"
  tar -xzf /tmp/sphp.tar.gz -C "$HOME/.local/php"
  chmod +x "$HOME/.local/php/php"
  PHP_BIN="$HOME/.local/php/php"
  export PATH="$HOME/.local/php:$PATH"
  if [ ! -x "$HOME/.local/php/composer" ]; then
    echo "== 安装 Composer =="
    curl -sL --fail -o /tmp/cset.php https://getcomposer.org/installer
    $PHP_BIN /tmp/cset.php --install-dir="$HOME/.local/php" --filename=composer
  fi
fi
COMPOSER="$HOME/.local/php/composer"
[ -x "$COMPOSER" ] || COMPOSER="composer"

echo "== PHP: $($PHP_BIN -r 'echo PHP_VERSION;') =="

# ---- 仅检验模式 ----
if [ "${1:-}" = "--verify" ]; then
  cd "$BACKEND"
  $PHP_BIN artisan migrate:fresh --seed --force
  $PHP_BIN artisan verify:workflow
  exit $?
fi

# ---- 后端依赖 ----
if [ ! -d "$BACKEND/vendor" ]; then
  echo "== 安装 Composer 依赖 =="
  if [ "$COMPOSER" = "composer" ]; then
    (cd "$BACKEND" && composer install --no-interaction)
  else
    $PHP_BIN "$COMPOSER" install --working-dir="$BACKEND" --no-interaction
  fi
fi

# ---- 前端依赖 ----
if [ ! -d "$FRONTEND/node_modules" ]; then
  echo "== 安装 npm 依赖 =="
  (cd "$FRONTEND" && npm install --no-audit --no-fund)
fi

# ---- 数据库 ----
mkdir -p "$BACKEND/database"
touch "$BACKEND/database/database.sqlite"
cd "$BACKEND"

if [ "${1:-}" = "--fresh" ]; then
  echo "== 重建数据库并写入演示数据 =="
  $PHP_BIN artisan migrate:fresh --seed --force
else
  $PHP_BIN artisan migrate --force
  USER_COUNT=$($PHP_BIN artisan tinker --execute='echo \App\Models\User::count();' 2>/dev/null | tail -1)
  if [ "$USER_COUNT" = "0" ]; then
    echo "== 空数据库，写入演示数据 =="
    $PHP_BIN artisan db:seed --force
  fi
fi

# ---- 启动（Ctrl+C 同时停止前后端） ----
echo ""
echo "=============================================================="
echo " 后端 API : http://localhost:8000/api"
echo " 前端界面 : http://localhost:5173"
echo " 演示账号 : cut@factory.local / cnc@factory.local /"
echo "            weld@factory.local / qc@factory.local /"
echo "            eng@factory.local / admin@factory.local"
echo " 密码统一 : password"
echo "=============================================================="
echo ""

cleanup() {
  echo ""
  echo "== 停止服务 =="
  [ -n "${API_PID:-}" ] && kill "$API_PID" 2>/dev/null || true
  [ -n "${WEB_PID:-}" ] && kill "$WEB_PID" 2>/dev/null || true
  exit 0
}
trap cleanup INT TERM

$PHP_BIN artisan serve --host=0.0.0.0 --port=8000 &
API_PID=$!
(cd "$FRONTEND" && npm run dev) &
WEB_PID=$!

wait
