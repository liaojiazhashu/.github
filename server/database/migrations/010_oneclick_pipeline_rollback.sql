-- 010 回滚：一键拆解批次与来源 cid
--
-- 顺序与正相反：先删依赖 batch_id 的索引与列，再删批次表，最后删 source_cid 的唯一键。
-- 批次表可以整张删掉（它只记「点过哪一次」，任务与作品本身不依赖它）；
-- 但 source_cid 删之前要清楚：**删掉之后再跑一键拆解，同一篇官网作品会被重复导入**，
-- 所以这一步只在确认要退回旧流程时执行。

ALTER TABLE hq_job
    DROP KEY idx_batch_status;

ALTER TABLE hq_job
    DROP COLUMN batch_id;

DROP TABLE IF EXISTS hq_run_batch;

ALTER TABLE hq_work
    DROP KEY uk_source_cid;

ALTER TABLE hq_work
    DROP COLUMN source_cid;
