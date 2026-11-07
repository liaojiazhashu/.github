-- 008 撤回：去掉 term_id，回到「关键词只有文本」的状态。
--
-- 撤回之后会退回旧行为：隐藏词条不影响检索命中。仅用于排查问题，
-- 生产环境不该长期停在这个状态。
--
-- 注意：撤回顺序必须与执行顺序相反 —— 先删索引再删列，
-- 否则 MySQL 会因为「列上有索引」拒绝删列。

ALTER TABLE hq_search_keyword DROP INDEX idx_term_id;

ALTER TABLE hq_search_keyword DROP COLUMN term_id;
