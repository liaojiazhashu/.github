-- 002 撤回：去掉审计表的 append-only 触发器（回到「靠自觉」的状态）
DROP TRIGGER IF EXISTS hq_audit_log_no_update;
DROP TRIGGER IF EXISTS hq_audit_log_no_delete;
