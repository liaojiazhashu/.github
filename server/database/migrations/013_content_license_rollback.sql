-- 013 回滚：撤掉授权留痕
--
-- 注意：回滚会**丢掉全部授权证据**。这不是可重算的派生数据（对比 012 的统计表），
-- 授权记录一旦删除无法重建 —— 作者什么时候同意过哪一版条款，只有这张表知道。
-- 所以回滚前必须先导出：
--
--   mysqldump -uhqlib_app -p hqlib hq_license_text hq_license_grant \
--     > license_YYYYMMDD.sql
--
-- 另外，回滚后 public_level 一并消失，模板里若已经引用了该字段会直接 500。
-- 因此回滚的顺序必须是：先撤模板引用，再执行本文件。

ALTER TABLE hq_work
    DROP KEY `idx_public_level`,
    DROP COLUMN `public_level`;

DROP TABLE IF EXISTS hq_license_grant;
DROP TABLE IF EXISTS hq_license_text;
