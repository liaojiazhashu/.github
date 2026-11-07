-- 008：给检索关键词表补 term_id，让「词条被隐藏」在检索里也成立。
--
-- 问题：hq_search_keyword 是**物化**表 —— 发布/重跑时把词条名、别名、2-gram 拆成
-- 一行行纯文本关键词写进去。它能按作品级可见性过滤（join hq_work / hq_analysis），
-- 但词汇层面没有归属：后台把「世代飞船」隐藏之后，那 4 行关键词仍然躺在表里，
-- 搜「世代飞船」照旧命中两篇 —— 页面上一个入口都没有，检索却把它们交出来。
--
-- 为什么不是「隐藏时重建索引」：那是物化 + 失效的思路，只要有一条路径忘了重建
-- （手工改库、脚本直连、将来新加的发文流程），泄漏就静默发生且无人知晓。
-- 加一列之后，可见性判断回到**查询时**，和 PUBLISHED_JOIN / TERM_LIVE 同一口径：
-- 改一处，全站生效。
--
-- term_id 可空：标题二元组、作者名、建议标题这些关键词本来就不属于任何词条。

ALTER TABLE hq_search_keyword
    ADD COLUMN term_id INT UNSIGNED NULL DEFAULT NULL AFTER keyword;

ALTER TABLE hq_search_keyword
    ADD INDEX idx_term_id (term_id);

-- 回填：整词先按标准名对齐，再按别名补齐（别名归并到所属词条）
UPDATE hq_search_keyword k
  JOIN hq_index_term t ON t.canonical = k.keyword
   SET k.term_id = t.id;

UPDATE hq_search_keyword k
  JOIN hq_index_term_alias a ON a.alias = k.keyword
   SET k.term_id = a.term_id
 WHERE k.term_id IS NULL;

-- 2-gram（「克制」之于「克制的悲伤」）无法用 SQL 反推归属，回填不到。
-- 迁移之后必须跑一次 `php bin/console.php library:reindex` 让它们带上 term_id，
-- 否则隐藏词条的 gram 仍会被搜到。
