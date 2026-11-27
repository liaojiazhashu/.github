#!/bin/bash
# 部署脚本薄壳。装在服务器 /www/wwwroot/deploy-zha.sh。
#
# 它本身不含任何逻辑：真正的部署脚本在仓库里（deploy/deploy-zha.sh），
# 这样它能被版本管理、能被 code review、改动走正常的提交推送流程。
#
# 为什么需要一个壳，而不是直接把 cron 指到仓库里那份：
# 仓库里那份只有在 pull 成功之后才存在。而 pull 正是它要干的事 ——
# 第一次安装、以及仓库被 git clean 掉之后，都需要有个东西在仓库外面兜住。
# 所以这个文件是**唯一一个不进仓库、只存在于服务器上的文件**，它必须足够薄，
# 薄到「不需要维护」：只有下面几行。
#
# 安装（一次性）：
#   scp deploy/bootstrap-shim.sh srv:/www/wwwroot/deploy-zha.sh
#   ssh srv 'chmod 755 /www/wwwroot/deploy-zha.sh'
#
# 之后所有改动都只改仓库里的 deploy/deploy-zha.sh，推上去等 cron 拉。

set -u

REPO=/www/wwwroot/zha.ruletree.club
REAL="$REPO/deploy/deploy-zha.sh"
LOG=/var/log/zha-deploy.log

# 用 -f 而不是 -x：仓库是在 Windows 上提交的（core.fileMode=false），
# git 记不住可执行位，deploy-zha.sh 到了服务器上是 644。
# 用 -x 判断的话，部署链会在 pull 成功那天彻底停住，而且日志里只有一句
# 「找不到」—— 明明文件就在那儿。所以这里不看权限位，直接交给 bash 跑。
if [ -f "$REAL" ]; then
  exec /bin/bash "$REAL" "$@"
fi

# 仓库里那份还不存在（首次安装，或被 git clean 掉）—— 说清楚，别静默。
echo "[$(date '+%F %T')] FAILED 找不到 $REAL，仓库可能尚未拉到含 deploy/ 的提交" >>"$LOG"
echo "  首次安装：scp bootstrap-shim.sh 到本路径之前，先手工确认仓库 HEAD 已含 deploy/" >>"$LOG"
exit 1
