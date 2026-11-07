-- 007 撤回：只删索引，不动任何数据。
-- 删索引不影响正确性，只影响查询计划；如果删除后列表变慢，重新施加本迁移即可。

ALTER TABLE hq_work DROP INDEX idx_feed_visible;
ALTER TABLE hq_work DROP INDEX idx_admin_visible;
