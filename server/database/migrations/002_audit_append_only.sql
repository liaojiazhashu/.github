-- ============================================================================
--  002 · 审计表改为 append-only（数据库层强制）
--
--  背景：hq_audit_log 的注释一直写着「只 INSERT，绝不 UPDATE / DELETE」，
--        但应用账号拿到的是 `GRANT SELECT, INSERT, UPDATE, DELETE ON hqlib.*` ——
--        也就是说这条约束靠自觉，不靠数据库。附录 E-17 要求的是后者。
--
--  为什么用触发器而不是细化授权：MySQL 的权限是「并集」，库级已授予的 UPDATE
--  无法在表级撤回；要撤回就得把 39 张表逐条重授一遍，以后每加一张表就漏一次。
--  触发器写一次，覆盖所有客户端（应用、Adminer、手滑的 SQL）。
--
--  为什么不用 TRUNCATE 防护：TRUNCATE 需要 DROP 权限，应用账号没有。
--
--  执行：mysql --defaults-file=client-root.cnf -h127.0.0.1 -P3307 hqlib < 002_audit_append_only.sql
--  撤回：mysql ... < 002_audit_append_only_rollback.sql
-- ============================================================================

SET NAMES utf8mb4;

DROP TRIGGER IF EXISTS hq_audit_log_no_update;
DROP TRIGGER IF EXISTS hq_audit_log_no_delete;

CREATE TRIGGER hq_audit_log_no_update
BEFORE UPDATE ON hq_audit_log
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hq_audit_log 是 append-only 表：不允许 UPDATE';

CREATE TRIGGER hq_audit_log_no_delete
BEFORE DELETE ON hq_audit_log
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hq_audit_log 是 append-only 表：不允许 DELETE';
