-- 010：一键拆解（整条流水线一次跑完）
--
-- 三件事，都是为了回答同一个问题：**这一批动过哪些篇、跑到哪了**。
--
-- 1. hq_work.source_cid
--    1177 篇练笔作品在官网各有一个 cid。没有这一列，就无法判断「哪一篇已经在馆里」，
--    「一键拆解」每次都会把同一篇重导一遍（正文一样、标题一样，只有 code 不同），
--    1300 篇跑两轮就变成 2600 篇垃圾。加唯一键，让重复导入在数据库层就失败。
--    手动上传/粘贴导入的作品没有官网来源，保持 NULL。
--
-- 2. hq_job.batch_id
--    任务表原本只按「作品」索引。一键拆解要回答的是「我点的这一批跑到哪了」，
--    那是另一个维度：同一篇作品可能先后属于不同批次（跑歪了重跑）。
--    批次 id 单独成列，不塞进 payload_json —— 统计进度要按它分组，塞 JSON 里既走不了
--    索引，也依赖键序。
--
-- 3. hq_run_batch
--    批次本身要落一行：当时选的什么范围（scope_json）、计划多少篇、谁点的。
--    不存这些，「进度 320/1177」就没有上下文；三个月后回看审计日志也说不清
--    「那次到底选的是第 1–17 期的僵尸作品还是全部」。

ALTER TABLE hq_work
    ADD COLUMN source_cid BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT '官网站内文章 cid；非官网来源为 NULL' AFTER source;

-- 唯一键而不是普通索引：这是「同一篇官网作品只能入馆一次」的硬约束。
-- MySQL 的 UNIQUE 允许多个 NULL，所以手动导入的作品互不冲突。
ALTER TABLE hq_work
    ADD UNIQUE KEY uk_source_cid (source_cid);

ALTER TABLE hq_job
    ADD COLUMN batch_id BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT '所属一键拆解批次 hq_run_batch.id；单篇任务为 NULL' AFTER work_id;

ALTER TABLE hq_job
    ADD KEY idx_batch_status (batch_id, status);

CREATE TABLE hq_run_batch (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`        CHAR(26) NOT NULL COMMENT 'ULID，页面上用它寻址',
    `scope_json`  MEDIUMTEXT NOT NULL COMMENT '范围条件快照（期次/字数/作者类型/状态/关键词）',
    `options_json` VARCHAR(1000) NOT NULL DEFAULT '{}' COMMENT 'auto_publish / force 等开关',
    `planned`     SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '本次计划处理的篇数',
    `ingest`      SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '其中需要先抓取入馆的篇数',
    `status`      ENUM('running','done','canceled') NOT NULL DEFAULT 'running',
    `created_by`  INT UNSIGNED NULL DEFAULT NULL,
    `note`        VARCHAR(200) NULL DEFAULT NULL,
    `created_at`  DATETIME NOT NULL,
    `updated_at`  DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY uk_code (`code`),
    KEY idx_status_created (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='一键拆解批次';
