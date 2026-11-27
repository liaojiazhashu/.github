#!/bin/bash
# 荒启科幻站点自动部署：从 GitHub 拉取并落地
# 由 cron 每 5 分钟调用一次
#
# 2026-11-27 改造。三个实测出来的事实决定了这次改法：
#
#   1. 服务器到 github.com:443 是**间歇性**可用的。连续 10 次 git fetch 只成功 3 次
#      （失败的那 7 次每次都要等满 45 秒才超时）。旧脚本一次失败就 exit，于是
#      20 天里记录了 1562 次 FAILED，而真正因为工作区脏失败的只有 5 次 ——
#      比例是 312 : 1。所以首要修复是**重试**，不是告警。
#   2. 旧脚本失败时只写一句「可能存在本地改动或网络问题」。网络断和文件冲突是
#      两件完全不同的事，长得一样就没法排障。现在两者分开记，并且把
#      git status --porcelain 和工作区是否只是行尾差异一起写进日志。
#   3. 静默失败：cron 每 5 分钟失败一次，日志在 /var/log/zha-deploy.log，
#      没有任何人收到通知。现在连续失败到阈值会调 bin/alert-send.php 发告警，
#      状态落在 /var/lib/zha-deploy/ 下（consecutive-failures / last-success），
#      运维可直接查看。（/healthz 目前**不读**这两个文件，这是后续要补的。）
#
# 设计取舍：**不自动丢弃任何本地改动**。服务器上的工作区本该是干净的，变脏就是
# 有人手工改过，那是需要人判断的事。脚本只把判断依据写清楚，不动手。
#
# 本脚本的真相源在仓库 deploy/deploy-zha.sh；服务器上 /www/wwwroot/deploy-zha.sh
# 只是一层薄壳，从仓库里调用本文件，见 deploy/bootstrap-shim.sh。

set -u

REPO=/www/wwwroot/zha.ruletree.club
LOG=/var/log/zha-deploy.log
STATE_DIR=/var/lib/zha-deploy
FAIL_FILE="$STATE_DIR/consecutive-failures"
PHP=/usr/bin/php
ALERT_SCRIPT="$REPO/server/bin/alert-send.php"

# 重试参数：5 次 × 12 秒上限 = 最多 72 秒，远小于 5 分钟的 cron 间隔。
# 12 秒是实测的：网络好时 fetch 在 1 秒内返回，坏时要等 45 秒才超时；
# 12 秒能砍掉那些注定失败的等待，把时间让给重试。
MAX_TRIES=5
TRY_TIMEOUT=12

# 告警阈值：连续失败 3 次（约 15 分钟）开始报，之后每 12 次（约 1 小时）重复一次。
# 不每 5 分钟报一次，是因为一次持续几小时的光纤抖动会刷出几十条同样的话，
# 人就会开始忽略它 —— 这正是这套告警最该避免的死法。
ALERT_AFTER=3
ALERT_EVERY=12

log() {
  echo "[$(date '+%F %T')] $*" >>"$LOG"
}

# 记一次失败，并在达到阈值时告警。$1 = 原因短语（会进告警标题，用来区分
# 「网络抖动」和「工作区脏」这两种完全不同的故障）。
# 两种失败都计数：工作区脏虽然不会自愈，但它同样是「部署停住了」，而且比
# 网络抖动更急 —— 网络会自己好，脏工作区不会。
record_failure() {
  local reason="$1"
  local fails=0
  [ -f "$FAIL_FILE" ] && fails=$(cat "$FAIL_FILE" 2>/dev/null || echo 0)
  case "$fails" in ''|*[!0-9]*) fails=0 ;; esac
  fails=$((fails + 1))
  echo "$fails" >"$FAIL_FILE"
  log "  连续失败计数：$fails"

  if [ "$fails" -ge "$ALERT_AFTER" ] && [ $(( (fails - ALERT_AFTER) % ALERT_EVERY )) -eq 0 ]; then
    if [ -f "$ALERT_SCRIPT" ]; then
      "$PHP" "$ALERT_SCRIPT" \
        --level=error \
        --title="部署链连续失败 ${fails} 次：${reason}" \
        --context-json="{\"consecutive_failures\":$fails,\"reason\":\"${reason}\",\"head\":\"$(git rev-parse --short HEAD 2>/dev/null)\",\"log\":\"${LOG}\"}" \
        >>"$LOG" 2>&1
      # 分开报：exit 2 是「通道没配」，不是「发失败了」。混成一句会让下一个人
      # 去查网络，其实是 .env 里 ALERT_WEBHOOK 空着 —— 这个区别值一行代码。
      case "$?" in
        0) log "  告警已投递" ;;
        2) log "  告警未投递：通道未配置（server/.env 的 ALERT_WEBHOOK 为空或 ALERT_ENABLED=false）" ;;
        *) log "  告警未投递：通道已配但对方未接受，见上一行" ;;
      esac
    else
      log "  告警脚本不存在（$ALERT_SCRIPT），跳过告警"
    fi
  fi
}

mkdir -p "$STATE_DIR" 2>/dev/null

cd "$REPO" || {
  log "FATAL 仓库目录不存在：$REPO"
  exit 1
}

# ---- 1. 工作区检查：只针对已跟踪文件 -------------------------------------------
# 未跟踪文件（?? 开头）不影响 git merge，不该让部署停下 —— 服务器上常年有
# storage/ 之类的运行时产物。
tracked_dirty=$(git status --porcelain --untracked-files=no 2>/dev/null)

if [ -n "$tracked_dirty" ]; then
  log "FAILED pull：工作区有已跟踪文件的本地改动，merge 会被拒绝，本次不尝试联网"
  printf '%s\n' "$tracked_dirty" | while IFS= read -r line; do
    [ -n "$line" ] && log "  本地改动：$line"
  done
  # 判断依据直接写进日志，省掉下次排查时手工跑一遍。
  # 必须对比 HEAD 而不是默认的「工作区 vs 索引」：如果某个文件已经被 git add
  # 进索引，默认比较会显示无差异，于是把「已暂存的真实改动」误判成「只是行尾」。
  if git diff --ignore-cr-at-eol --quiet HEAD 2>/dev/null; then
    log "  判定：git diff --ignore-cr-at-eol HEAD 无差异 —— 只是行尾不同，内容一致"
    log "  处理：确认后可执行 git checkout -- <文件> 丢弃，不影响任何内容"
  else
    log "  判定：存在真实内容差异，需人工确认后再处理；脚本不会自动丢弃"
  fi
  log "  排查：cd $REPO && git status --porcelain && git diff HEAD --stat"
  record_failure "工作区脏"
  exit 1
fi

# ---- 2. 联网拉取：唯一会因网络失败的一步，带重试 -------------------------------
before=$(git rev-parse HEAD 2>/dev/null)
fetched=0
try=0

while [ "$try" -lt "$MAX_TRIES" ]; do
  try=$((try + 1))
  start=$(date +%s)
  if timeout "$TRY_TIMEOUT" git fetch --quiet origin main 2>>"$LOG"; then
    fetched=1
    log "fetch 成功：第 $try/$MAX_TRIES 次尝试，耗时 $(( $(date +%s) - start ))s"
    break
  fi
  log "fetch 失败：第 $try/$MAX_TRIES 次尝试，耗时 $(( $(date +%s) - start ))s"
  [ "$try" -lt "$MAX_TRIES" ] && sleep 2
done

if [ "$fetched" -ne 1 ]; then
  log "FAILED pull：连续 $MAX_TRIES 次 fetch 均在 ${TRY_TIMEOUT}s 内未完成"
  log "  远端：$(git remote get-url origin 2>/dev/null)"
  log "  本地 HEAD：$(git rev-parse --short HEAD 2>/dev/null)"
  log "  这类失败的典型报错：Failure when receiving data from the peer / SSL connection timeout"
  record_failure "网络"
  exit 1
fi

# ---- 3. 合并：纯本地操作，不再触网 ---------------------------------------------
# 用 merge --ff-only 而不是 pull，是因为 pull = fetch + merge，会在我们刚
# 重试成功之后再联网一次 —— 那一次失败就前功尽弃，而且不会被重试覆盖。
if ! git merge --ff-only FETCH_HEAD >>"$LOG" 2>&1; then
  log "FAILED merge：fetch 成功，但无法快进合并"
  # 三种原因长得不一样，分开判断，别让人对着同一句话猜。
  if ! git merge-base --is-ancestor HEAD FETCH_HEAD 2>/dev/null; then
    log "  原因：本地有分叉提交（HEAD 不是远端 main 的祖先）"
    log "  排查：git log --oneline HEAD ^FETCH_HEAD 看本地多出来的提交；确认后 rebase 或重置"
    reason="本地分叉"
  elif [ -n "$(git status --porcelain --untracked-files=all 2>/dev/null)" ]; then
    log "  原因：工作区文件与远端新增文件冲突（多为同名未跟踪文件）"
    git status --porcelain --untracked-files=all | while IFS= read -r line; do
      [ -n "$line" ] && log "  本地：$line"
    done
    log "  处理：确认该未跟踪文件可删后再删；脚本不会自动删"
    reason="文件冲突"
  else
    log "  原因：未归类，需人工看上面的 git 输出"
    reason="合并失败"
  fi
  record_failure "$reason"
  exit 1
fi

after=$(git rev-parse HEAD 2>/dev/null)

# ---- 4. 恢复计数 ---------------------------------------------------------------
if [ -f "$FAIL_FILE" ]; then
  prev=$(cat "$FAIL_FILE" 2>/dev/null || echo 0)
  case "$prev" in ''|*[!0-9]*) prev=0 ;; esac
  [ "$prev" -gt 0 ] && log "已恢复：此前连续失败 $prev 次，本次拉取成功"
  rm -f "$FAIL_FILE"
fi
date '+%F %T' >"$STATE_DIR/last-success"

# ---- 5. 落地：属主与模板缓存 ---------------------------------------------------
if [ "$before" != "$after" ]; then
  log "UPDATED $before -> $after"
  # 只修正本次变更文件的属主，避免全站遍历
  git diff --name-only "$before" "$after" | while IFS= read -r f; do
    [ -n "$f" ] && [ -e "$f" ] && chown www:www "$f" 2>/dev/null
  done
  # 模板缓存失效
  find server/storage/cache -type f -name "*.php" -delete 2>/dev/null
fi

exit 0
