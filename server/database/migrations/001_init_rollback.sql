-- ============================================================================
--  银河图书馆动态系统 · 初始化结构「撤回」脚本
--  用途：一键回滚 001_init.sql。按依赖逆序 DROP（先子后父），带外键检查关闭。
--  ⚠ 会删除全部业务数据，只允许在测试/重建环境执行；生产执行前必须已有备份。
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS hq_migration;
DROP TABLE IF EXISTS hq_media;
DROP TABLE IF EXISTS hq_setting;
DROP TABLE IF EXISTS hq_rate_limit;
DROP TABLE IF EXISTS hq_search_keyword;
DROP TABLE IF EXISTS hq_import_row;
DROP TABLE IF EXISTS hq_import_batch;
DROP TABLE IF EXISTS hq_audit_log;
DROP TABLE IF EXISTS hq_session;
DROP TABLE IF EXISTS hq_user;
DROP TABLE IF EXISTS hq_role_permission;
DROP TABLE IF EXISTS hq_permission;
DROP TABLE IF EXISTS hq_role;
DROP TABLE IF EXISTS hq_job_log;
DROP TABLE IF EXISTS hq_job;
DROP TABLE IF EXISTS hq_prompt_template;
DROP TABLE IF EXISTS hq_ai_call;
DROP TABLE IF EXISTS hq_analysis_revision;
DROP TABLE IF EXISTS hq_revision_item;
DROP TABLE IF EXISTS hq_revision;
DROP TABLE IF EXISTS hq_suggestion_path;
DROP TABLE IF EXISTS hq_suggestion;
DROP TABLE IF EXISTS hq_foreshadow;
DROP TABLE IF EXISTS hq_plot_beat;
DROP TABLE IF EXISTS hq_plot_summary;
DROP TABLE IF EXISTS hq_work_term;
DROP TABLE IF EXISTS hq_index_term_alias;
DROP TABLE IF EXISTS hq_index_term;
DROP TABLE IF EXISTS hq_index_card;
DROP TABLE IF EXISTS hq_analysis_stage;
DROP TABLE IF EXISTS hq_analysis;
DROP TABLE IF EXISTS hq_work_version;
DROP TABLE IF EXISTS hq_work_file;
DROP TABLE IF EXISTS hq_work_tag;
DROP TABLE IF EXISTS hq_work;
DROP TABLE IF EXISTS hq_tag;
DROP TABLE IF EXISTS hq_genre;
DROP TABLE IF EXISTS hq_author;
DROP TABLE IF EXISTS hq_issue;

SET FOREIGN_KEY_CHECKS = 1;

-- 表数量：39（与 001_init.sql 一致）
