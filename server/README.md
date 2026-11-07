# 银河图书馆 · 动态系统（server/）

> 对应规划文档：`../docs/银河图书馆-动态化系统-企业级规划文档.md`  
> 当前进度：**M1 – M6 已交付**（路由 / 后台登录 / 作品导入 / 六阶段拆解流水线 /
> 权限矩阵 / 版本 diff / 发布下线 / 单阶段重跑 / 拆解结果编辑与词库词条归并 / 公开馆藏前台 /
> 附录 E 68 项安全自检 / 渗透自测 / 压测 / 加密备份与恢复演练）。
> 里程碑编号对齐规划文档 §16.1：M2「内容」已提前并入 M1，M5「前台」于 2026-10-29 交付，
> M6「加固」同日交付（安全自检 56 通过 / 0 不通过 / 8 待人工、渗透 15 项全过、
> 备份加密 + 恢复演练校验一致）。**下一步是上线前的 Go/No-Go 评审（§16.2）**。

## 1. 运行环境（本机绿包，不写注册表）

| 组件           | 路径                     | 端口       | 说明                                                         |
| ------------ | ---------------------- | -------- | ---------------------------------------------------------- |
| PHP 8.4.27   | `D:\webserver\php`     | 8090     | 由原 8.3.36 原地替换，旧目录备份 `D:\webserver\php-8.3.36`；8080 仍是既有站点 |
| 本项目 php.ini  | `server/php.ini`       | —        | 启动时用 `-c` 指定，`open_basedir`/禁函数只约束本项目                      |
| MySQL 5.6.51 | `D:\webserver\mysql56` | **3307** | 绿色版，数据在 `mysql56\data`，无 Windows 服务                        |
| MySQL 8.4.13 | `D:\webserver\mysql`   | 3306     | 既有服务 `MySQL84`，**未改动**                                     |

**数据库版本口径**：生产用 **MySQL 5.7+（推荐 8.0 / 8.4 LTS）**，本机 5.6.51 是**向下兼容下限**。
同一份 `001_init.sql` 已在 5.6.51 与 8.4.13 上双跑：各 39 张表，329 个列定义逐字节一致
（唯一差异是 8.0.19 起移除的整数显示宽度，语义等价）。

**sql_mode 已对齐生产档**（最容易漏的一处）：本机 5.6 的 `my.ini` 主动设成
`ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`，
外加 `innodb_strict_mode=ON`、`explicit_defaults_for_timestamp=ON`。
不这么做的话，模糊 `GROUP BY`、`'0000-00-00'` 这类写法在本地能过、到生产直接报错。


## 2. 启停

```bat
D:\webserver\启动图书馆.bat     :: 起 MySQL 3307 + 站点 8090 + worker
D:\webserver\停止图书馆.bat     :: 停 8090 监听 + 优雅关闭 MySQL 3307
```

手动启动（调试用，前台可见日志）：

```bat
cd /d D:\AITEST\huangqi-scifi\server
D:\webserver\php\php.exe -c php.ini -S 127.0.0.1:8090 -t public public/index.php
```

> 末尾的 `public/index.php` 是**路由脚本**，不能省：PHP 内置服务器只按它把非静态请求交给 Slim。
> 省掉的话 `/admin/login` 会 404（静态服务器找不到这个文件）。

## 2.1 后台怎么用（M1）

首次准备（只做一次，顺序不能反）：

```bat
cd /d D:\AITEST\huangqi-scifi\server

:: 1) 写入角色 / 权限 / 体裁种子（幂等，可重复执行）
D:\webserver\php\php.exe -c php.ini bin\console.php seed:rbac

:: 2) 建管理员账号（口令至少 12 位；不给 --password 会提示输入）
D:\webserver\php\php.exe -c php.ini bin\console.php user:create --username=admin --role=admin
```

日常：

| 操作 | 地址 |
| --- | --- |
| 登录 | `http://127.0.0.1:8090/admin/login` |
| 概览 | `/admin` |
| **一键拆解** | `/admin/oneclick` |
| 拆解调度（进度 / 失败 / 暂停） | `/admin/queue` |
| 作品列表 | `/admin/works` |
| 单篇导入 | `/admin/works/new` |
| 批量导入 | `/admin/works/import` |
| 原文版本 / 版本对比 | `/admin/works/<作品code>/versions` · `/diff?from=1&to=2` |
| 分析结果（编辑 / 发布 / 重跑） | `/admin/analyses/<分析code>` |

## 2.2 拆解流水线怎么用（M3）

一条分析走的是「后台入队 → worker 消费」，页面请求里**不跑模型**（单阶段最长 300 秒，同步跑必然超时）。

```bat
cd /d D:\AITEST\huangqi-scifi\server

:: ① 起 worker（常驻；一次处理一条就退用 --once，适合挂计划任务）
D:\webserver\php\php.exe -c php.ini bin\worker.php
D:\webserver\php\php.exe -c php.ini bin\worker.php --once
D:\webserver\php\php.exe -c php.ini bin\worker.php --name=w2 --stale=900

:: ② 也可以在命令行直接跑单篇（不入队，逐阶段打印过程；调提示词时用这个）
D:\webserver\php\php.exe -c php.ini bin\analyze.php --work=<作品code>
D:\webserver\php\php.exe -c php.ini bin\analyze.php --work=<作品code> --force
D:\webserver\php\php.exe -c php.ini bin\analyze.php --all --limit=5

:: ③ 批量回归（20 篇通过率验收，带分类与报告）
D:\webserver\php\php.exe -c php.ini bin\regress.php --limit=20
D:\webserver\php\php.exe -c php.ini bin\regress.php --limit=20 --threshold=0.9 --json
D:\webserver\php\php.exe -c php.ini bin\regress.php --work=<作品code> --reuse
```

`regress.php` 与 `analyze.php --all` 的区别在于它把结果分成四类：
**通过 / 复用 / 拦下（正文过短，预期行为）/ 失败**。通过率的分母是「总数 − 被拦下的」——
把「正确拒绝」算进失败会低估系统，把「幂等复用」算进通过会高估系统。报告写到
`storage/reports/regression-<时间戳>.json`，可与上一轮 diff。

后台入口：作品详情页 → 「开始分析」（勾「忽略幂等复用」可强制重跑）→ 分析结果页
看产出、六步执行记录与逐次调用成本（S1–S5 调模型 + S7 读者视角，S6 是纯代码组装）。

**关键词口径（v4）**：词库是「科幻概念索引」—— 既不是通用意象词典，也不是标签墙。

两道闸门，都要过：

1. **它是不是科幻概念**：拿掉这篇的科幻设定，这个词还站得住吗？
   「世代飞船」「记忆编辑」站不住（离开科幻没有对应物）；
   「茶」「信件」「影子」「雨水」「雪原」站得住 —— 站得住的一律不收，
   不管它多常见、多好用。词库收的是类型概念，不是母题。
2. **别的科幻小说会不会也用它**：「WA0409黑洞」「封孔泥」「《园艺与花卉》」不会，
   进了词库就是一条永远只挂单篇的死词，索引功能等于零。

数量：每篇**最多 5 个**，而且 **5 是上限不是配额** ——
先想 8–12 个候选再逐个过闸门，交 2–4 个是常态，一个都抽不出来就交空数组
（闸门允许 `terms: []`，页面显示「这次分析没有产出关键词」）。
禁止用近义词（克隆人 + 克隆体）、上下位词凑数。

每条必须带一句**脱离本文也读得懂的定义**，否则词库里全是暗号。

实现方式是三重约束，缺一不可：

| 层 | 在哪 | 做什么 |
| --- | --- | --- |
| 提示词 | `resources/prompts/v4/s1_index.md` | 注入词表 + 退役词清单；两道闸门的判据、数量规则、八类必禁项 |
| 词表 | `resources/taxonomy/sf_terms.php` | 143 个通行词 + 296 别名，按三类分组。S1 从这里选，落库时别名归一 |
| 退役表 | `resources/taxonomy/retired_terms.php` | 形状合格但**与科幻没有强关联**的说法（影子、信件、茶、献祭…）。提示词里点名、闸门拒收、`term:prune` 清理历史数据 |
| 闸门 | `StageGate::g1` | 表内词放行；退役词拒收；表外新词必须 2–8 字、无数字、无拉丁字母、无书名号、无「的/与/之」 |

**公开词库的口径**：`is_canonical = 1`（表内通行词）**或**被 ≥2 篇已发布作品引用，
并且**至少 1 篇引用** —— 零引用的词条点开是空页面，只留在库里给分析选词，不进目录。
`is_canonical` 也不代表终身免检：词表删掉的词会被 `sync-taxonomy` 撤销标记。

> 改完两张表要跑 `php -c php.ini bin/check-taxonomy.php` 自检（重复、越界、退役词冲突）。
> 改词表后跑 `term:sync-taxonomy --apply` 对齐 `is_canonical`；
> 换提示词后用 `php -c php.ini bin/analysis-rerun.php --all` 重跑 S1（默认不级联），
> 看过新词再决定要不要 `--stage=S4_ADVICE --cascade`，最后跑 `term:prune --apply` 清掉退役词与孤儿词。

**词条百科**：词条详情页固定四要素，数据来源各管一段 ——
①词条释义（`hq_index_term.definition`，分析产物）；②起源与发展（人工撰写）；
③世界科幻中的它，至多 3 部代表作（人工撰写，**只列不注**：只有书名/作者/年份）；
④馆内作品（`hq_work_term` 实时查询）。别名不在前台展示（归并与检索在数据层仍用）。
②③在 `resources/taxonomy/term_encyclopedia.php`，按规范词为键，
由 `TermEncyclopedia` 加载，前台渲染，不进库。覆盖校验连库对齐：

```bat
D:\webserver\php\php.exe -c php.ini bin\check-encyclopedia.php          # 结构 + 覆盖校验
D:\webserver\php\php.exe -c php.ini bin\check-encyclopedia.php --apply  # 顺手回填空缺释义
```

**标题纪律（2026-11-01）**：全站小标题一律单层自足 —— 不再有 eyebrow 副标签、
标题旁解释小字；分项小标题（谁/要什么/色彩与光线/保守/激进…）15px 加粗，
不得小于正文。新页面照此办理。

新增在用词条而百科缺条目时，该词详情页会缺②③两节 —— 自检会点名，补上即可。

**两个视角**：一篇作品有两个子页，同一份数据两种排法 ——
`/library/works/{code}` 是读者页（核心设定 · 魅力 · 影视化 · 适合谁），
`/library/works/{code}/author` 是作者视角（节点 · 伏笔 · 建议 · 修改方向）。
用两条路由而不是查询参数，是因为缓存中间件按路径做键，参数会让两套内容互相覆盖。

**「作者视角」与「作者主页」不是一回事**，别再混：
- `/library/works/{code}/author` —— 同一篇的另一种读法，读者是这篇的作者本人；
- `/library/authors/{code}` —— **这个人名下的全部馆内作品**，读者是「看完一篇还想找他别的篇」的人。

后者按 `hq_author.code`（ULID）寻址，不按名字：笔名会改、会重名、会有全半角两种写法，
而 code 改不了，分享出去的链接不会失效。作品详情页的作者名就是这个入口。

读者页的「如果拍成片子」区块**只有文字，没有图**：主块是 S7 `visual.scene`
写出的那一幕（可直接念出来的画面描述），下面三格给色调、镜头与可对标坐标。
馆里不生成、也不存任何影视化概念图 —— 生成图无论标注得多清楚，都会被读者当成
作者写的原意，而它只是模型读完正文之后的推测。这条是硬约定，不要重新引入。

**幂等**：正文 sha256 + 提示词版本 + 模型 + 各阶段参数一起算 `input_hash`，
同一条 `input_hash` 成功过就直接复用，不再产生调用费用。

**成本护栏**：单篇超 `AI_PER_WORK_BUDGET_YUAN`（默认 3 元）或单日超 `AI_DAILY_BUDGET_YUAN`
（默认 100 元）立刻停；`hq_setting` 里写 `ai.enabled=0` 可随时按下应急刹车。

**短文本会被拦**：正文不足 500 字符时在调用前直接拒绝（拆不出四相节点轴，钱花了也过不了闸门）。

**命令行批量导入**（回归或演示时一次灌一批，不必开浏览器）：

```bat
D:\webserver\php\php.exe -c php.ini bin\import.php --dir="storage\import-stage\某批" --author=某作者 --issue=25 --dry
D:\webserver\php\php.exe -c php.ini bin\import.php --dir="storage\import-stage\某批" --author=某作者 --issue=25
```

`--dir` 只能指向 `server\` 之内（`open_basedir` 限死），外部稿子先拷进 `storage\import-stage\`。
判重按**正文 sha256**，重复文件报「内容重复，已属于作品 #N」，`--force-dup` 才强行再导一份。
`--dry` 与实跑共用**同一套判据**（扩展名白名单 / 0 字节 / 33 MiB 上限，直接引用
`WorkImportService::ALLOWED_EXT` 与 `MAX_BYTES`）。这条不是洁癖：预演一旦比实跑宽松，
它报的「将导入」就是假的，而人会照着它安排工作量。

其它运维命令：

```bat
:: 列账号 / 重置口令（重置会同时踢掉该账号所有会话）
D:\webserver\php\php.exe -c php.ini bin\console.php user:list
D:\webserver\php\php.exe -c php.ini bin\console.php user:reset-password --username=admin

:: 绑定 / 解除动态验证码（密钥只显示一次）
D:\webserver\php\php.exe -c php.ini bin\console.php user:enable-totp --username=admin
D:\webserver\php\php.exe -c php.ini bin\console.php user:disable-totp --username=admin

:: 清理过期会话与限流记录
D:\webserver\php\php.exe -c php.ini bin\console.php session:purge

:: 字数口径变了之后重算（先报告，加 --apply 才写回）
D:\webserver\php\php.exe -c php.ini bin\recount-words.php
D:\webserver\php\php.exe -c php.ini bin\recount-words.php --apply

:: 单元测试
:: ⚠ 不要用 -c php.ini：php.ini 里 extension_dir 写死的 D:\webserver\php\ext 一旦不存在，
::   mbstring 就加载失败，PHPUnit 会直接拒跑（报 "the mbstring extension is not available"），
::   而真正的原因被这条消息盖住了 —— 十有八九不是没装扩展，是路径指错了。
::   正确姿势是 -d extension_dir 指到实际的 ext 目录（服务器上就是下面这条）：
D:\webserver\php\php.exe -d extension_dir=D:\webserver\php\ext vendor\bin\phpunit --no-coverage

:: 从绿色包还原的机器（php 不在 D:\webserver）用这条：
D:\AITEST\_restore-backup\02-webserver\runtime\php\php.exe -d extension_dir="D:\AITEST\_restore-backup\02-webserver\runtime\php\ext" vendor\bin\phpunit --no-coverage

:: 需要 intl（NumberFormatter / Collator）时追加 -d extension=intl。
:: 不必再写 -d extension=mbstring：指对 extension_dir 之后 ini 里那行就生效了，
:: 再写只会多一行 "Module already loaded" 的警告。
:: 期望输出：OK (… tests, … assertions)。当前基线是 262 tests / 1019 assertions，
:: 数字会随测试增加而变，**绿不绿看 OK 这个词，别对数字**。
```

**账号边界**：`hq_user` 只是**后台运营账号**，与前台会员账号（读者 = 社群作者，H5 与 APP 共用一套）**不共用表**，
理由见规划文档 §8.0。后台不开放注册，账号只能由管理员在服务器上用 `console.php` 创建。

## 2.3 后台怎么改、怎么发（M4）

四件事：**改产出、传版本、看差异、发上线**。

### 权限矩阵（10 个权限码 × 4 个角色）

| 权限码 | admin | editor | reviewer | viewer |
| --- | :-: | :-: | :-: | :-: |
| work.read 查看作品与原文 | ● | ● | ● | ● |
| work.write 新建/编辑/导入作品 | ● | ● | · | · |
| work.delete 删除作品（软删） | ● | · | · | · |
| analysis.run 触发分析/重分析 | ● | ● | · | · |
| analysis.edit 编辑拆解结果 | ● | ● | ● | · |
| analysis.publish 发布/下线 | ● | · | ● | · |
| term.merge 索引词归并 | ● | ● | · | · |
| system.user 用户与角色 | ● | · | · | · |
| system.setting 系统设置 | ● | · | · | · |
| audit.read 审计日志 | ● | · | ● | · |

分界线是刻意划的：**editor 能跑机器分析但没有发布权**（对外可见这一步要人负责），
**reviewer 能发布和改字但不能导入、不能重跑**（审校的职责是看和改，不是重造）。

落地方式有三条不变式，`bin/check-permissions.php` 会核对：

1. 权限码的**契约**写在 `App\Application\Auth\Permission::MATRIX`，数据库 `hq_role_permission` 是运行时正本，
   两者必须一致（漂移会被这个脚本抓出来）；
2. 每条路由都必须 `setName()` 且在 `config/permissions.php` 里登记，**没登记 = 403**（默认拒绝，
   新加动作忘了配权限会当场暴露，而不是悄悄对所有人开放）；
3. 权限**每请求查库**，不缓存在会话里 —— 改一行权限立刻生效，不用等人重新登录。

```bat
:: 权限自检（矩阵 / 路由覆盖 / 权限码三方对齐；有错退出码非 0，可挂进部署脚本）
D:\webserver\php\php.exe -c php.ini bin\check-permissions.php
```

### 改产出

分析结果页上，索引词、建议、修改方向都可就地编辑（`analysis.edit`），每次改动写一条
`hq_analysis_revision` 快照（谁、什么时候、改了什么由 `note` 说明）。

索引词有两条不同性质的改动，别混：

- **调整主/辅与权重、移除**：只影响这一篇（`hq_work_term`），不碰全站词表；
- **归并**：改的是全站词表（`hq_index_term`），把 A 并进 B —— A 变成 B 的别名，之后搜旧名仍能找到，
  两个词条都用过的作品只留一条（主词优先、权重取大）。跨类别不能归并。
  **撤销只能恢复词条本身**，已经搬过去的作品关联不会回滚（界面上写明了这一点）。

### 传版本、看差异

作者改稿后重新投稿、或编辑回填修订稿，都在 `/admin/works/<code>/versions` 上传新版本。
新版本**不覆盖**旧文件：`hq_work_file` 不可变，版本只是一条指向它的记录，
所以「某个分析当初依据的是哪一版」永远查得回来，diff 也有两侧可比。

diff 按**段落**比（作者改的是一段话，不是第 3712 个字符），连续的一删一增会被识别为「改写」，
并把两侧公共前后缀剥掉、只高亮中间那段。超过 150 万格子退化为按位置比较——
会粗一点，但不会把进程拖死。

### 重分析与发布

- **单阶段重跑**（`analysis.run`）在分析结果页同步执行，可选**级联**：
  索引词 → 建议 → 修改方向；剧情 → 伏笔 → 建议 → 修改方向；伏笔或建议 → 修改方向。
  不勾级联时，下游阶段会被标为 `stale`（「已过期」）——它还在，但引用的是重跑前的数据。
- **发布**（`analysis.publish`）把这次分析设为作品的 `current_analysis_id`，同作品此前发布的
  自动转 `superseded`；**下线**把它退回 `succeeded`，作品回到「已分析」。
- 整篇重跑仍然走队列（`analysis.run` 入队 + worker），因为六阶段最长可能跑十几分钟；
  单阶段重跑留在请求里，因为操作者就等在这个页面上。

## 2.4 前台怎么用、怎么改（M5）

对外只有这十二条地址，**全部无会话**（不带 `Set-Cookie`，否则整页缓存不成立）：

| 地址 | 说明 | 缓存 |
| --- | --- | --- |
| `/library` | 首页：四项统计、最新入馆、三类词库入口、按期次、方法说明 | 60s |
| `/library/works` | 馆藏列表（期次 / 体裁 / 字数 / 排序筛选 + 双向游标翻页：上一页 / 回到第一页 / 下一页） | 60s |
| `/library/works/{code}` | 一篇作品的**读者视角**：主线三句、核心设定、魅力、影视化描述、适合谁 | 300s |
| `/library/works/{code}/author` | 同一篇的**作者视角**：剧情节点、伏笔台账、三层建议、修改方向 | 300s |
| `/library/authors/{code}` | 一位作者的**全部馆内作品**：篇数、字数、期次跨度、作品卡、反复写到的词 | 300s |
| `/library/terms` | 科幻词库总览（三类分组 + 检索 + 分页） | 300s |
| `/library/terms/{id}` | 一个词条：百科定义、馆内作品、共现词、同类词 | 300s |
| `/library/search?q=克制` | 检索结果（相关词 chips + `<mark>` 高亮） | **不缓存** |
| `/library/issues/25` | 按期次归档（同一套双向游标翻页，`total` 走真实计数） | 300s |
| `/library/method` | 方法说明：收词口径、两页各写什么、依据、边界与收录范围（**方法论在馆内，不在官网**） | 1h |
| `/sitemap.xml` | 全部已发布页（当前 62 条，随发布数变化） | 1h |

`/library/works?author={code}` 是作者主页的「换成列表看」版本：带筛选条件、可翻页、
可叠加期次与字数；作者主页本身是它的卡片版。两条路由共用同一段筛选（`workFilters`），
页头写几篇，网格就摆几篇。

上架 / 下线 / 重建索引 / 落 sitemap：

```bat
cd /d D:\AITEST\huangqi-scifi\server

:: 预演：哪些作品会被发布（不加 --apply 一律不动数据）
D:\webserver\php\php.exe -c php.ini bin\console.php library:publish --all

:: 真发布（会自动重建这篇的关键词并清掉相关页面缓存）
D:\webserver\php\php.exe -c php.ini bin\console.php library:publish --all --apply
D:\webserver\php\php.exe -c php.ini bin\console.php library:publish --work=01M61... --apply

:: 下线一篇
D:\webserver\php\php.exe -c php.ini bin\console.php library:unpublish --work=01M61... --apply

:: 词库重建（改了分词规则、或检索搜不到东西时）
D:\webserver\php\php.exe -c php.ini bin\console.php library:reindex

:: 落静态 sitemap（生产推荐，Nginx 直接返回文件，PHP 不参与）
D:\webserver\php\php.exe -c php.ini bin\console.php sitemap
```

## 2.5 运营台（M7）怎么用

入口 `http://127.0.0.1:8090/admin`。导航按「一天里会做什么」分成四组：
**拆解 / 作品 / 索引 / 治理**，每一项都按权限码显示，没权限的项不出现（不是点了才 403）。

### 拆解的三种「动」

| 动作 | 在哪 | 作用范围 | 语义 |
| --- | --- | --- | --- |
| 开始拆解 | 作品列表批量条 / 调度台 | 勾选的作品，或**按当前筛选全部** | 入队，worker 按优先级领取 |
| 暂停领取 | 调度台闸门 | 全局 | 只停「领新活」，**正在跑的会跑完** |
| 停止 | 作品列表「停止」/ 调度台单条 | 勾选的作品，或单条任务 | 排队中→直接 `canceled`；运行中→**阶段边界退出** |

三件事必须分清，否则会误判：

- **暂停不是停止。** 暂停=闸门，检查点在 `claim()` 之前。已花掉的钱不浪费——那一篇会跑完。
  页面上写死了这句话：「正在跑的那一篇会跑完；排队中的一条都不会丢。」
- **运行中不能硬杀。** 模型请求已经发出去就收不回来，硬杀只会留下 `running` 僵尸。
  所以按「停止」只置 `cancel_requested=1`，由 worker 在每个阶段边界检查并抛出
  `AnalysisCanceled`，走独立异常分支：置 `canceled`、**不重试、不写失败告警**——
  否则运营自己停的任务会在告警里报一整天。
- **停止后作品状态要收尾。** 中止（停止/失败/进程中断）一律走
  `WorkRepository::statusAfterAbort()` 按「指针 + 既有产物」推导，绝不让它停在 `analyzing`：
  停在 `analyzing` 的作品后台永远显示「正在分析」，而再次入队又会被
  BatchDispatcher 的「正在分析」规则静默跳过，**全程没有一处报错**。

### 隐藏的三级粒度

`visibility` 与 `status` 是**正交**的两件事：`status` 答「走到哪一步」，`visibility` 答「要不要给人看」。
隐藏不清 `status`、不清 `current_analysis_id`、不清 `published_at`，所以恢复是无损的。

| 粒度 | 前台表现 | 联动范围 |
| --- | --- | --- |
| 作品 | 整篇不存在，详情页 404，列表里消失 | 列表 / 期次 / 词条计数 / 共现 / 检索 / sitemap / 统计 |
| 拆解 | **等同于这篇从未被拆解过**：详情页 404，列表、期次、首页统计、sitemap 里都没有它 | 以上全部 + 只被它引用的词条退出词库 + 正文词形遮蔽 |
| 词条 | 词条页 404，从词库消失 | 词库列表 / 计数 / 共现 / 作品页标签 / 检索 / sitemap / **全部公开文本里的词形（遮蔽）** |

**拆解隐藏必须看起来像「从未拆解」。** 公开区唯一的内容就是拆解本身，所以藏掉拆解之后，
这篇作品没有任何可看的东西。留一个「作品在馆、拆解暂不公开」的壳，等于承认馆里有过这份结论 ——
而它本该不存在。所以两种隐藏在前台收敛成同一个结果：**页面 404、数字不算、链接不出现**。
差别只留在后台：作品级隐藏是原文也下线，拆解级隐藏是原文仍在、只是这一次结论收存。

这条口径收在 `LibraryRepository::LIVE_WORK` 一段常量里，`workFilters()` / `countWorks()` /
`publishedIssues()` / `stats()` / `filterOptions()` / `sitemapEntries()` / `findPublishedByCode()`
全部拼同一句。散落的 JOIN 一定会漏一处，而漏掉的那一处就是「藏了还看得见」。

联动口径一共四个常量：`LIVE_WORK`（作品视角）、`PUBLISHED_JOIN`（词条关联视角）、
`TERM_LIVE`、`TERM_REFS`。改一处，全站生效。

隐藏必须同时做四件事，缺一件就等于没藏住：**改状态 → 写审计 → 失效页面缓存 → 重写静态 sitemap**。
只改数据库时，60–300 秒的整页缓存会继续把已隐藏的内容吐给读者，而人已经以为藏好了；
`public/sitemap.xml` 更彻底 —— 它是构建时的快照，不改就永远留着，搜索引擎会一直来抓一个 404。
所以入口只有一个（`VisibilityService`），且受影响的缓存标签在**改之前**收集（含二级影响：
隐藏作品要一并失效它的词条页与期次页）。

### 第四道闸门：公开文本层（`PublicTextGuard`）

前三道闸门都在 SQL 里，挡的是**结构化引用**——关联表、计数、列表、检索。
但拆解产物存的是**自由文本**：一句话索引、主题说明、情节摘要、节点细节、伏笔正文、
修改建议、读者看点、术语说明。这些是模型读完小说写出来的叙述，它会自然地把词条名
写进句子里（"串起冷冻休眠、世代飞船与人工生态圈"）。**这些字不在任何关联表里，
只改查询条件它照旧出现在页面上。**

所以有一条产品要求没法只靠 SQL 满足：「在馆里已经没有实体的词，不该在任何公开文本里再见到它」。
`App\Application\Library\PublicTextGuard` 是为此存在的第四道闸门，位置在
**产物 → 模板之间**，只此一处：

```php
$artifacts = $this->guard->artifacts($this->analyses->loadArtifacts($analysisId));
```

它整棵递归处理产物数组，而不是逐个字段判断 —— 模板读的是
`$artifacts['beats'][3]['detail']` 这种深层路径，逐个点名必然漏一处，而漏一处等于没隐藏。

**判断标准不是「后台有没有点过隐藏」，而是「这个词在公开区还活不活得下去」**：

1. 词条自己被隐藏；
2. 词条没被点名，但引用它的已发布拆解被隐藏了，且它没有其它可见出处 ——
   词条页已经 404、列表里也没有它，它事实上不存在，只是 `visibility` 字段还没改。

第 2 条是拆解级隐藏的直接后果：藏掉一篇拆解，只被它用过的词就失去了全部出处。
这种词如果还出现在**别的**文章正文里，读者会在没有任何入口的情况下看到它。

反过来，**从未被任何拆解引用过的词表词不遮**：它们从来没在馆内露过面，遮它们不解决任何泄漏，
而它们往往是「飞船」这类太通用的说法，纳进来会把全站正文打成星号。实测库里就有 4 个词条
只挂在失败或未发布的分析上（「警报」「仿生人」），按「没出处」判会把它们从别人正文里抹掉。

**遮蔽而不是整段删除**：段落整体删掉会让文本断裂、甚至整页无可读内容。遮蔽保留上下文，
读者看到 `＊＊＊＊` 就知道此处有内容被隐去，这比假装没有更诚实，信息也确实没泄漏。
等长替换（四个字换四个星号），排版宽度不变。

三条边界无法机械化，写在代码注释里也写在这里：

1. **作品标题不遮**。词条叫「蒲公英」、作品也叫《蒲公英》是同名巧合，不是泄漏。
   因为隐藏一个词条就给作品改名，读者会找不到这篇。与可见作品标题**完全相同**的词形整个退出遮蔽。
2. **可见词条名里的子串不遮**。隐藏「世代飞船」而「冷冻仓世代飞船」是另一条可见词条时，
   按字面遮蔽会把后者打成「冷冻仓＊＊＊＊」—— 一条还挂着的词条，名字被截断了。可见名称优先。
3. **单字词形不遮**。会遮到任何句子里偶然的同字，噪声大于信号。

别名照遮（写「冷冻仓飞船」和写「世代飞船」是同一件事），但同样受边界 1、2 约束。

**遮蔽范围**：作品详情页的四件产出（整棵递归）、列表/首页/期次卡片的一句话索引、
检索结果的摘要、词条页的释义与百科「起源与发展」。**不动**标题、作者名、期次名、
外部经典书名 —— 那是身份信息，不是词条索引。

### 检索里的两处特殊处理

- **被隐藏的词不做 2-gram 扩展**。搜「世代飞船」会拆出「飞船」，而「飞船内舱」是公开词条，
  于是页面显示「共 1 篇」——词本身没出现，结果却暗示馆里有这个话题。
  隐藏的词应当表现得和从不存在一样：只按整串查。
- **不拿它去翻作品简介**。`hq_work.summary` 是作者原文，不属于词条索引，不该被改写；
  但也不该成为隐藏词的检索入口（`searchLike()` 的 `$includeSummary` 开关）。

### 关键词表与可见性正交

`hq_search_keyword` 存 `term_id`（迁移 `008`），可见性在 `matchKeywords()` **查询时**判断。
反过来做（隐藏就不写入关键词）会埋一个很隐蔽的坑：半夜定时跑了 `library:reindex`，
正好某词处于隐藏状态，它的关键词就永久缺失——之后恢复可见仍搜不到，而页面一切正常，没人会知道。

由此得到一条操作上的便利：**隐藏 / 恢复词条不需要重建索引**，改完即时生效。

### 泄漏巡检（`bin\scan-term-leak.php`）

```bat
php -c php.ini bin\scan-term-leak.php
```

四层检查，退出码可进流水线：

| 层 | 查什么 | 期望 |
| --- | --- | --- |
| 数据层 | `loadArtifacts()` 输出的自由文本里有多少处待遮蔽 | 只报数量，是守卫的工作量 |
| 呈现层 | 真抓全部前台（含 `/author`、词条页、期次页）逐页找**词形残留**与**已消失作品的链接** | **0** |
| 检索层 | 拿每个隐藏词形去搜 | **0 篇** |
| 消亡层 | 该消失的地址（死词条、消失的作品）逐个探状态码 | **全部 404** |

**呈现层的作品检查不能只看隐藏词条。** 一篇拆解的关键词如果全是共享词（「世界观」「飞船」
这类），它被隐藏后不会产生任何一个死词条——只查词条的话，脚本会打印「没有隐藏词条，
无需扫描」然后退出 0。那是最危险的一种通过：什么都没验，却报了一个绿。所以巡检器
独立算一份「曾经发布过、现在不该在公开区」的作品清单，逐个页面找指向它们的链接。
判据只认 `code` 不认标题：《错位》这种标题本身就是常用词，按文本匹配必然误报。

**消亡层查的是反面。** 前三层查「有没有漏出来」，它查「该关的门关没关」。少了它，
一个把过滤写反的实现（什么都 404）能让前三层全绿——页面都不存在，当然没有残留。

扫描集合与 `PublicTextGuard` **同一口径**：显式隐藏的词条，加上「因某篇已发布拆解被隐藏
而失去全部出处」的词条。若两者不一致，巡检会报「没有隐藏词条」然后退出 —— 最危险的通过方式。

两个坑写在脚本里：① 公开区限额 60 请求/60 秒，脚本主动节流并把 429 当「没测到」重试——
限流页里没有词，把它算成「这一页干净」是这个检查最危险的失败方式；② 检索页必然回显
用户输入（标题、「没有找到「××」」），那不算泄漏，所以检索断言单独做，不混进页面扫描。

### 作者页巡检（`bin\check-author-pages.php`）

```bat
php -c php.ini bin\check-author-pages.php
```

作者页的篇数来自一条聚合 SQL，卡片来自另一条列表 SQL。两条各写一遍条件，早晚会漂，
而漂移的样子极其隐蔽：页头写「9 篇」、底下摆 8 张卡，读者不会察觉少了一篇，作者本人会。
所以巡检逐位作者比对：

| 查什么 | 期望 |
| --- | --- |
| 馆内有作品记录的作者，作者页可访问 | 200（0 篇也是 200） |
| 页头「馆内作品 N 篇」 | 与库里聚合一致 |
| 首屏卡片数 | `min(篇数, 每页数)` —— 少了是漏篇，多了是漏过滤 |
| 卡片指向的作品详情 | 200 |
| 0 篇的作者页 | 有空态，且不摆卡片、不列词条 |

外加两条反向断言：**馆内没有任何作品记录的作者，作者页必须 404**；
**sitemap 作者条目数 = 能打开的作者页数**。

**作者页不跟着作品一起消失**。存续条件是「馆内有没有这个人的作品记录」，不是「有没有可见作品」：
作品被隐藏是作品的事，把人的页面一起 404，等于在拆解级隐藏之外又开了一层作者级隐藏，
那不在授权范围里。0 篇就是 0 篇——页面照常打开，写「馆内作品 0 篇」，空态只说
「暂时没有公开展示的作品」，不报「本来有几篇被藏了」。

2026-11-01 实测：6 位作者 / 28 项检查全通过；联动演练——隐藏两篇中的一篇，作者页 2 篇→1 篇、
缓存与 sitemap 同步；两篇全隐藏，作者页 **仍 200、0 篇 0 卡、空态**，sitemap 保留该作者条目，
`?author=` 保留筛选并显示「共 0 篇」，还原后全部复原，库里 0 条残留隐藏。

### 批量操作的可信度

「按筛选全部开始 / 停止 / 隐藏」的目标集合由**服务端按筛选条件重算**
（`WorkRepository::idsByFilter()`，与列表共用同一份 `buildWhere()`），
不信任前端传来的 id 列表：翻页勾选、换筛选、改数据都会让两边对不上。
上限 2000 条一次，超过分批。

### 站内文章（官网内容源，`/admin/source`）

后台只读浏览官网全站文章，用来回答「哪些练笔作品还没进馆」。三个数字必须能对上：

```
全站 1843  →  参加练笔 1375  →  练笔可用 1177
```

差额就是被删除 / 未公开 / 非练笔的部分，页面上一并列出（已删除 / 未公开 / 非发布各多少）。

**两个接口的 `key` 语义完全不同**，这是最容易卡住的地方：

| 接口 | `key` 是什么 | 例子 |
| --- | --- | --- |
| `hqContents/contentsList` | 平台公共密钥 | `?key=QyAPIZKw&page=1&limit=50` |
| `hqContents/contentsInfo` | **文章 cid** | `?key=2460&isMd=0` |

详情接口把 `key` 当 cid 用。读官网前端代码时写的是 `{key: this.cid}`，很容易误以为那是公共密钥，
然后一直拿到「该文章不存在」。另外**详情接口成功时不返回 `code` 字段**，用 `code === 1`
判成功会把所有成功当失败 —— 表现是「列表正常、详情永远 404」。

**练笔判据只能用活动名，不能用 slug。** 实测 29 个活动里 slug 有三种拼法并存
（`PracticeWriting` / `PracticeWrighting` / `PricticeWrighting`），还混着群星杯、未来校园、
NTR 创作活动。按 slug 前缀筛会漏掉一半。判据是 `active[].name` 含「荒启科幻练笔」。

「可用」= `type='post'` ∧ `isDelete=0` ∧ `isopen=1` ∧ `status='publish'`。前三个状态字段互相独立：
有 84 篇 `isopen=0` 但 `status` 仍是 publish，有 163 篇 status 非 publish 却没被标删除。

**第四个条件（`type='post'`）是后补的，也最阴。** 站里有一批 `type='post_draft'` 的文章，
列表接口里 `status=publish`、`isopen=1`、没被删，三个条件全合格；但拿 cid 去调详情接口，
一律返回「文章暂未公开访问」——它们还在草稿箱。不按类型挡，第 25 期那 20 篇草稿
和站方自己的测试稿（`【接口自检-可删】`、`probe-cid`、`test`）都会被算进「练笔可用」。
所以 1197 这个旧数字里含 20 篇草稿，正确值是 **1177**。

**目录整份落盘，不逐页打官网。** 全站 1843 篇、每页上限 50 条，翻完 37 个请求约二十秒；
页面翻页读本地 `storage/cache/source/catalog.json`，只有看全文才实时调详情接口。
顶部显示「数据截至 X」，旧了自己点同步。

```bat
php -c php.ini bin\console.php source:sync
```

**CA 证书必须放在项目目录内**（`storage/certs/cacert.pem`，配置项 `SOURCE_API_CA`）。
php.ini 的 `open_basedir` 只允许 `server` 及其下，指到 `D:\webserver\php\...` 会让 `is_file()`
直接触发限制、curl 读不到证书 —— 而且 bootstrap 把 warning 转异常，表现是整页 500。

出站安全：地址只来自配置、不跟随重定向（E-47/E-48）。审计里 SourceApi 已列入白名单，
并额外断言该文件不含 `getQueryParams` / `$_GET` / `$_REQUEST`。

**翻页要重试。** 官网接口在连续请求后会间歇性失败（不是 429，也没有固定规律），
37 页几乎必然撞上一次。所以翻页间隔放到 700ms，单页失败按 0→2s→5s→10s 退避重试 4 次；
仍然失败才整体放弃、**不落盘**（半份目录比没有目录更危险）。

#### 期次、作者、字数（拆解排期要的三样）

一期一行摊在 `/admin/source` 顶部，26 期合计 1375 篇参加、1177 篇可用、约 1285 万字。

**期号只能从活动名解析。** 活动名写着「荒启科幻练笔第二十六期」，中文数字转成 26；
`slug` 有三种错拼（见上），按 slug 解析会得到 0。解析不出来按「非练笔」处理，不猜。

**字数用接口的 `size`，不逐篇数字。** 实测它就是正文去标签后的字符数
（《失聪者》`size`=17214，本地 `strip_tags` 后 `mb_strlen`=17182，差的是空白与实体）。
所以 1800 篇不必各拉一次详情——那是把 37 个请求变成 1800 个。

**期次以官网为准，日历只做兜底。** 把 26 个活动的 `deadline` 拉下来一比，
**第 1~24 期的实际截稿与 `IssueSchedule` 推算差一个月**（例：第 13 期官网 2025-06-01，
推算给 2025-07-01）。规则仍是 9 期/年，错在相位：第 1 期是 1 个月的窗口，不是 2 个月。
从第 25 期起两者重新对齐，所以「第 25 期 = 2026-09-01 → 2026-11-01」这句话依然成立。

两条命令分工，不要混着跑：

```bat
php -c php.ini bin\console.php source:issue-sync           :: 预览
php -c php.ini bin\console.php source:issue-sync --apply   :: 用官网 deadline 写 hq_issue
php -c php.ini bin\console.php library:issue-dates         :: 只处理没对准官网的期次
```

`source:issue-sync` 缺期就补建（本次补了 1~24 期），已存在的更新起止与 `source_mid` /
`source_ends_at` / `source_reveal_at`。`library:issue-dates` 现在会**跳过**
`source_ends_at` 非空的期次——否则跑一次日历命令，就把刚同步好的真日期推回错的。

**第 25、26 期的作者名拿不到。** 练笔在揭晓前，官网把作者统一显示成「匿名用户」，
连作者 UID 都是临时的（第 25 期 79 篇、第 26 期 4 篇全匿名，揭晓日分别是 2026-11-16 / 2026-12-16）。
这两期要等揭晓后重新 `source:sync` 才会有真实作者，之前不要导入、也不要按作者排期。
期次表里「匿名」列和「未揭晓」标记就是为这件事留的。

#### 导出清单

```bat
php -c php.ini bin\console.php source:export                              :: 可用练笔全部
php -c php.ini bin\console.php source:export --scope=practice --issue=22  :: 第 22 期参加的全部
php -c php.ini bin\console.php source:export --out=D:/清单.csv            :: 指定路径
php -c php.ini bin\console.php source:export --out=- > 清单.csv           :: 走标准输出
```

默认落 `storage/artifacts/`，文件名带期次与日期。列固定为：
`cid、期号、期次、标题、作者、作者UID、字数、发布时间、状态、链接`。
文件带 UTF-8 BOM 与 CRLF——不带 BOM 只有别人的 Excel 打开才乱码，本地一切正常。
后台列表页的「导出当前筛选（CSV）」带的是当前 `scope / issue / q`，所见即所导。

#### 作者活跃度：活跃 / 僵尸（`/admin/source/activity`）

拆解一千多篇不能平铺着起步，得先看作者还在不在站里。口径是**近一年有没有产出**：

| 分类 | 判据 | 本次实测 |
| --- | --- | --- |
| 活跃 | 一年内投过稿 **或** 评过稿 | 158 位 / 924 篇 / 1054.9 万字 |
| 僵尸 | 一年内两样都没有 | 83 位 / 167 篇 / 141.9 万字 |
| 仅评论 | 一年内只发过短评 | 2 位 / 8 篇 |
| 匿名 | 揭晓前的匿名投稿 | 65 篇 |
| 已注销 | 人已离站 | 9 位 / 13 篇 |

```bat
php -c php.ini bin\console.php source:activity                 :: 近一年，约 6 分钟
php -c php.ini bin\console.php source:activity --since=2025-11-01
php -c php.ini bin\console.php source:activity --refresh       :: 忽略缓存重查
php -c php.ini bin\console.php source:activity --limit=5       :: 冒烟，只查 5 人
```

**「评稿」和「评论」是两套东西，不能混。** 评稿走 `review/reviewList`，每条带五维打分
（电子/文笔/节奏/人物/立意）和五段评语，全站一万三千多条；评论走 `hqComments/commentsList`，
是短文，对象可以是文章也可以是别人的评稿。拿评论当活跃证据，会把「只回了句好看」的人算成活跃作者。

**按作者过滤的两个键最容易弄反**：评稿用 `searchParams={"uid":X}`（评稿人），
评论用 `searchParams={"authorId":X}`（`ownerId` 是**被评论文章的作者**，同一人 authorId 43 条、
ownerId 29 条）。传错键不会报错，接口会静默忽略、还你全站总量。

**投稿看全站，不看练笔。** 有人三年不参赛却一直在别的栏目写，只看练笔会把人冤枉成僵尸。

**匿名是作品级的，不是作者级的。** 实测 39 个 uid 既有匿名作品又有实名作品（第 25 期匿名、
往期实名）。按「最近一篇的名字」判身份，会把这些人整批划进匿名、丢掉几十篇该处理的稿子。
正确做法：作者只要有过任一实名文章就算实名（名字取最近一篇实名的），匿名只对那一篇作品生效。

**查不到 ≠ 没评过稿。** 接口失败记 `ok=false`、分类落「未查到」，绝不判僵尸 ——
否则一个天天评稿的活跃作者会被静默抹掉，而且没有任何提示。命令末尾若有「未查到」会以退出码 1 提醒。

缓存存的是**原始时间戳**不是结论：一年这条线每天都在动，存结论会让缓存一过期就给错分类。

### 账号

- 运营账号 `huangqi`（admin），口令不在文档里写，需要时用控制台重置：
  `php -c php.ini bin\console.php user:reset-password --username=huangqi`
- 改自己的口令：右上角用户名 → `/admin/account`（验旧口令、≥12 位、不得与原口令相同、写审计但不记口令本身）。
- 权限码 11 个 × 4 角色，改完必须跑 `bin\check-permissions.php` 做三方比对。

## 2.6 一键拆解（M11，2026-11-01）

后台地址 **`/admin/oneclick`**，左侧「拆解」组第一项。这一页把整条流水线收在一个按钮后面：
**抓正文 → 入馆 → 拆解 →（可选）上架**，中间不需要人去导文件、也不需要逐篇开任务。

### 四步

1. **选范围**：期次（26 个方块，空＝全部）、作者类型（活跃/僵尸/不限）、字数区间、标题包含、
   要动哪些（还没拆的 / 失败重试 / 待上架 / 全部）、这一批最多多少篇（上限 500）、是否重跑已上架。
   条件走 GET，预览可以直接刷新、也能收藏成书签——「第 1–17 期的僵尸作品」这种范围会被反复用。
2. **看预览**：范围内多少篇、其中待入馆 / 待拆解 / 待上架各多少、预计花多少钱多少小时
   （按本库历史实测均价，不是拍脑袋的系数）、哪些被排除（匿名 / 已上架 / 正在跑 / 范围外）。
3. **按下去**：建一个批次（`hq_run_batch`），已入馆的直接排拆解，没入馆的先排抓取。
   按下即返回，活由 worker 跑；worker 没在跑时页面会提示。
4. **回来看**：最近几批，抓取与拆解**分开计**（抓取失败的作品根本没进馆，不该混进拆解分母），
   每批带「上架本批」与「取消排队」两个动作。

### 几条硬约定

- **范围单位是「官网练笔作品」**，不是「馆内已有作品」。1177 篇里绝大多数还没入馆，
  只认馆内作品的话第一次点下去只会动十几篇，剩下的还得手工导——那不叫一键。
- **匿名作品不进范围**：揭晓前官网连作者名都是「匿名用户」，导进来是一篇没有归属的作品，
  等揭晓后还得删掉重来。
- **默认不自动上架**。拆解是花钱跑机器，上架是「这些结果可以见人了」的对外承诺，
  合成一步等于让模型跑歪的产物不经人眼就出现在馆里。要自动上架在开始前勾选。
- **权限两个码**：看范围与派活 = `analysis.run`；「上架本批」= `analysis.publish`。
  会跑分析的人不一定有权把结果推到读者面前。
- **一次 500 篇**：1300 篇分几批推进，点错一次不至于烧掉整月预算；跑完再点会自动接着往后取。
- **「拆完不公开」是一等选项**（`--keep-hidden` / 页面第二个勾）。勾了之后 worker 在拆解成功后
  把作品置为 `hidden`——走 `VisibilityService`，连带失效页面缓存与重写 sitemap，
  所以是「前台任何页面都不出现」，不是「碰巧没上架」。这类批次不提供「上架本批」，
  接口层也会拒绝：留一个整批上架的口子等于没守。
- **已排队的不重复计入**。预览会跳过「已排 `source.ingest`」和「已排 `analysis.run`」的作品，
  所以连着预览两次数字会变小 —— 那是已派出去了，不是丢件。

### 命令行同一个入口

```bat
:: 预览：会派多少篇、约多少钱（不带 --apply 不落库）
D:\webserver\php\php.exe -c php.ini bin\console.php pipeline:oneclick --issues=1,2 --stage=todo --limit=50

:: 派活
D:\webserver\php\php.exe -c php.ini bin\console.php pipeline:oneclick --issues=1,2 --stage=todo --limit=50 --apply
```

参数与页面一一对应：`--author=active|zombie`、`--min`、`--max`、`--q`、`--stage`、`--limit`、
`--auto-publish`、`--keep-hidden`、`--force`。两条路走的是同一个 `OneClick` 用例，
不会出现「页面能跑、命令行跑出别的结果」。

### 清空重来（换模型 / 正式生成前）

```bat
:: 预览：会删多少行、多少篇作品退回待拆解
D:\webserver\php\php.exe -c php.ini bin\console.php library:purge-analysis

:: 真删（先 mysqldump 备份）
D:\webserver\php\php.exe -c php.ini bin\console.php library:purge-analysis --apply
```

删的是**从正文算出来的东西**：分析、阶段、修订、剧情、伏笔、索引卡、读者简报、修改建议、
索引词与词条关联、导入/拆解留下的任务与批次。**不删**作品本体、原文文件、作者、期次、
审计日志、AI 调用账单、访客搜索词 —— 那几样要么是原始素材，要么是账。

作品退回「已入馆待拆解」（`status=imported`、`current_analysis_id=NULL`、摘要与发布时间清空），
所以下次 `--stage=todo` 就能重新拆，不必再抓一遍正文。命令跑完会清页面缓存并重写 sitemap，
不会留下「作品已经删了、读者还能从缓存里看到」的窗口。

### 人工指定作者分类

机器按「近一年有投稿或评稿」判，但只在评论区活跃的人会被判成「仅评论」，
既不算活跃也不算僵尸。人工指定单独存 `activity-override.json`，**重跑 `source:activity`
不会被冲掉**，快照里还会留 `state_auto` 记下机器原本判的是什么：

```bat
D:\webserver\php\php.exe -c php.ini bin\console.php source:activity-override --list
D:\webserver\php\php.exe -c php.ini bin\console.php source:activity-override --set=鸑鷟=active
D:\webserver\php\php.exe -c php.ini bin\console.php source:activity-override --remove=鸑鷟
```

### 数据落了哪

| 表 / 列 | 作用 |
| --- | --- |
| `hq_work.source_cid`（唯一键） | 官网 cid ↔ 馆内作品。没有它「这篇入馆了吗」只能靠标题猜，重导一次就是两份正文、两次拆解费用 |
| `hq_job.batch_id` | 任务属于哪一批。统计进度按它分组，塞进 payload 里既走不了索引也依赖键序 |
| `hq_run_batch` | 批次收据：当时的范围快照、计划多少篇、其中多少要抓取、谁点的、备注 |

### 实测（2026-11-01，端到端一次真跑）

第 1 期 1 篇《虫子》：`source.ingest` 抓正文 → 入馆（3675 字，`source_cid=10`，原文版本 1 条）
→ 自动接 `analysis.run` → 拆解成功 → 「上架本批」→ 作品 `published`、前台详情 200
→ 用 `library:unpublish` 回滚成「已拆未上架」，站点回到原状（已上架 15 篇 → 14 篇）。

顺带修掉一个存量 bug：`library:publish` / `library:unpublish` / `library:reindex` 三条命令
自 `SearchService` 增加第二个构造参数（公开文本守卫）之后**一直致命错误**（`ArgumentCountError`），
命令静默 exit 255。已修，三条命令恢复可用。

## 千篇级连续拆解手册（1300 篇实测推演）

实测口径：19 篇已完成分析，**平均 1.4 分钟/篇、0.12 元/篇**（含重试与闸门回灌）。
1300 篇 ≈ **30 小时串行 / 160 元**；daily 预算 100 元（`ai.budget.daily_yuan`）→ 约 2 天跑完。
提速：`max_concurrency` 目前无客户端实现，worker 是串行抢占（`UPDATE ... LIMIT 1` 原子领任务），
**可同时开 2–3 个 worker 进程**近线性提速，互不冲突。

**前台承接（2026-11-01 复核）**：作品列表与期次页是双向游标翻页（上一页 / 回到第一页 / 下一页），
词条页的作品列表按权重展示最相关的 60 篇、超过时给「全部 N 篇 →」进 `/library/works?term=` 分页列表；
搜索硬上限 200 条按得分排序；sitemap 无上限（1300 篇 ≈ 1700 条 URL，单文件限额 5 万）。
官网是纯静态入口页、无任何随馆藏膨胀的计数，无需改动。

**规模演练（2026-11-01 实测，不是推演）**：`bin\bench-scale.php` 在一个事务里把作品补到
1300 篇、拆解 1300 条、关键词关联 12854 行、词条 368 个，逐条量关键查询，最后无条件回滚
（生产数据一行不留）。实测结果：

| 查询 | 中位耗时 |
| --- | --- |
| 前台作品列表 48/页 | 50 ms |
| 后台作品列表 50/页 | 12 ms |
| 词条共现推荐 | 52 ms |
| sitemap 全量（≈1700 URL） | 90 ms |
| 词条页 / 按词检索 / 同词作品 | < 1 ms |

最慢 97 ms，**没有一条超过 200 ms**。前台整页缓存 60–300 秒，读者侧基本命中缓存。

```bat
D:\webserver\php\php.exe -c php.ini bin\bench-scale.php --reps=15
```

演练同时暴露并修掉了一处真问题：前台/后台作品列表对 `hq_work` 做**全表扫 + filesort**，
因为 `idx_published` 建在 `published_at` 上，而默认排序是 `w.id DESC`。
`007_scale_indexes.sql` 补了 `idx_feed_visible (visibility, id, deleted_at)` 与
`idx_admin_visible (visibility, created_at, deleted_at)`。

**列序是这次的关键**：第一版写成 `(visibility, deleted_at, id)`，`type` 从 `ALL` 变成 `range`，
但 filesort 还在、耗时只从 55 ms 降到 50 ms —— 因为 `deleted_at IS NULL` 是范围条件，
排在 `id` 前面就断掉了索引提供的顺序。把 `deleted_at` 挪到最后、交给索引条件下推过滤才对。

**判断索引有没有用，别看 EXPLAIN 里 key 空不空**：事务内插入不刷新 InnoDB 统计信息，
演练里的优化器仍按旧行数估成本，选择不可信。脚本最后做了「强制走 / 强制不走」的对照，
实测 **0.91 ms vs 12.44 ms（13 倍）**，这才证明索引该留。

```bat
cd /d D:\AITEST\huangqi-scifi\server

:: ① 导入语料（open_basedir 限制，语料必须先放进 server/storage/import-stage/）
D:\webserver\php\php.exe -c php.ini bin\import.php --dir=storage\import-stage\第N批 --author=佚名

:: ② 批量入队（先预览再加 --apply；已入队/在跑的作品自动排除）
D:\webserver\php\php.exe -c php.ini bin\console.php analysis:batch --enqueue --limit=200
D:\webserver\php\php.exe -c php.ini bin\console.php analysis:batch --enqueue --limit=200 --apply

:: ③ 起 worker（单窗口单进程；要并行就再开 1-2 个窗口各跑一条）
D:\webserver\php\php.exe -c php.ini bin\worker.php

:: ④ 随时看进度与花费
D:\webserver\php\php.exe -c php.ini bin\console.php analysis:batch --stats

:: ⑤ 隔天预算恢复 / 闸门耗尽的失败任务重新入队
D:\webserver\php\php.exe -c php.ini bin\console.php analysis:batch --requeue-failed --apply

:: ⑥ 中断残留（进程被杀、analysis 卡 running）解卡
D:\webserver\php\php.exe -c php.ini bin\console.php analysis:reap --minutes=30 --apply

:: ⑦ 一批跑完的收尾：词表对齐 → 清退役词 → 百科覆盖校验 → 重建检索 → 落 sitemap
D:\webserver\php\php.exe -c php.ini bin\console.php term:sync-taxonomy --apply
D:\webserver\php\php.exe -c php.ini bin\console.php term:prune --apply
D:\webserver\php\php.exe -c php.ini bin\check-encyclopedia.php
D:\webserver\php\php.exe -c php.ini bin\console.php library:reindex
D:\webserver\php\php.exe -c php.ini bin\console.php sitemap
```

跑大批次前的注意项：

- **提示词版本冻结**：`input_hash` 含提示词指纹，中途改提示词 = 全部已跑的作废重算（钱 ×2）。
  要改提示词，等这一批跑完再改，再对失败的用 `analysis-rerun.php` 定点重跑。
- **Windows 没有进程守护**：worker 默认跑 24h 自退（`--max-seconds`），被更新/注销打断也没关系——
  心跳超时的任务会被下一个 worker 自动回收重跑；重新拉起 worker 即可续跑。
- **新词会缺百科**：1300 篇预计产生大量表外新词，`check-encyclopedia.php` 会点名；
  词条详情页对缺百科的词只隐藏「起源与发展 / 世界科幻中的它」两节，不报错。
- **成本刹车**：单篇 3 元（`ai.per_work_budget_yuan`）、单日 100 元（`ai.daily_budget_yuan`），
  均可在 `hq_setting` 运行时调整，不用改代码。

**改完模板或 CSS 看不到变化**：页面被整页缓存了（最长 5 分钟）。开发时直接清：

```bat
del /q storage\cache\pages\*.html storage\cache\pages\*.json
```

静态资源不用管版本号——`library.css` / `library.js` / `theme.js` 的 `?v=` 取文件 `mtime`，
改了文件名不变但版本号会变，读者不会拿着旧样式。

主题跟随（2026-11-01）：官网与馆共用 `hq-theme` 这一个 localStorage 键。两站不同源时
localStorage 不互通，所以官网的 `/library` 链接在点击瞬间由 js/main.js 的委托补上
`?theme=day|night`（本机还会先补 8090 源），馆内 `theme.js` 的取值优先级是
**URL 参数 > 本地记忆**，取到参数就回存 —— 从官网点进馆，首帧就是官网当前的那套主题。

视觉验收（12 个页面 × 2 档位 × 2 套主题，顺带审计渐变 / emoji / 内联脚本 / 横向溢出 /
主题是否真的生效）：

```bat
node ..\tools\shoot-library.cjs                  :: 夜间（默认），出 24 张
set THEME=day && node ..\tools\shoot-library.cjs :: 浅色，出 24 张
:: WIDTHS=390 PAGES=work,home 可缩小范围

:: 输出 .build\shots-library\<页面>-<宽度>-<主题>.png + audit-<主题>.json
```

整页截图动辄 9000px 高，在预览里缩成一条，看不清排版细节。要按区块取景用另一支：

```bat
set URL=http://127.0.0.1:8090/library/works/<作品code>
set NAME=work && set THEME=day && node ..\tools\shoot-clip.cjs
:: 依次输出 首屏 / #index 关键词 / #plot 剧情 / #advice 建议 / #revision 修改方向 / 元信息 六屏
```

## 3. 凭据位置（不在本文件里写明文）
| 内容                    | 位置                                                | 说明                                 |
| --------------------- | ------------------------------------------------- | ---------------------------------- |
| 应用连接串 / KEK / DeepSeek Key | `server/.env`                                     | 已在 `.gitignore`；模板见 `.env.example` |
| root 客户端配置（免命令行输密码）   | `D:\webserver\mysql56\client-root.cnf`            | 仅本机使用                              |
| 应用账号客户端配置             | `D:\webserver\mysql56\client-app.cnf`             | 用于验证最小权限                           |
| 建库脚本（含一次性口令，已执行）      | `D:\webserver\mysql56\000_bootstrap.executed.sql` | 归档用，勿外传                            |

## 4. 自检

```bat
curl http://127.0.0.1:8090/healthz
```

期望：`status=ok`、`db.version=5.6.51-log`、`db.tables=39`、`ai.state=configured`、
`queue.queued/running/failed` 三项队列计数、`ai.cost_today_yuan` 今日成本。


**AI 连通性**（三级，日常用前两级）：

```bat
:: ① 只看配置与用量，不发请求、不产生费用
curl http://127.0.0.1:8090/healthz

:: ② 参数自检（不发请求）
D:\webserver\php\php.exe -c php.ini bin\test-ai.php --dry

:: ③ 真调一次：模型清单 / 延迟 / 思考量 / finish_reason / 空返回成因判定
D:\webserver\php\php.exe -c php.ini bin\test-ai.php
D:\webserver\php\php.exe -c php.ini bin\test-ai.php --heavy   :: 追加 max_tokens 阶梯与思考开关对照（多次计费）
```

`test-ai.php` 回答三个问题：Key 是否有效、当前账号能用到哪些模型、
以及**空 content 是「思考吃光 token」还是「真空返回」**——这两者处置完全不同。

数据库连通性与权限：

```bat
D:\webserver\mysql56\bin\mysql.exe --defaults-file=D:\webserver\mysql56\client-app.cnf -e "SELECT COUNT(*) FROM hqlib.hq_work;"
:: 期望：能查；但 CREATE TABLE 会被拒（ERROR 1142），这是最小权限生效的标志
```

权限矩阵与路由覆盖（M4 起必查）：

```bat
D:\webserver\php\php.exe -c php.ini bin\check-permissions.php
:: 期望：打印 10 行权限矩阵，末行「三方一致：代码契约 = 数据库 = 路由表。」
:: 任何一项不一致都会列出具体条目并以非 0 退出
```

隐藏词条的公开泄漏（改过可见性、文本渲染或检索之后必查）：

```bat
D:\webserver\php\php.exe -c php.ini bin\scan-term-leak.php
:: 期望：呈现层词形 0 处、作品链接 0 处，检索层 0 篇，消亡层全部 404；退出码 0。
:: 退出码 2 表示有页面因限流没验证到，结论不完整，不是通过。
```

### 4.1 安全自检（附录 E 68 项，可重放）

```bat
D:\webserver\php\php.exe -c php.ini bin\security-audit.php
:: 带会话类检查（E-32 会话固定 / E-36 角色越权）——注意是 --qa，不是 --pass：
D:\webserver\php\php.exe -c php.ini bin\security-audit.php --qa=qa_viewer
```

**`qa_viewer` 没有常驻口令，这是故意的。** 会话类检查必须真的登录一次，但把口令写进
文档、脚本或命令行历史，等于为了跑一次检查永久开一个后门。`--qa` 的做法是：开跑前把口令
随机成 36 位（只在进程内存里），结束时——正常退出、fail 退出、异常退出都算——再随机成另一串。
净效果是**这个账号任何时候都没有一个人知道的口令**，想用它登录必须先跑一次自检。
进程被 kill 时口令停在本次随机值上，依然不可猜。`--user/--pass` 仍保留给需要固定凭据的场合。

期望末行：`合计 68 项：通过 60 · 不通过 0 · 待人工 2 · 不适用 6`，报告落在
`storage/reports/security-audit-*.json`。**有 fail 时退出码为 1**，所以它能直接进 CI。

三种判定互不替代：`pass`（实测通过，证据可复查）、`fail`（必须修，修完重跑）、
`manual`（机器判不了）、`na`（本系统没有这个威胁面，理由写在证据里）。

**`-c php.ini` 不是可选项。** E-02…E-05 读的是「当前进程加载了哪一份 php.ini」，
漏掉 `-c php.ini` 会把 4 条本来通过的检查判成 `fail`（实测 `通过 50 · 不通过 4`），
所以脚本会先自查 ini 路径，不是项目那份就直接 `exit 2` 并打印正确命令 ——
**退出码 2 = 命令写错，不是配置坏了**，别照着 `fail` 去改 `disable_functions`。

剩下 2 项 `manual` 是「机器判不了」，不是「没做」：E-61 TLS 要 SSL Labs 评真实域名，
E-67 只差一行生产 webhook（代码链路已经演练通过，见 §4.5）。

### 4.2 备份与恢复演练（E-56 / E-65）

```bat
D:\webserver\php\php.exe -c php.ini bin\backup.php              :: 加密备份，默认留最近 7 份
D:\webserver\php\php.exe -c php.ini bin\backup.php --list       :: 看现有备份
D:\webserver\php\php.exe -c php.ini bin\backup-restore-drill.php :: 恢复到 hqlib_drill 并逐表比对
```

- 备份是**分块 AES-256-GCM 加密**的逻辑快照（`.dump.enc`）：明文头只留「怎么拆块」，
  表名、建表语句、数据、校验和全在密文里。分块是为了库里长到 2GB 那天不会 OOM。
- 每张表记「行数 + 各行 md5 的异或」。异或与行序无关，所以校验的是**内容一致**，
  不是「文件字节一致」—— 恢复后行序变了也照样能比。
- 演练写到独立的 `hqlib_drill` 库，用的是独立账号（`DRILL_DB_*`）。应用账号没有 DDL 权限，
  这一点不该为了演练放宽。
- 演练结论落 `storage/reports/restore-drill-latest.json`；附录 E-65 的判定读它，
  并要求结论是 7 天内的 —— **过期就等于没演练**。

首次启用（只需一次）：

```bat
D:\webserver\mysql56\bin\mysql.exe --defaults-file=D:\webserver\mysql56\client-root.cnf -e ^
  "CREATE DATABASE IF NOT EXISTS hqlib_drill CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" ^
  "CREATE USER 'hqlib_restore'@'127.0.0.1' IDENTIFIED BY '<口令>';" ^
  "GRANT ALL PRIVILEGES ON hqlib_drill.* TO 'hqlib_restore'@'127.0.0.1';"
```

然后把口令写进 `.env` 的 `DRILL_DB_PASS`。

### 4.3 渗透自测（15 项）

```bat
D:\webserver\php\php.exe -c php.ini bin\pentest-self.php
:: 期望：共 15 项，通过 15，失败 0
```

覆盖注入（排序 / 检索词 / 时间盲注 / 对照参数）、越权（未发布作品、期次与词条枚举）、
穿越与源码泄漏、反射 XSS、CRLF 注入、超长与二进制输入、方法篡改、未登录写后台、公开读限流。
**这是自测不是渗透测试**：业务逻辑漏洞与多步组合攻击仍在签字表的 manual 项里。

### 4.4 压测

```bat
node ..\tools\loadtest-library.cjs
:: 单请求真实延迟（并发 1，排除排队影响）
CONCURRENCY=1 REQUESTS=40 node ..\tools\loadtest-library.cjs
```

**读数的正确姿势**：Windows 上 PHP 内置服务器不能 fork（`PHP_CLI_SERVER_WORKERS` 会打印
`forking is not supported on this platform`），单进程上限约 40 req/s，所以**并发吞吐不能当容量结论**。
这里只取两个可信的：

| 指标 | 实测 | 说明 |
| --- | --- | --- |
| 缓存页 p50 / p95（并发 1） | 24ms / 26ms | 12–58KB 的页面延迟一样，说明瓶颈不在页面大小 |
| 检索页 p50（不缓存） | 63ms | 走词库表 + LIKE 兜底，比缓存页慢 2.6 倍，符合预期 |
| 缓存命中率（并发 16） | 100% | 160 次请求全部 HIT，**且没有一次 429** —— 缓存在限流之外，这是设计的核心 |

### 4.5 采集与演练（6 条检查的实测来源）

这 6 条在 §4.1 里不是靠读代码判的，而是靠「先跑一个脚本，再由自检读报告」。分开的原因是
**PHP 端没有能力做这些事**：`disable_functions` 禁了 exec（E-04 的依据），Windows 下
`fileperms()` 返回假数，composer 要写临时文件而 `open_basedir` 只放行两处。

| 项 | 命令 | 报告 | 过期 |
| --- | --- | --- | --- |
| E-58 `.env` 权限 | `powershell -ExecutionPolicy Bypass -File bin\check-env-acl.ps1` | `env-acl.json` | 30 天 |
| E-62 依赖漏洞 | `powershell -ExecutionPolicy Bypass -File bin\check-deps.ps1` | `composer-audit.json` | 14 天 |
| E-67 告警通道 | 见下（两终端） | `alert-drill-latest.json` | 90 天 |
| E-68 回滚可执行 | `php -c php.ini bin\rollback-drill.php` | `rollback-drill-latest.json` | 90 天 |
| E-40 二次验证 | `php -c php.ini bin\totp-drill.php` | `totp-drill-latest.json` | 90 天 |

**过期即退回 manual** —— 一张三个月前的「通过」不算证据，这是设计而不是疏漏。

E-67 需要两个终端（演练是「别人写的接收端真的收到了」才算数）：

```bat
:: 终端 A：起一次性接收端（它只会被这一个演练用）
D:\webserver\php\php.exe -S 127.0.0.1:18099 bin\alert-sink.php
:: 终端 B
D:\webserver\php\php.exe bin\alert-drill.php --sink=http://127.0.0.1:18099/alert
```

四条断言：真的收到并 2xx（不是「curl 没报错」）、报文里 Key/手机号/邮箱已掩码、
同标题 30 分钟内不重复发、对方不可达时**不抛异常**（告警是旁路，不能把业务拖走）。

**为什么不自动起接收端**：那需要 `proc_open`，而项目禁了它（E-50）。这条禁令本身是对的 ——
多敲一条命令，换掉一个永久豁免口子，划算。

`bin\rollback-drill.php` 在 `hqlib_drill` 上走完整闭环：001 撤回（表清零）→ 001 前滚（39 表回来）
→ 002 施加 → 002 撤回（**UPDATE 必须成功**，这才是「撤回生效」的证据）→ 002 再施加。
「没报错」不等于「回滚了」，所以验证一个保护被拆掉，必须看到它保护的东西真的能改。

## 5. 迁移（执行 / 撤回两段式）

**用执行器，不要手工喂 mysql.exe。** `bin\migrate.php` 按文件名排序执行、把 checksum 记进
`hq_migration`、`*_rollback.sql` 永不自动跑，并且会先告诉你「哪些还没跑」：

```bat
:: 看状态
D:\webserver\php\php.exe -c php.ini bin\migrate.php --status

:: 执行（DML 账号没有 ALTER，这是有意的权限边界；DDL 凭据单独给）
D:\webserver\php\php.exe -c php.ini bin\migrate.php --apply --user=root --password=***
:: 或命令行不想留密码：
set MIGRATE_DB_USER=root && set MIGRATE_DB_PASS=*** && D:\webserver\php\php.exe -c php.ini bin\migrate.php --apply
:: 或在 storage\mysql-admin.cnf 写 [client] user/password（已 gitignore）
```

`--baseline` 适合「表已经手工建好了」的场景，**务必配 `--through=NNN` 划界**，
否则会把尚未执行的迁移也标成已应用——记录说跑过了、字段其实不存在。

| 迁移 | 内容 |
| --- | --- |
| `001_init.sql` | 39 张表 + 种子 |
| `002_audit_append_only.sql` | 把 `hq_audit_log` 变成数据库层强制的 append-only |
| `003_keyword_v2.sql` | 关键词 v2（题材/情绪退出词库） |
| `004_term_canonical.sql` | 词条正名与归并 |
| `005_visibility.sql` | 三级可见性（作品/拆解/词条）+ `hq_job.cancel_requested` + 权限码 `visibility.toggle` |
| `006_job_work_id.sql` | `work_id` 从 `payload_json` 提成真实列并建 `idx_work_status` |
| `007_scale_indexes.sql` | 列表口径索引（见上一节规模演练） |
| `008_search_keyword_term.sql` | `hq_search_keyword.term_id`：让「词条被隐藏」在检索里也成立 |

`005` 为什么单独加可见性列而不是复用 `status`：隐藏后恢复不能改变发布时刻与排序位次，
塞进 `status` 就必然要「记住原来的值」，那是另一种脆弱。

`008` 为什么加一列而不是「隐藏时重建索引」：关键词表是物化的，重建是「写时失效」思路，
只要有一条路径忘了重建（手工改库、脚本直连、将来新加的发文流程），泄漏就静默发生且无人知晓。
加 `term_id` 之后可见性判断回到**查询时**，和其它三处 JOIN 同一口径。
**执行后必须跑一次 `library:reindex`** —— 2-gram（「克制」之于「克制的悲伤」）无法用 SQL
反推归属，只有重建才带得上 `term_id`，否则隐藏词条的片段仍会被搜到。

`005` 为什么单独加可见性列而不是复用 `status`：隐藏后恢复不能改变发布时刻与排序位次，
塞进 `status` 就必然要「记住原来的值」，那是另一种脆弱。

`006` 之前「这篇在队列里吗」靠 `payload_json LIKE '%"work_id":123,%'` 判断——
1300 篇下是 1300 次全表扫，而且依赖 JSON 里的键序，纯属埋雷。

`002` 为什么用触发器而不是细授权：MySQL 的权限是**并集**，库级已授予的 UPDATE 撤不回来，
要撤就得把 39 张表逐条重授，以后每加一张表就漏一次。触发器是库级的、跟着数据走的，
绕不过去。同理，`.env` 里的应用账号只有 DML 权限、没有 ALTER —— 应用被攻破也改不了表结构。

## 6. 目录职责

```
public/      唯一 Web 根（index.php 入口；assets/ 后台样式与脚本；media/ 只放重编码后的图片）
app/         控制器 / 中间件 / 应用服务 / 仓储 / 导入解析 / 模型客户端
             ├── Application/  Auth（登录 + Permission 权限契约）· Work（导入用例 + TextDiff）
             │                 · Analysis（分块 / 提示词 / 闸门 / 流水线 / 阶段依赖 / AnalysisException）
             ├── Http/         Controller（Admin / Library / Health）· Middleware（会话 / CSRF / 限流 / 认证 / 授权
             │                 / 公开页缓存 / 公开读限流）· Support（ErrorPage / LibraryErrorPage / PublicPageData）
             ├── Infrastructure/ Pdo（仓储：Work / Analysis / AiCall / Job / Term / Setting / User / Session / Audit
             │                 / **Library**＝前台只读，发布口径写在 PUBLISHED_JOIN 常量里）
             │                 · Import（txt/md/docx 解析）· Storage（原文落盘）
             │                 · Ai（DeepSeek 客户端 / 成本模型）
             ├── Support/      Config · Db · Crypto · Session · Text · Totp · Ulid · Log
             └── View/         Twig 工厂与渲染
config/      settings（读环境变量）· ai（模型/单价/六阶段参数）· permissions（路由→权限）
             · container（容器装配）· dependencies（容器定义）· routes（路由表，每条都 setName）
             · middleware（中间件顺序）· bootstrap（自启）
database/    migrations（001_init.sql、回滚脚本、建库模板）
resources/   Twig 模板（admin/… · library/…＝公开前台，_bg.twig 是官网背景层的
             副本——改背景必须与 tools/build-pages.cjs 的四常量同步）· prompts/v4（六阶段提示词，是代码资产）
             · taxonomy（sf_terms 词表 · retired_terms 退役表 · term_encyclopedia 词条百科）
public/      assets（library.css / library.js / theme.js / fonts/woff2 / favicon）· sitemap.xml（可选静态）
storage/     ★ 不在 Web 根：uploads / artifacts（模型响应快照）/ reports（回归报告）
             / logs / cache/pages（整页 HTML 缓存）/ import-stage（外部语料暂存，导入前先拷进来）
bin/         console.php（账号 / 种子 / library:* / term:* / sitemap）· analysis-rerun.php（重跑某阶段）
             · analyze.php（单篇直跑）· worker.php（队列消费）· regress.php（批量回归）
             · import.php（目录批量导入）· recount-words.php（字数重算）
             · check-permissions.php（权限三方对齐）· check-taxonomy.php（词表自检）
             · check-encyclopedia.php（词条百科结构与覆盖自检）
             · security-audit.php（68 项安全审计）· pentest-self.php（自攻击 15 项）
             · backup.php（加密备份）· backup-restore-drill.php / rollback-drill.php
             · totp-drill.php / alert-drill.php + alert-sink.php（演练，跑完自动还原）
             · check-deps.ps1 / check-env-acl.ps1（Windows 环境核查，需要 PowerShell）
tests/       PHPUnit（Unit：Ulid / Totp / Text / Crypto / Chunker / StageGate / CostModel
             / Permission / StageDependency / TextDiff / Cursor / Highlight / PageCache / SitemapBuilder
             / TermEncyclopedia）
```

## 7. 铁律（与文档第 11 章一致）

1. 所有 SQL 走 PDO 预处理，禁止字符串拼接；排序/列名走白名单。
2. 上传文件只进 `storage/uploads`，永不进 Web 根，扩展名 + MIME + 内容三重校验。
3. 不做 `unserialize`、不做动态 `include`、不调 `exec/system`。
4. `.env` 不进版本库；日志必须脱敏；审计表只允许 INSERT。
5. 任何写操作都要 CSRF + 权限 + 审计三件套。
6. **公开页零会话**：`GET /library*`、`/sitemap.xml`、`/`、`/healthz` 不启 session、不写 `Set-Cookie`、
   不读 `SESSION`，否则整页缓存与公共 CDN 全部失效（`SessionMiddleware::isPublicRead()` 守着）。
   反过来说：**别在路由里用 `static` 闭包**——Slim 会把闭包 bindTo 容器，PHP 不允许绑 `$this`，
   结果是 500（`/` 与 `/admin/` 曾因此各挂过一次）。
7. **发布口径只有一个写法**：`LibraryRepository::PUBLISHED_JOIN`（作品已发布 ∧ 当前分析指针指向它 ∧
   分析已发布）。前台任何「查作品」的 SQL 都拼这个常量，不许各写各的。
8. **前台不拼 HTML**：用户输入只以文本片段交给 Twig，`<mark>` 之类写在模板里，模板负责转义。
