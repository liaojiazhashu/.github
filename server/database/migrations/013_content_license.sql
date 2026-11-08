-- 013：作品授权留痕（拆解与公开的权利基础）
--
-- 为什么必须建这两张表：
--
--   官网 library.html 上写着「馆内作品均在作者授权范围内拆解与展示，著作权归作者本人」，
--   而库里 1111 篇作品、42 张表里没有一个字段能证明这件事。这不是文案问题，是举证问题：
--   一旦作者主张未经许可传播，平台既拿不出授权，又因为自己公开宣称"已获授权"而无法
--   退到"不知情"的抗辩位置 —— 声明越肯定，举证不能的后果越重。
--
-- 另一个背景：1111 篇全部是 `source=import`，不是站内投稿。也就是说，稿件是从活动侧
--   批量导入的，导入那一刻没有任何同意动作。授权要么补，要么降级公开范围，没有第三条路。
--
-- 两张表的分工：
--   hq_license_text   条款版本。条款会改，改过之后必须能回答"这位作者当年同意的是哪一版"。
--                     正文入库存 Markdown 原文 + sha256，不存渲染后的 HTML —— 渲染会随模板变，
--                     原文不会，举证时要用原文。
--   hq_license_grant  一次授权 = 一行。作者 × 作品 × 条款版本 三者唯一。
--                     撤回不删行，只写 revoked_at —— 授权史本身是证据，删了就说不清
--                     "他曾经同意过、后来撤回了"，反而像从没同意过。
--
-- 为什么用 SET 而不是 JSON 存 scopes：
--   MySQL 5.7 的 JSON 没有原生索引，查"哪些作品允许短引用"要全表扫。SET 可以用
--   FIND_IN_SET 并且能建索引前缀。授权项是固定的六项，不存在频繁增删。
--
-- 为什么 public_level 放在 hq_work 而不是每次 JOIN 授权表算：
--   公开层级的判断在列表页、详情页、sitemap、检索索引四处都要用，每次 JOIN 三张表
--   会把最热的列表查询拖慢。它是授权的**派生值**，由授权变更时同步写入，
--   与 LIVE_WORK 一样属于"改了授权就要跟着改"的那类常量。

CREATE TABLE hq_license_text (
    `id`           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `version`      VARCHAR(16) NOT NULL COMMENT '如 v1.0，与条款文件同名',
    `title`        VARCHAR(100) NOT NULL,
    `body`         MEDIUMTEXT NOT NULL COMMENT '条款正文（Markdown 原文，不用渲染后的 HTML）',
    `body_sha256`  CHAR(64) NOT NULL COMMENT '正文摘要：证明作者同意的是这一版而非后来的版本',
    `effective_at` DATETIME NOT NULL,
    `retired_at`   DATETIME NULL DEFAULT NULL COMMENT '停用时间；停用不等于失效，老作品仍适用',
    `created_at`   DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY uk_version (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='投稿授权条款版本';


CREATE TABLE hq_license_grant (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `work_id`       INT UNSIGNED NOT NULL,
    `author_id`     INT UNSIGNED NOT NULL,
    `text_id`       SMALLINT UNSIGNED NOT NULL,
    `text_version`  VARCHAR(16) NOT NULL COMMENT '冗余存版本号：举证时不必 JOIN，单表即可出证',
    `scopes`        SET('store','analyze','publish','quote','index','archive') NOT NULL
                    COMMENT 'store=存储原文 analyze=拆解 publish=公开结论 quote=短引用 index=编入索引 archive=期次留档',
    `granted_via`   ENUM('submit','claim','notice','import') NOT NULL DEFAULT 'submit'
                    COMMENT 'submit=投稿勾选 claim=作者事后认领 notice=公告确认 import=导入时随附授权',
    `granted_at`    DATETIME NOT NULL,
    `evidence`      VARCHAR(500) NULL DEFAULT NULL
                    COMMENT '同意凭证：IP、UA、渠道单号、公告编号等，分号分隔',
    `revoked_at`    DATETIME NULL DEFAULT NULL COMMENT '撤回时间；不删行，保留授权史',
    `revoked_scopes` SET('publish','quote') NULL DEFAULT NULL
                    COMMENT '局部撤回：只撤公开或只撤引用；NULL 且 revoked_at 非空 = 全部撤回',
    `revoke_reason` VARCHAR(200) NULL DEFAULT NULL,
    `created_at`    DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY uk_work_author_text (`work_id`,`author_id`,`text_id`),
    KEY idx_work (`work_id`),
    KEY idx_author (`author_id`),
    KEY idx_revoked (`revoked_at`),
    KEY idx_granted_at (`granted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='作品授权留痕（一行 = 作者对一篇作品的一次授权）';


-- 公开层级：把"有没有授权"翻译成页面能直接用的开关。
--   0 = 未授权：不出现在公开区（保留在后台，可继续内部使用）
--   1 = 仅结论：公开拆解结论，但隐藏 quote / evidence 等原文引用
--   2 = 结论 + 短引用：完整授权
--
-- 现有 1111 篇已经在线，直接置 0 会让整个图书馆瞬间空掉 —— 那是对读者的破坏性变更，
-- 不是合规修复。已发布的先统一置 1：**保持页面可访问，只收掉原文引用那部分**。
-- 这是"无授权时能公开的最大范围"，也是补授权期间的过渡态。
ALTER TABLE hq_work
    ADD COLUMN `public_level` TINYINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT '公开层级：0=未授权不出库 1=仅结论无引用 2=结论+短引用' AFTER `visibility`,
    ADD KEY `idx_public_level` (`public_level`);

UPDATE hq_work SET `public_level` = 1 WHERE `status` = 'published';


-- 条款正文**不写进迁移**，由 bin/license-sync.php 从 resources/legal/*.md 导入。
--
-- 原因有两条：
--   1. 条款正文约三千字，塞进 SQL 要做大量引号转义，改一次就要写一条新迁移，
--      而条款改版是业务动作，不该走 DDL 流程；
--   2. 更关键的是 sha256 一致性 —— 条款文件被改动而库里 body_sha256 没跟着变，
--      就会出现"作者同意 A 版、系统却指向 B 版"的举证断裂。让脚本算摘要、
--      发现不一致就报错，比让迁移静默写入可靠。
--
-- 导入（可重复执行，已存在同版本且摘要一致时跳过）：
--   php -c php.ini bin/license-sync.php --apply
-- 校验（只读，退出码 0 通过 / 1 不一致）：
--   php -c php.ini bin/license-sync.php --check
