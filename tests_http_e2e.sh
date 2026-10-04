#!/usr/bin/env bash
# 通过真实 HTTP API 走完整业务流程（与前端调用路径一致）
# 默认先重建演示数据库（脚本依赖干净基线）；设置 E2E_NO_RESET=1 可跳过重置。
set -uo pipefail
BASE="http://127.0.0.1:8000/api"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="$(command -v php || echo "$HOME/.local/php/php")"
if [ "${E2E_NO_RESET:-0}" != "1" ]; then
  echo "== 重建演示数据库 =="
  (cd "$ROOT/backend" && "$PHP_BIN" artisan migrate:fresh --seed --force >/dev/null 2>&1)
fi
PASS=0; FAIL=0
J() { python3 -c "import sys,json;d=json.load(sys.stdin);print(eval(sys.argv[1]))" "$1"; }
login() { curl -s -X POST $BASE/auth/login -H 'Content-Type: application/json' -H 'Accept: application/json' -d "{\"email\":\"$1\",\"password\":\"password\"}" | J 'd["token"]'; }
ok()   { if [ "$1" = "$2" ]; then PASS=$((PASS+1)); echo "  ✓ $3"; else FAIL=$((FAIL+1)); echo "  ✗ $3 (got $1 want $2)"; fi; }
truthy() { if [ "$1" = "True" ] || [ "$1" = "true" ]; then PASS=$((PASS+1)); echo "  ✓ $2"; else FAIL=$((FAIL+1)); echo "  ✗ $2 (got $1)"; fi; }

CUT=$(login cut@factory.local); CNC=$(login cnc@factory.local); WELD=$(login weld@factory.local)
QC=$(login qc@factory.local); ENG=$(login eng@factory.local)

echo "== A. 认证与角色拦截 =="
ENGONLY=$(curl -s -X POST $BASE/parameter-versions -H "Authorization: Bearer $CUT" -H 'Accept: application/json' -d '{}' -o /dev/null -w '%{http_code}')
ok "$ENGONLY" 403 "操作员创建参数版本返回 403"
NOAUTH=$(curl -s "$BASE/orders" -H 'Accept: application/json' -o /dev/null -w '%{http_code}')
ok "$NOAUTH" 401 "未带令牌访问返回 401"

echo "== B. 工程师新建生产单 200 件 =="
OID=$(curl -s -X POST $BASE/orders -H "Authorization: Bearer $ENG" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"product_code":"P-AX100","product_name":"AX100 传动轴组件","planned_qty":200,"note":"HTTP E2E"}' | J 'd["id"]')
curl -s -X POST $BASE/orders/$OID/release -H "Authorization: Bearer $ENG" >/dev/null
BID=$(curl -s "$BASE/batches?production_order_id=$OID" -H "Authorization: Bearer $CUT" | J 'd[0]["id"]')
echo "  订单 $OID 根批次 $BID"

echo "== C. 下料接收（锁定 v1） =="
VER=$(curl -s -X POST $BASE/batches/$BID/receive -H "Authorization: Bearer $CUT" -H 'Accept: application/json' | J 'd["param_version"]["version"]' 2>/dev/null || echo "?")
# 列表关联版本
VER=$(curl -s "$BASE/batches/$BID" -H "Authorization: Bearer $CUT" | J 'd["param_version"]["version"]')
ok "$VER" "v1" "接收后锁定参数版本 v1"
EQ=$(curl -s "$BASE/stations/1/equipment" -H "Authorization: Bearer $CUT" | J 'd[0]["id"]')

echo "== D. 登记 190 合格 + 5 返工 + 5 报废 = 200 =="
ST=$(curl -s -X POST $BASE/batches/$BID/production -H "Authorization: Bearer $CUT" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "{\"equipment_id\":$EQ,\"good_qty\":190,\"rework_qty\":5,\"scrap_qty\":5}" | J 'd["batch"]["status"]' 2>/dev/null)
ST=$(curl -s "$BASE/batches/$BID" -H "Authorization: Bearer $CUT" | J 'd["status"]')
ok "$ST" "qc_pending" "数量守恒登记成功 → 待质检"
RID=$(curl -s "$BASE/batches/$BID" -H "Authorization: Bearer $CUT" | J 'd["records"][-1]["id"]')

echo "== E. 自批拦截（用下料员令牌打质检接口） =="
CODE=$(curl -s -X POST $BASE/records/$RID/inspect -H "Authorization: Bearer $CUT" -H 'Content-Type: application/json' -o /dev/null -w '%{http_code}')
ok "$CODE" "403" "角色中间件拦截操作员质检"

echo "== F. 质检员放行 190 / 返工 5 回 OP-10 / 报废 5（合计 200） =="
P10=$(curl -s "$BASE/processes?product_code=P-AX100" -H "Authorization: Bearer $QC" | J '[p for p in d if p["code"]=="OP-10"][0]["id"]')
R=$(curl -s -X POST $BASE/records/$RID/inspect -H "Authorization: Bearer $QC" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "{\"passed_qty\":190,\"rework_qty\":5,\"scrap_qty\":5,\"rework_to_process_id\":$P10,\"note\":\"5件回流\"}")
ST=$(curl -s "$BASE/batches/$BID" -H "Authorization: Bearer $CUT" | J 'd["status"]')
ok "$ST" "released" "放行后批次在途（只能由机加接收）"
QTY=$(curl -s "$BASE/batches/$BID" -H "Authorization: Bearer $CUT" | J 'd["qty"]')
ok "$QTY" "190" "父批次剩余放行数量 190"
RWB=$(curl -s "$BASE/batches?production_order_id=$OID" -H "Authorization: Bearer $CUT" | J '[b for b in d if b["origin"]=="rework"][0]["id"]')
echo "  返工子批 $RWB"

echo "== G. 工位占用互斥：焊接员抢收应失败 =="
MSG=$(curl -s -X POST $BASE/batches/$BID/receive -H "Authorization: Bearer $WELD" -H 'Accept: application/json' | J 'd["message"]')
echo "    → $MSG"
CODE=$(curl -s -X POST $BASE/batches/$BID/receive -H "Authorization: Bearer $WELD" -o /dev/null -w '%{http_code}')
ok "$CODE" "422" "非目标工位接收返回 422"

echo "== H. 机加接收 → 数量不符挂起 → 工程师裁定 =="
curl -s -X POST $BASE/batches/$BID/receive -H "Authorization: Bearer $CNC" >/dev/null
EQ2=$(curl -s "$BASE/stations/2/equipment" -H "Authorization: Bearer $CNC" | J 'd[0]["id"]')
curl -s -X POST $BASE/batches/$BID/production -H "Authorization: Bearer $CNC" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "{\"equipment_id\":$EQ2,\"good_qty\":180,\"rework_qty\":0,\"scrap_qty\":0}" >/dev/null
ST=$(curl -s "$BASE/batches/$BID" -H "Authorization: Bearer $CNC" | J 'd["status"]')
ok "$ST" "on_hold" "180≠190 数量不符 → 挂起"
HRID=$(curl -s "$BASE/holds" -H "Authorization: Bearer $ENG" | J '[r for r in d if r["batch_id"]=='$BID'][-1]["id"]')
# 更正 185+0+5
curl -s -X POST $BASE/records/$HRID/resolve-hold -H "Authorization: Bearer $ENG" -H 'Content-Type: application/json' \
  -d '{"action":"correct","good_qty":185,"rework_qty":0,"scrap_qty":5}' >/dev/null
ST=$(curl -s "$BASE/batches/$BID" -H "Authorization: Bearer $CNC" | J 'd["status"]')
ok "$ST" "qc_pending" "更正数量守恒后恢复待质检"
RID2=$(curl -s "$BASE/batches/$BID" -H "Authorization: Bearer $CNC" | J 'd["records"][-1]["id"]')
curl -s -X POST $BASE/records/$RID2/inspect -H "Authorization: Bearer $QC" -H 'Content-Type: application/json' \
  -d '{"passed_qty":185,"rework_qty":0,"scrap_qty":5}' >/dev/null

echo "== I. 参数换版（工程师激活机加工序 v2：主轴上限收紧到 2300） =="
P20=$(curl -s "$BASE/processes?product_code=P-AX100" -H "Authorization: Bearer $ENG" | J '[p for p in d if p["code"]=="OP-20"][0]["id"]')
TPLS=$(curl -s "$BASE/processes/$P20/templates" -H "Authorization: Bearer $ENG")
V2=$(echo "$TPLS" | python3 -c '
import sys,json,urllib.request
d=json.load(sys.stdin)
items=[]
for it in d["active_version"]["items"]:
    mx = 2300 if it["template"]["code"]=="SPINDLE_SPEED" else it["max_value"]
    items.append({"parameter_template_id":it["parameter_template_id"],"set_value":it["set_value"],"min_value":it["min_value"],"max_value":mx})
body=json.dumps({"process_id":d["process"]["id"],"version":"v2","change_note":"主轴上限收紧2300","activate":True,"items":items}).encode()
req=urllib.request.Request("http://127.0.0.1:8000/api/parameter-versions",data=body,headers={"Content-Type":"application/json","Authorization":"Bearer '$ENG'"})
print(json.load(urllib.request.urlopen(req))["version"])')
ok "$V2" "v2" "v2 已创建并激活，v1 退役"

echo "== J. 焊接接收 185 件完工（不受机加换版影响） =="
curl -s -X POST $BASE/batches/$BID/receive -H "Authorization: Bearer $WELD" >/dev/null
EQ3=$(curl -s "$BASE/stations/3/equipment" -H "Authorization: Bearer $WELD" | J 'd[0]["id"]')
curl -s -X POST $BASE/batches/$BID/production -H "Authorization: Bearer $WELD" -H 'Content-Type: application/json' \
  -d "{\"equipment_id\":$EQ3,\"good_qty\":185,\"rework_qty\":0,\"scrap_qty\":0}" >/dev/null
RID3=$(curl -s "$BASE/batches/$BID" -H "Authorization: Bearer $WELD" | J 'd["records"][-1]["id"]')
curl -s -X POST $BASE/records/$RID3/inspect -H "Authorization: Bearer $QC" -H 'Content-Type: application/json' \
  -d '{"passed_qty":185,"rework_qty":0,"scrap_qty":0}' >/dev/null
ST=$(curl -s "$BASE/batches/$BID" -H "Authorization: Bearer $WELD" | J 'd["status"]')
ok "$ST" "completed" "末道放行 → 完工 185"

echo "== K. 返工子批 5 件重新流转并完工 =="
curl -s -X POST $BASE/batches/$RWB/receive -H "Authorization: Bearer $CUT" >/dev/null
curl -s -X POST $BASE/batches/$RWB/production -H "Authorization: Bearer $CUT" -H 'Content-Type: application/json' \
  -d "{\"equipment_id\":$EQ,\"good_qty\":5,\"rework_qty\":0,\"scrap_qty\":0}" >/dev/null
RRID=$(curl -s "$BASE/batches/$RWB" -H "Authorization: Bearer $CUT" | J 'd["records"][-1]["id"]')
curl -s -X POST $BASE/records/$RRID/inspect -H "Authorization: Bearer $QC" -H 'Content-Type: application/json' -d '{"passed_qty":5,"rework_qty":0,"scrap_qty":0}' >/dev/null
curl -s -X POST $BASE/batches/$RWB/receive -H "Authorization: Bearer $CNC" >/dev/null
# 机加现在生效 v2；用 v2 范围内读数登记（模拟读数自动在范围内）
curl -s -X POST $BASE/batches/$RWB/production -H "Authorization: Bearer $CNC" -H 'Content-Type: application/json' \
  -d "{\"equipment_id\":$EQ2,\"good_qty\":5,\"rework_qty\":0,\"scrap_qty\":0}" >/dev/null
RRID=$(curl -s "$BASE/batches/$RWB" -H "Authorization: Bearer $CNC" | J 'd["records"][-1]["id"]')
LOCKED=$(curl -s "$BASE/batches/$RWB" -H "Authorization: Bearer $CNC" | J 'd["param_version"]["version"]')
ok "$LOCKED" "v2" "换版后回流批次在机加锁定新版本 v2"
curl -s -X POST $BASE/records/$RRID/inspect -H "Authorization: Bearer $QC" -H 'Content-Type: application/json' -d '{"passed_qty":5,"rework_qty":0,"scrap_qty":0}' >/dev/null
curl -s -X POST $BASE/batches/$RWB/receive -H "Authorization: Bearer $WELD" >/dev/null
curl -s -X POST $BASE/batches/$RWB/production -H "Authorization: Bearer $WELD" -H 'Content-Type: application/json' \
  -d "{\"equipment_id\":$EQ3,\"good_qty\":5,\"rework_qty\":0,\"scrap_qty\":0}" >/dev/null
RRID=$(curl -s "$BASE/batches/$RWB" -H "Authorization: Bearer $WELD" | J 'd["records"][-1]["id"]')
curl -s -X POST $BASE/records/$RRID/inspect -H "Authorization: Bearer $QC" -H 'Content-Type: application/json' -d '{"passed_qty":5,"rework_qty":0,"scrap_qty":0}' >/dev/null
ST=$(curl -s "$BASE/batches/$RWB" -H "Authorization: Bearer $WELD" | J 'd["status"]')
ok "$ST" "completed" "返工回流 5 件走完全程完工"

echo "== L. 追溯链守恒核对 =="
curl -s "$BASE/orders/$OID/trace" -H "Authorization: Bearer $ENG" | python3 -c '
import sys,json
d=json.load(sys.stdin)
r=d["reconciliation"]
fails=[c for c in r["checks"] if not c["pass"]]
for c in r["checks"]: print(("  ✓ " if c["pass"] else "  ✗ ")+c["detail"])
print("  事件数:",len(d["events"]),"| 批次树根节点:",len(d["tree"]),"| 全部通过:",r["all_pass"])
sys.exit(0 if r["all_pass"] and not fails else 1)'
[ $? -eq 0 ] && { PASS=$((PASS+1)); echo "  ✓ 追溯 API 守恒核对全部通过且事件链完整"; } || { FAIL=$((FAIL+1)); echo "  ✗ 追溯核对存在失败"; }

echo
echo "HTTP E2E 结果：通过 $PASS，失败 $FAIL"
exit $FAIL
