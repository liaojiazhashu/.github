# 开发与同步说明

这个仓库同时承载三个部分：

| 部分 | 入口文件 | 说明 |
|---|---|---|
| 荒启科幻官网 | `index.html` `about.html` `community.html` `contact.html` `tools.html` | 纯静态页 |
| 银河图书馆 | `library.html` + `server/` | PHP 8.4 + Slim + Twig |
| 管理后台 | `server/` 里的 `/admin/*` 路由 | 同一 PHP 应用 |

## 三个位置

| 位置 | 路径 | 角色 |
|---|---|---|
| 本机 | `D:\AITEST\huangqi-scifi` | 开发，改代码在这里 |
| GitHub | `liaojiazhashu/.github` | 中转，唯一真相源 |
| 服务器 | `/www/wwwroot/zha.ruletree.club` | 线上，只拉取不修改 |

数据流是单向的：**本机 → GitHub → 服务器**。服务器上的代码不要直接改，改了会在下次拉取时冲突。

## 日常操作：双击一个文件

打开 `D:\AITEST\`，双击 **一键同步.cmd**。

它会依次完成：

1. 检查本机改动，有就让你写一句说明并提交
2. 拉取 GitHub 上的新提交（如果你在别处改过）
3. 推送到 GitHub
4. 让服务器立即拉取上线

跑完窗口里会显示三方的版本号和线上地址。按任意键关闭。

## 不用一键脚本时的手动等价操作

```bash
cd /d/AITEST/huangqi-scifi
git add -A
git commit -m "说明这次改了什么"
git pull --rebase origin main
git push origin main
# 服务器 5 分钟内自动拉，或立即触发：
ssh srv 'flock -n /tmp/zha-deploy.lock /www/wwwroot/deploy-zha.sh'
```

## 服务器自动部署机制

- 部署脚本：`/www/wwwroot/deploy-zha.sh`
- 定时任务：`*/5 * * * *`，cron 每 5 分钟执行一次
- 日志：`/var/log/zha-deploy.log`
- 并发保护：`flock`，不会同时跑两份
- 只做 `git pull --ff-only`：服务器上有本地改动时会失败并记日志，不会覆盖

查看部署记录：

```bash
ssh srv 'tail -20 /var/log/zha-deploy.log'
```

## 不会同步的东西

以下内容被 `.gitignore` 挡在仓库外，**永远不会**随代码同步：

| 内容 | 原因 |
|---|---|
| `server/.env` | 数据库凭据 |
| `server/vendor/` | Composer 依赖，服务器上独立安装 |
| `server/storage/uploads` `artifacts` | 118 MB 用户上传的作品原文 |
| `server/storage/certs` | 证书私钥 |
| `server/composer.phar` | 二进制，3.2 MB |
| `.build/fonts` | 字体副本 25 MB |
| `assets/*.css` `assets/*.js` 等软链 | 服务器部署技巧，源文件在 `server/public/assets/` |

所以代码同步**不会**影响图书馆里已有的作品内容，也不会覆盖数据库。

## 冲突了怎么办

脚本在合并失败时会停下并列出冲突文件。两种处理：

**放弃这次同步，回到同步前的状态**

```bash
git rebase --abort
```

**手动解决**

打开冲突文件，会看到这样的标记：

```
<<<<<<< HEAD
你本机的版本
=======
GitHub 上的版本
>>>>>>> origin/main
```

保留想要的内容，把 `<<<<<<<` `=======` `>>>>>>>` 三行删掉，然后：

```bash
git add 冲突的文件
git rebase --continue
git push origin main
```

## 验证同步是否成功

三方版本号必须一致：

```bash
# 本机
cd /d/AITEST/huangqi-scifi && git log --oneline -1

# GitHub
git ls-remote origin refs/heads/main

# 服务器
ssh srv 'cd /www/wwwroot/zha.ruletree.club && git log --oneline -1'
```

也可以抽查某个文件在两端的 md5：

```bash
md5sum /d/AITEST/huangqi-scifi/index.html
ssh srv 'md5sum /www/wwwroot/zha.ruletree.club/index.html'
```

最后看线上是否真的生效：

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://zha.ruletree.club/
```

返回 `200` 即正常。

## 环境信息

| 项 | 值 |
|---|---|
| 服务器 | 腾讯云轻量 `lhins-2440bxd7`，2C2G，OpenCloudOS 9.6 |
| 连接方式 | `ssh srv`（免密，root@119.29.2.186） |
| 站点根 | `/www/wwwroot/zha.ruletree.club` |
| 运行环境 | nginx 1.30.6 + PHP 8.4.27 + MySQL 5.7.44 |
| 数据库 | `hqlib`（服务器为权威，本机 `.env` 指向 127.0.0.1:3307） |
| 线上域名 | https://zha.ruletree.club |

## 注意

- 服务器内存只有 2 GB，不要在服务器上跑构建或大批量处理
- 仓库是公开的（public），**永远不要**把 `.env`、密钥、证书提交进去
- 仓库挂在 `.github` 这个组织档案仓库名下，`profile/` 目录不要删——它决定个人主页顶部显示的内容
