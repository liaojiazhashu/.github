-- 009：让期次以官网为准
--
-- 问题：hq_issue 里的起止时间是 IssueSchedule 按「9 期/年、月份周期」推出来的，
-- 但把官网 26 个练笔活动的 deadline 拉下来一比，**1~24 期的实际截稿与推算差了一个月**
-- （例：第 13 期官网 2025-06-01 截稿，推算给的是 2025-07-01）。
-- 规则本身没错（仍是 9 期/年），错在相位：第 1 期是 1 个月的窗口，不是 2 个月。
--
-- 所以这里不删推算值，而是**并存**：
--   · starts_at / ends_at        —— 馆内自己用的窗口，没有官网数据时靠推算
--   · source_ends_at             —— 官网记的截稿时刻，有值就代表「这一期已对准官网」
--   · source_reveal_at           —— 官网记的揭晓时刻；揭晓前作者一律显示「匿名用户」
--   · source_mid                 —— 官网活动 id，用来去重和回查
--
-- 为什么不在迁移里直接改 ends_at：迁移是一次性的，而期次会一直加。
-- 数据同步交给 `source:issue-sync`（可重复执行），迁移只负责把字段准备好。

ALTER TABLE hq_issue
    ADD COLUMN source_mid INT UNSIGNED NULL DEFAULT NULL COMMENT '官网练笔活动 mid' AFTER status,
    ADD COLUMN source_ends_at DATETIME NULL DEFAULT NULL COMMENT '官网记录的截稿时刻' AFTER source_mid,
    ADD COLUMN source_reveal_at DATETIME NULL DEFAULT NULL COMMENT '官网记录的揭晓时刻' AFTER source_ends_at;

-- mid 是官网活动的唯一 id。允许 NULL 是因为这一列只有练笔期次才填，
-- 而且历史数据在同步之前全都是 NULL。
ALTER TABLE hq_issue
    ADD UNIQUE KEY uk_source_mid (source_mid);
