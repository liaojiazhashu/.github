-- =============================================================================
-- 011 词条百科落库
-- -----------------------------------------------------------------------------
-- 详情页四要素里的「起源与发展」「世界科幻中的它」原本只存在于
-- resources/taxonomy/term_encyclopedia.php（68 个键）。改一个词条 = 改代码 + 部署，
-- 而且改它要碰 AI 拆解的仓库目录 —— 运营补一段话不应该有这种代价。
--
-- 搬到 hq_term_encyclopedia 之后：
--   · 后台 /admin/terms/{id} 直接编辑，保存即生效；
--   · bin/console.php term:encyclopedia-import 把文件里的基线灌进来；
--   · 读取侧先查库、查不到回落文件，所以迁移漏一条也不会让老内容消失。
--
-- 与 hq_index_term 一对一（uk_term）：一个词只有一段历史，多行会让「显示哪一段」
-- 变成未定义行为。不加外键是沿用本项目惯例 —— 词条归并/清理走应用层事务，
-- 让 DDL 层面的级联去删内容反而更危险。
--
-- 撤回：011_term_encyclopedia_rollback.sql
-- =============================================================================

CREATE TABLE IF NOT EXISTS hq_term_encyclopedia (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  term_id       INT UNSIGNED NOT NULL,
  history       VARCHAR(1000) NOT NULL DEFAULT '',
  classics_json TEXT NULL COMMENT 'JSON 数组：[{title,author,year}]，至多 3 项',
  updated_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL,
  updated_at    DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_term (term_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='词条百科（起源与发展 / 世界科幻中的它）';
