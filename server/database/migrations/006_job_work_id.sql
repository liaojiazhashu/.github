-- =============================================================================
-- 006 任务绑定作品 id
-- -----------------------------------------------------------------------------
-- 003 时代为了「这篇作品在队列里吗」用 `payload_json LIKE '%"work_id":123,%'` 判断。
-- 那是当时唯一可行的写法（MySQL 5.6 没有 JSON 函数），代价是：
--   · 无法走索引，每查一篇都要扫一遍 hq_job；
--   · 1300 篇的批量入队要逐篇做这个判断 —— 1300 次全表扫；
--   · 依赖 JSON 键序，谁改了载荷字段顺序，判断就静默失效。
--
-- 现在把它提成真实列。队列是 1300 篇批跑的中枢，值得为它建一个索引。
-- 列可空：非 analysis 类任务（将来可能有的 import / reindex）没有作品。
--
-- 撤回：006_job_work_id_rollback.sql
-- =============================================================================

ALTER TABLE hq_job
  ADD COLUMN work_id INT UNSIGNED NULL
    COMMENT '绑定的作品 id；analysis.run 必有，其它任务类型可空' AFTER type,
  ADD KEY idx_work_status (work_id, status);

-- 回填历史任务。载荷由本系统自己写，键序固定（json_encode 保序，work_id 在首位），
-- 所以取 `"work_id":` 之后到第一个逗号之间的数字即可。非 analysis 类跳过。
UPDATE hq_job
   SET work_id = CAST(
         SUBSTRING_INDEX(
           SUBSTRING_INDEX(payload_json, '"work_id":', -1),
           ',', 1
         ) AS UNSIGNED
       )
 WHERE work_id IS NULL
   AND type = 'analysis.run'
   AND payload_json LIKE '%"work_id":%';

-- 回填失败的置回 NULL（CAST 对非数字会得到 0，0 不是合法作品 id）
UPDATE hq_job SET work_id = NULL WHERE work_id = 0;
