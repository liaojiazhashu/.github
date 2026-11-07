-- =============================================================================
-- 003 关键词口径 v2 + 读者视角
-- -----------------------------------------------------------------------------
-- 背景：v1 的索引词是「五类各 ≥1、最多 30 个」，那是按「给文章打标签」设计的。
-- 但银河图书馆的词表服务于另一件事：**科幻百科词库** —— 用户拿着一个概念
-- （「记忆外包」「配额制」）来找作品。按标签设计出来的表，查概念时全是噪音：
--   ① theme（题材）与 tone（情绪）描述的是文风，不是可被检索的实体；
--   ② 30 个词里通常只有 4–5 个真正属于这篇的骨架，其余是陪衬，反而埋掉了重点。
-- v2 因此只剩三类，每篇最多 5 个，且每个词条必须带一条「脱离本文也读得懂」的定义。
--
-- 本迁移做三件事：
--   1) 把历史分类映射到新分类（tech/theme → setting，society → world）；
--   2) 删掉 tone 类词条（新口径里没有它的位置）；
--   3) 收窄 ENUM，并新增 hq_reader_brief（读者视角）。
--
-- 注意：已有的 15 篇作品会在迁移后按 v2 提示词重跑，词条会被替换成 ≤5 条。
-- 这里只保证「旧数据不会把 ENUM 撑坏」，不保证旧词条符合新口径。
--
-- 撤回：003_keyword_v2_rollback.sql（不恢复被删的 tone 词条 —— 那是数据，不是结构）
-- =============================================================================

-- ---- 1) 先放宽 ENUM，再加新值 ------------------------------------------------
-- 顺序不能省：ENUM 是白名单，直接 UPDATE 一个还不存在的值会被严格模式判
-- 「Data truncated for column 'category'」。所以先并集放宽，映射完再收窄。
ALTER TABLE hq_index_term
  MODIFY category ENUM('theme', 'tech', 'society', 'image', 'tone', 'setting', 'world') NOT NULL;

-- ---- 2) 历史分类映射 ---------------------------------------------------------
UPDATE hq_index_term SET category = 'setting', updated_at = NOW()
 WHERE category IN ('tech', 'theme');

UPDATE hq_index_term SET category = 'world', updated_at = NOW()
 WHERE category = 'society';

-- ---- 3) tone 类词条清理 ------------------------------------------------------
-- 先删关联再删词条：hq_work_term 有外键，反过来会失败
DELETE wt FROM hq_work_term wt
  JOIN hq_index_term t ON t.id = wt.term_id
 WHERE t.category = 'tone';

DELETE a FROM hq_index_term_alias a
  JOIN hq_index_term t ON t.id = a.term_id
 WHERE t.category = 'tone';

DELETE FROM hq_index_term WHERE category = 'tone';

-- ---- 4) ENUM 收窄 ------------------------------------------------------------
ALTER TABLE hq_index_term
  MODIFY category ENUM('setting', 'world', 'image') NOT NULL;

-- ---- 5) 读者视角 -------------------------------------------------------------
-- 与 hq_index_card 分开存：改关键词不该让读者页的「看点」跟着作废，反之亦然。
CREATE TABLE IF NOT EXISTS hq_reader_brief (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  analysis_id      BIGINT UNSIGNED NOT NULL,
  hook             VARCHAR(300) NOT NULL DEFAULT '' COMMENT '一句话钩子（具体，不剧透）',
  appeal_json      TEXT         NULL             COMMENT '三条看点 [{title, why}]',
  visual_tone      VARCHAR(300) NOT NULL DEFAULT '' COMMENT '色彩与光线',
  visual_lens      VARCHAR(300) NOT NULL DEFAULT '' COMMENT '镜头与调度',
  visual_ref       VARCHAR(400) NOT NULL DEFAULT '' COMMENT '可对标的影视/游戏坐标',
  visual_scene     VARCHAR(400) NOT NULL DEFAULT '' COMMENT '最适合做预告片的画面',
  audience_primary VARCHAR(300) NOT NULL DEFAULT '' COMMENT '主要读者',
  audience_also    VARCHAR(300) NOT NULL DEFAULT '' COMMENT '另一类读者',
  audience_avoid   VARCHAR(300) NOT NULL DEFAULT '' COMMENT '会觉得不值的读者',
  audience_gate    VARCHAR(300) NOT NULL DEFAULT '' COMMENT '阅读门槛',
  terms_note       VARCHAR(300) NOT NULL DEFAULT '' COMMENT '关键词怎么帮人找到它',
  created_at       DATETIME     NOT NULL,
  updated_at       DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_reader_analysis (analysis_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COMMENT = '读者视角（S7）';
