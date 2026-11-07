-- 009 回滚：撤掉官网对齐字段
--
-- 撤字段等于「放弃与官网对齐」，之后期次时间只剩 IssueSchedule 推算值。
-- 因为这三列都是新增的旁路数据，撤掉不影响 works / analyses 的引用关系。

ALTER TABLE hq_issue DROP INDEX uk_source_mid;

ALTER TABLE hq_issue
    DROP COLUMN source_reveal_at,
    DROP COLUMN source_ends_at,
    DROP COLUMN source_mid;
