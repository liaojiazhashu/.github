-- 012 回滚：撤掉练笔统计表
--
-- 这两张表里存的都是可重算的派生数据（口径在 IssueStats 里，源数据在官网），
-- 回滚不丢原始事实，重跑一次 `source:stats --apply` 即可重建。
-- 但历史快照会一并消失 —— 需要留档先导出：
--   mysqldump ... hqlib hq_practice_stat hq_practice_stat_run > practice_stat_YYYYMMDD.sql

DROP TABLE IF EXISTS hq_practice_stat;
DROP TABLE IF EXISTS hq_practice_stat_run;
