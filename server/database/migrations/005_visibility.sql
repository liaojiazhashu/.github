-- =============================================================================
-- 005 可见性：隐藏 / 恢复（后台治理的核心开关）
-- -----------------------------------------------------------------------------
-- 为什么**不复用 status**：
--   status 回答的是「这份内容走到哪一步了」（imported→analyzed→published）；
--   visibility 回答的是「这一步的内容要不要给人看」。两件事会同时独立发生：
--
--     · 一篇已发布的作品发现引文错误，需要先藏起来修，修好再放出去。
--       如果靠 unpublish，current_analysis_id 被清空、published_at 被抹掉，
--       恢复时相当于重新发布一次 —— 发布时刻、排序位次全变了。
--     · 1300 篇批跑期间，模型在某几篇上跑偏了。要藏，但不能退回 imported，
--       否则重新入队时会再花一次钱。
--     · 隐藏和发布的责任人不同：发布是「内容可以见人了」，隐藏是「现在先别见人」。
--       审计上必须能分开追溯。
--
--   ｜ status      ｜ visibility ｜ 前台表现                          ｜
--   ｜ published   ｜ visible    ｜ 正常展示                          ｜
--   ｜ published   ｜ hidden     ｜ 作品壳在、拆解内容不展示（存目）  ｜
--   ｜ published   ｜ hidden（作品级）｜ 整篇从馆内消失               ｜
--   ｜ unpublished ｜ 任意       ｜ 本来就不展示                      ｜
--
-- 两个隐藏粒度都要，因为它们是不同的运营动作：
--   · 作品隐藏（hq_work.visibility）：这篇作品整体不该出现 —— 版权、撤稿、误导入。
--   · 拆解隐藏（hq_analysis.visibility）：作品还在，但机器拆的结论先别给人看。
--
-- 词条隐藏（hq_index_term.visibility）与 status 同样正交：
--   status 的 merged/deprecated 是「这个词本身不成立了」；
--   hidden 是「这个词还成立，但现在不适合摆出来」。
--   隐藏后词库列表、分类计数、共现、作品页关键词全部同步消失 ——
--   这件事不靠各处手工加判断，而是靠 LibraryRepository 的可见性口径统一兜住。
--
-- 撤回：005_visibility_rollback.sql
-- =============================================================================

-- ---- 作品 ----
ALTER TABLE hq_work
  ADD COLUMN visibility    ENUM('visible','hidden') NOT NULL DEFAULT 'visible'
    COMMENT '对外可见性，与 status 正交：隐藏不清 status、不清 current_analysis_id' AFTER status,
  ADD COLUMN hidden_at     DATETIME     NULL COMMENT '本次隐藏的时刻；恢复时清空，历史在 hq_audit_log' AFTER visibility,
  ADD COLUMN hidden_by     INT UNSIGNED NULL COMMENT '执行隐藏的后台账号' AFTER hidden_at,
  ADD COLUMN hidden_reason VARCHAR(200) NULL COMMENT '隐藏理由（运营自述，展示给本人复核）' AFTER hidden_by,
  ADD KEY idx_visibility (visibility, status);

-- ---- 拆解结果 ----
ALTER TABLE hq_analysis
  ADD COLUMN visibility    ENUM('visible','hidden') NOT NULL DEFAULT 'visible'
    COMMENT '拆解结果的对外可见性；隐藏后作品壳仍在，四件产出不展示、不进检索与词条命中' AFTER status,
  ADD COLUMN hidden_at     DATETIME     NULL AFTER visibility,
  ADD COLUMN hidden_by     INT UNSIGNED NULL AFTER hidden_at,
  ADD COLUMN hidden_reason VARCHAR(200) NULL AFTER hidden_by,
  ADD KEY idx_analysis_visibility (visibility, status);

-- ---- 词条 ----
ALTER TABLE hq_index_term
  ADD COLUMN visibility    ENUM('visible','hidden') NOT NULL DEFAULT 'visible'
    COMMENT '词条的对外可见性；隐藏后不出现于词库、计数、共现与作品页标签' AFTER status,
  ADD COLUMN hidden_at     DATETIME     NULL AFTER visibility,
  ADD COLUMN hidden_by     INT UNSIGNED NULL AFTER hidden_at,
  ADD COLUMN hidden_reason VARCHAR(200) NULL AFTER hidden_by,
  ADD KEY idx_term_visibility (visibility, category);

-- ---- 任务取消标记 ----
-- 「停止」有两种粒度：排队中的直接置 canceled；正在跑的那一条没法从外部砍断
-- （模型请求已经发出去了），只能置标记，由 worker 在**阶段边界**读一次后主动放弃。
-- 阶段边界是安全的：一个阶段内部的写入是原子的，跨阶段放弃不会留下半份产物。
ALTER TABLE hq_job
  ADD COLUMN cancel_requested TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1=请求在阶段边界停止（运行中的任务无法被外部硬杀）' AFTER locked_at,
  ADD KEY idx_cancel (status, cancel_requested);

-- ---- 运行时开关 ----
-- analysis.paused：全局暂停。1300 篇跑到第 400 篇发现提示词写错了，要能一键停，
-- 而不是去任务管理器里杀 worker 进程 —— 强杀会留下 running 僵尸，还要靠回收捡回来。
INSERT INTO hq_setting (k, v, updated_at) VALUES
  ('analysis.paused', '0', NOW()),
  ('analysis.paused_note', '', NOW())
ON DUPLICATE KEY UPDATE v = VALUES(v);

-- ---- 权限码 ----
-- 新增一个权限码而不是复用 analysis.publish：
--   「让内容可见」和「让内容暂时不可见」是两种授权。
--   编辑可以藏（发现问题先藏起来是止血），但发布要审校签字，这是原有的分工。
INSERT IGNORE INTO hq_permission (code, name, created_at)
VALUES ('visibility.toggle', '隐藏/恢复内容', NOW());

INSERT IGNORE INTO hq_role_permission (role_id, permission_id)
SELECT r.id, p.id
  FROM hq_role r
  JOIN hq_permission p ON p.code = 'visibility.toggle'
 WHERE r.code IN ('admin', 'editor', 'reviewer');
