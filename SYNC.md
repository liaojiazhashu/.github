# 开发与同步说明

这个仓库同时承载三个部分：

| 部分 | 入口文件 | 说明 |
|---|---|---|
| 荒启科幻官网 | `index.html` `about.html` `community.html` `contact.html` `tools.html` `privacy.html` | 纯静态页 |
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

## 官网页面：源文件与产物

官网七个页面和样式表都是**构建产物**。不要直接改根目录的 `index.html` / `css/style.css`——
下次构建会把它覆盖掉。

| 要改什么 | 改哪里 | 改完跑 |
|---|---|---|
| 页面文案与结构 | `.build/pages/<页面>.html` | `node tools/build-pages.cjs` |
| 颜色、间距、组件样式 | `.build/css-parts/*.css` | `node tools/build-css.cjs` |

- 页头、页脚、导航、`<title>` 与 meta 描述**不在这七个源文件里**，在
  `tools/build-pages.cjs` 的 `NAV` / `FOOTER_*` / `PAGES` 三段。改一处，七页同时生效。
- 源文件与产物**都要提交**：服务器服务的是产物，源文件是下次重建的依据。
- 构建后跑 `node tools/check-site.cjs`：查未定义的 CSS 变量、标题层级、锚点与死链。
- 截图与调试输出落在 `.build/shots/`（已忽略），不要往仓库根目录丢临时文件。

2026-11-08 之前 `.build/pages` 与 `.build/css-parts` 被排除在仓库外，仓库只有产物，
换台机器就没法重建页面。现在两者都已入库。

## 不会同步的东西

以下内容被 `.gitignore` 挡在仓库外，**永远不会**随代码同步：

| 内容 | 原因 |
|---|---|
| `server/.env` | 数据库凭据 |
| `server/vendor/` | Composer 依赖，服务器上独立安装 |
| `server/storage/uploads` `artifacts` | 118 MB 用户上传的作品原文 |
| `server/storage/certs` | 证书私钥 |
| `server/composer.phar` | 二进制，3.2 MB |
| `server/public/sitemap.xml` | 运行时生成物，每次落库都重写；以服务器磁盘为准（见下节） |
| `.build/fonts` | 字体副本 25 MB |
| `assets/*.css` `assets/*.js` 等软链 | 服务器部署技巧，源文件在 `server/public/assets/` |

所以代码同步**不会**影响图书馆里已有的作品内容，也不会覆盖数据库。

## 静态 sitemap：不进仓库，以服务器磁盘为准

`server/public/sitemap.xml` 是**运行时生成物**。`PublicSurface` 在每次发布 / 下线 /
闸门落库后重写它，内容随数据变（闸门收起 267 篇之后，条数从 1290 降到 1015）。
2026-11-08 起它被 `.gitignore` 挡住 —— 之前入库的那份是开发机产物，`<loc>` 全是
`http://127.0.0.1:8090/…`，一旦被推上线，搜索引擎就会去抓本机地址。

它同时是**服务器工作树被弄脏**的根源：旧版本里闸门每 `--apply` 一次就重写一次
这个受跟踪文件，而 `deploy-zha.sh` 用的是 `git pull --ff-only`，dirty 就永远拉不动。
撤出库之后这个问题不再出现；如果日志里还报 `FAILED pull`，先跑
`ssh srv 'cd /www/wwwroot/zha.ruletree.club && git status --porcelain'` 看是哪个文件。

- 重建：`ssh srv 'cd /www/wwwroot/zha.ruletree.club/server && php bin/console.php sitemap'`
- **文件缺失不会自动回来**：`PublicSurface::refreshSitemap()` 是「只重写不新建」，
  见 `server/app/Application/Library/PublicSurface.php:174`。少了它就退回动态路由
  `SitemapController`（`server/config/routes.php` 的根级 `/sitemap.xml`），
  页面照样 200、条数正确，只是每次请求都过一遍 PHP。
- 换了服务器、或跑过 `git clean -xfd` 之后，若觉得 `/sitemap.xml` 变慢，
  跑一次上面那条重建命令即可。nginx 是 `try_files $uri $uri/ @slim`，有没有文件都通。

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

## 验收陷阱：Twig 页有整页缓存，别把「旧的」当成「没上线」

`/library/*` 的 200 响应经过 `PublicCacheMiddleware`（`server/app/Http/Middleware/PublicCacheMiddleware.php`），
HTML 落盘缓存在 `server/storage/cache/pages/`。各档 TTL（`ttlFor()`，配置项默认值）：

| 路径 | TTL |
|---|---|
| `/library` | `library.cache.home`，默认 60 秒 |
| `/library/works/<code>`、`/library/works/<code>/author` | `library.cache.work`，默认 300 秒 |
| `/library/authors`、`/library/authors/<code>` | `library.cache.author`，默认 300 秒 |
| `/library/works`（列表等其他 works 路径） | `library.cache.works`，默认 60 秒 |
| `/library/method`、`/library/practice` | 3600 秒 |
| `/library/terms/<id>` | `library.cache.term`，默认 300 秒 |
| `/library/terms`（索引等） | `library.cache.terms`，默认 300 秒 |
| `/library/issues*` | `library.cache.issue`，默认 300 秒 |
| `/library/search`、其余路径 | 0（不缓存） |

部署后验收任何 Twig 页，**第一步看响应头**：

```bash
curl -sI https://zha.ruletree.club/library/method | grep -Ei 'x-cache|cache-control|age'
```

- `X-Cache: MISS` —— 这次是现渲染的，可以据此判定
- `X-Cache: HIT` —— **返回的是缓存里的旧 HTML**，可能「新导航 + 旧正文」或反过来；加随机参数打穿再看：
  ```bash
  curl -s "https://zha.ruletree.club/library/method?v=$(date +%s)" | grep -n '要查的那句话'
  ```
- `X-Cache: BYPASS` —— 该路径不缓存（非 200、后台、带 Cookie 等）

2026-11-25 栽过：改完 method 页文案，线上看到「新文案 + 旧页脚」的混合态，`X-Cache: HIT`、`max-age` 还剩 2700 多秒。

**判据用结构，不用字节数。** 动态页的正文总字数、作品数每天都在变，字节差只能当线索：
`grep -c 'privacy.html'`、页脚 `foot__legal` 里有没有那个 `<a>`、`<title>` 是不是新值 —— 这些才是证据。

**立刻失效某一个指定的页面：用 `library:cache-purge --path=…`。**

```bash
# 改完模板要让这一页马上生效，不用等 TTL
php -c php.ini bin/console.php library:cache-purge --path=/library/method
# 带查询串的页面要连 query 一起给，引号别省
php -c php.ini bin/console.php library:cache-purge --path='/library/search?q=abc'
```

它按 key 精确删掉该页的 `.html` 与 `.json` 两个文件；`--path` 必须以 `/library` 开头；**刻意没有 `--all`**——一个能把全站缓存一次清空的开关，迟早有人在生产上误用。`library:cache-prune` 替代不了它：那条按 `created_at` 删「更老」的文件、最小门槛 7200 秒，刚缓存的新页永远删不掉。

不要手算文件名去删。key 的算法收在 `PageCache::keyFor()` 一处（2026-11-25 起），文档里再抄一遍 sha1 规则，迟早与实现漂开。

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
