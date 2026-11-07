-- 007：给「列表口径」补索引 —— 1300 篇规模演练（bin/bench-scale.php）量出来的那条。
--
-- 演练把作品补到 1300 篇、关键词关联补到 12854 行之后，EXPLAIN 显示前台作品列表
-- 与后台作品列表都对 hq_work 做 type=ALL（扫 1300 行）并附带 Using temporary; Using filesort：
--
--   前台·作品列表 48/页   w  ALL  -  1300  Using where; Using temporary; Using filesort
--   后台·作品列表 50/页   w  ALL  -  1300  Using where; Using temporary; Using filesort
--
-- 原因是现有的 idx_published 建在 (published_at, id) 上，而前台默认排序是 w.id DESC，
-- 排序键对不上；过滤位 visibility / deleted_at 又不在任何索引里。
-- 于是优化器只剩「全表扫 + 排序」一条路，而且因为要排序，它必须把 1300 行全部
-- 关联一遍才能取前 49 条 —— LIMIT 的提前退出完全失效。
--
-- ⚠️ 列序是这次的关键，写反了就白加：
--   第一版写成 (visibility, deleted_at, id)，EXPLAIN 确实从 ALL 变成 range，
--   但 **filesort 还在** —— 因为 `deleted_at IS NULL` 是范围条件，一旦它排在 id 前面，
--   索引就无法再按 id 提供顺序。耗时只从 55ms 降到 50ms，等于没解决问题。
--   把 deleted_at 挪到最后一列，顺序由 (visibility, id) 提供，deleted_at 交给
--   索引条件下推过滤（本项目里绝大多数行 deleted_at 都是 NULL，过滤几乎不花钱），
--   才真正拿掉 filesort、让 LIMIT 提前退出。
--
-- 说明：MySQL 5.6 的优化器在 1300 行这种小表上仍可能选择全表扫，这是**正确**的代价判断，
-- 不是索引失效。判断索引有没有价值，看的是「强制走索引快多少」与「数据量上去之后会不会切」，
-- 不是「现在有没有被选上」。

-- 前台：WHERE visibility='visible' AND deleted_at IS NULL ORDER BY id DESC
ALTER TABLE hq_work ADD INDEX idx_feed_visible (visibility, id, deleted_at);

-- 后台：同一套过滤，默认按创建时间倒序（WorkRepository::SORTABLE 的 created）
ALTER TABLE hq_work ADD INDEX idx_admin_visible (visibility, created_at, deleted_at);
