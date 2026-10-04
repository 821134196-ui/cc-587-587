#!/usr/bin/env bash
# ============================================================
# 工厂半成品转序与工艺参数锁定系统 —— 一键启动
# 用法: ./start.sh     (Ctrl+C 停止)
# ============================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# 静态 PHP 运行时(无系统 PHP 时使用)
[ -x "$HOME/.runtime/php" ] && export PATH="$HOME/.runtime:$PATH"

command -v php >/dev/null 2>&1 || { echo "✗ 未找到 PHP(需要 8.2+),请安装或放置于 ~/.runtime/php"; exit 1; }
command -v npm >/dev/null 2>&1 || { echo "✗ 未找到 npm,请先安装 Node.js"; exit 1; }

echo "==> 准备后端 (Laravel + SQLite)…"
cd "$ROOT/server"
[ -f .env ] || cp .env.example .env
[ -f database/database.sqlite ] || touch database/database.sqlite
if [ ! -d vendor ]; then
  echo "==> 安装 Composer 依赖…"
  php "$HOME/.runtime/composer" install --no-interaction --prefer-dist
fi
php artisan migrate --seed --force >/dev/null
echo "    数据库已迁移并载入基础数据"

echo "==> 准备前端 (React + Vite)…"
cd "$ROOT/frontend"
[ -d node_modules ] || npm install --no-fund --no-audit

cd "$ROOT/server"
php artisan serve --host 127.0.0.1 --port 8000 >/dev/null 2>&1 &
BACK_PID=$!
cd "$ROOT/frontend"
npm run dev -- --host 127.0.0.1 --port 5173 >/dev/null 2>&1 &
FRONT_PID=$!

cleanup() { kill "$BACK_PID" "$FRONT_PID" 2>/dev/null || true; }
trap cleanup EXIT INT TERM

sleep 2
echo ""
echo "  ✅ 后端 API : http://127.0.0.1:8000/api"
echo "  ✅ 前端界面 : http://127.0.0.1:5173   (在浏览器打开)"
echo ""
echo "  检验测试: cd server && php artisan test"
echo "  按 Ctrl+C 停止服务"
echo ""
wait
