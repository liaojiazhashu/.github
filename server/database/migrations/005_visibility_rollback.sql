-- =============================================================================
-- 005 回滚：撤掉可见性相关列、索引、权限码与开关
-- -----------------------------------------------------------------------------
-- ⚠️ 只删结构，不动业务数据。回滚后 visibility 字段消失，等于「所有隐藏失效、
--    一切按 status 口径展示」——这正是回滚的语义，不是 bug。
-- ⚠️ 执行前先备份：`php -c php.ini bin/backup.php`。
-- =============================================================================

-- 权限码（先撤关联，再撤码本身；MySQL 5.6 的 DELETE 不支持多表 + JOIN 的某些写法，用子查询）
DELETE FROM hq_role_permission
 WHERE permission_id IN (SELECT id FROM hq_permission WHERE code = 'visibility.toggle');

DELETE FROM hq_permission WHERE code = 'visibility.toggle';

-- 运行时开关
DELETE FROM hq_setting WHERE k IN ('analysis.paused', 'analysis.paused_note');

-- 任务取消标记
ALTER TABLE hq_job
  DROP INDEX idx_cancel,
  DROP COLUMN cancel_requested;

-- 词条
ALTER TABLE hq_index_term
  DROP INDEX idx_term_visibility,
  DROP COLUMN visibility,
  DROP COLUMN hidden_at,
  DROP COLUMN hidden_by,
  DROP COLUMN hidden_reason;

-- 拆解结果
ALTER TABLE hq_analysis
  DROP INDEX idx_analysis_visibility,
  DROP COLUMN visibility,
  DROP COLUMN hidden_at,
  DROP COLUMN hidden_by,
  DROP COLUMN hidden_reason;

-- 作品
ALTER TABLE hq_work
  DROP INDEX idx_visibility,
  DROP COLUMN visibility,
  DROP COLUMN hidden_at,
  DROP COLUMN hidden_by,
  DROP COLUMN hidden_reason;
