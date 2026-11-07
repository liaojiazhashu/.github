-- 012：练笔统计（按期 + 总计）
--
-- 需求来自「按每一期和总计两个维度统计：公开作品数、未公开作品数（含匿名作品）、
-- 作者数、评稿成员数、评稿数、作品总字数，且排除回收站与草稿箱」。这些话每一条
-- 都是一个口径，口径会被人反复问起（「为什么第 25 期有 80 篇却只算 61 篇」），
-- 所以口径必须落在表里，而不是留在某个脚本的输出里。
--
-- 为什么不在统计时现算、不建视图：
--   1. 现算要翻 26 个期次的评稿列表（约一百五十次外部请求、两分钟），页面扛不住；
--   2. 官网数据会动（撤稿、改公开状态、补评稿），而「上个月统计出来的数」本身
--      是历史事实，需要留存快照，否则没法回答「这个月为什么比上个月少了三篇」。
--
-- 两张表的分工：
--   hq_practice_stat_run  一次重算 = 一行。记开始/结束、用了哪份目录、算了几期。
--   hq_practice_stat      一次重算 × 一个期次 = 一行；issue_no = 0 那一行是「总计」。
--                         总计单独成行而不是查询时 SUM，是因为作者与评稿成员要**去重**
--                         再求和 —— 同一个人投过三期，合计只能算一个作者。

CREATE TABLE hq_practice_stat_run (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`        CHAR(26) NOT NULL COMMENT 'ULID，报告里用它指代某一次重算',
    `status`      ENUM('running','done','failed') NOT NULL DEFAULT 'running',
    `issues`      SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '本次算出的期次数（不含总计行）',
    `catalog_at`  DATETIME NULL DEFAULT NULL COMMENT '所用目录快照的抓取时间',
    `catalog_n`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '目录里的文章总数',
    `triggered`   VARCHAR(16) NOT NULL DEFAULT 'cron' COMMENT 'cron / manual / cli',
    `note`        VARCHAR(200) NULL DEFAULT NULL,
    `error`       VARCHAR(500) NULL DEFAULT NULL,
    `started_at`  DATETIME NOT NULL,
    `finished_at` DATETIME NULL DEFAULT NULL,
    `seconds`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY uk_code (`code`),
    KEY idx_status_started (`status`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='练笔统计重算批次';

CREATE TABLE hq_practice_stat (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `run_id`              BIGINT UNSIGNED NOT NULL,
    `issue_no`            SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = 总计行',
    `source_mid`          INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '官网活动 mid；总计行为 0',
    `label`               VARCHAR(32) NOT NULL COMMENT '「第 25 期」/「总计」',

    -- 作品口径：只数 type=post 且未进回收站；草稿箱（post_draft）与回收站一律不进分母
    `works_public`        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '公开：isopen=1 且 status=publish',
    `works_hidden`        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '未公开：isopen=0（含匿名作品）',
    `works_rejected`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已公开但未通过/待审：isopen=1 且 status≠publish',
    `works_total`         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '= public + hidden + rejected',
    `words_public`        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `words_hidden`        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `words_rejected`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `words_total`         BIGINT UNSIGNED NOT NULL DEFAULT 0,

    -- 人：作者按 uid 去重；匿名作品的 uid 是真实的，所以照样计入作者数
    `authors`             INT UNSIGNED NOT NULL DEFAULT 0,
    `authors_public`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '只统计公开作品的作者',
    `works_anon`          INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '作者名被抹成「匿名用户」的篇数',

    -- 评稿：只数落在本期分母内作品的评稿；AI 评稿单列，不进主口径
    `review_count`        INT UNSIGNED NOT NULL DEFAULT 0,
    `review_people`       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '去重评稿人 uid',
    `review_ai`           INT UNSIGNED NOT NULL DEFAULT 0,
    `review_people_all`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '含 AI 评稿的去重人数，便于对账',

    -- 官网自报的聚合值（hqMetas/metaInfo）。存下来只为对账：
    -- 对不上就说明口径或数据有一边变了，而不是让一个静默的差值烂在报表里
    `site_works`          INT UNSIGNED NOT NULL DEFAULT 0,
    `site_words`          BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `site_review`         INT UNSIGNED NOT NULL DEFAULT 0,
    `site_review_people`  INT UNSIGNED NOT NULL DEFAULT 0,

    `ended_at`            DATETIME NULL DEFAULT NULL,
    `reveal_at`           DATETIME NULL DEFAULT NULL,
    `fetched_at`          DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY uk_run_issue (`run_id`, `issue_no`),
    KEY idx_issue_run (`issue_no`, `run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='练笔统计快照（按期 + 总计）';
