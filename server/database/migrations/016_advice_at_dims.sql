-- =============================================================================
-- 016_advice_at_dims.sql —— 建议定位「节.段」+ 八维评分折叠
-- =============================================================================
-- 为什么需要这两列：
--   方案文档 v2.0 有两项设计目的在 v4 里漏了：
--   1. §1 R3 位置写法：任何判断都要能指到位置，统一写「节.段」（如 2.8），
--      指不到必须标 [推断]。S4 建议原表只有 problem（引原文片段），没有
--      结构化的 at 定位字段，作者拿着建议要自己回原文找。
--   2. §6.3 八维评分：设定新意/世界自洽/主题深度/结构节奏/人物塑造/语言意象/
--      逻辑严密/原创性，各 0–10 等权，null 维度不计入平均，默认折叠、标题写
--      「写作者参考，读者可跳过」。S5 修改方向原表只存 summary + items，评分
--      被当成分数类字段直接删掉了（§6.3 明确说评分要保留、只是折叠）。
--   at 用 VARCHAR(20)：位置写法最长为「[推断]」或「999.999」，20 字足够。
--   dims 用 TEXT JSON：八维不按子字段检索（检索是词条的事），存 JSON 最简单；
--      全 null 时存 NULL（宁缺毋造，不存空壳 JSON）。
-- =============================================================================

ALTER TABLE hq_suggestion
  ADD COLUMN at VARCHAR(20) NULL
  COMMENT '建议指向的位置「节.段」如 1.8，指不到写 [推断]' AFTER problem;

ALTER TABLE hq_revision
  ADD COLUMN dims_json TEXT NULL
  COMMENT '八维评分 JSON {setting_novelty:0-10,...}，null 维度不计入平均' AFTER summary;

-- rollback（数据库回滚时执行下面两条）：
-- ALTER TABLE hq_suggestion DROP COLUMN at;
-- ALTER TABLE hq_revision DROP COLUMN dims_json;
