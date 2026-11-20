-- =============================================================================
-- 015_tier.sql —— 三档拆解深度（T1 速览 / T2 标准 / T3 深度）
-- =============================================================================
-- 为什么需要这一列：
--   方案文档 v2.0 §2.1 定义了三档深度，但 v4 实现是固定六阶段、无档位。
--   T1 速览（权利不明或只入库）：只要 S1 索引词 + S7 口味卡，够做「读/不读」决定；
--   T2 标准（默认）：+ S2 剧情 + S3 伏笔，读者还想知道「讲了什么」；
--   T3 深度（已授权且≥中篇）：+ S4 建议 + S5 修改方向，作者手艺视图 + 引文配额。
--   tier 记录「这次分析实际按哪档拆的」，由 Pipeline::resolveTier 按
--   hq_work.public_level（权利）+ hq_work.word_count（中篇阈值 20000）判定。
--   不放 hq_work 而放 hq_analysis：同一篇作品换授权后重拆，tier 会变，
--   而历史分析要保留当时用了哪一档，不能跟着最新的 public_level 漂移。
-- =============================================================================

ALTER TABLE hq_analysis
  ADD COLUMN tier ENUM('t1','t2','t3') NOT NULL DEFAULT 't2'
  COMMENT '拆解深度档位：t1速览/t2标准/t3深度' AFTER prompt_version;

-- 存量回填：凡有 S4_ADVICE 成功阶段记录的，说明当时跑满了六阶段（深度拆解），
-- 如实标 t3；否则保持默认 t2（跑过剧情+伏笔的标准拆解）。
-- S4_ADVICE 是 t3 独有的阶段（t1/t2 都不跑），用它判断最准。
UPDATE hq_analysis a
   SET a.tier = 't3'
 WHERE EXISTS (
   SELECT 1 FROM hq_analysis_stage s
    WHERE s.analysis_id = a.id
      AND s.stage_code = 'S4_ADVICE'
      AND s.status = 'succeeded'
 );

-- rollback（数据库回滚时执行下面这条）：
-- ALTER TABLE hq_analysis DROP COLUMN tier;
