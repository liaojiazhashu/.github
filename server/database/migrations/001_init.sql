-- ============================================================================
--  银河图书馆动态系统 · 初始化结构
--  目标版本：MySQL 5.7+（生产 8.0 / 8.4 LTS），向下兼容 5.6.51
--  兼容性已实测：同一份脚本在 5.6.51 与 8.4.13 上各建出 39 张表，
--                329 个列定义（去掉 8.0.19+ 移除的整数显示宽度后）逐字节一致
--  约定：
--    · 全部 InnoDB / utf8mb4 / utf8mb4_unicode_ci / ROW_FORMAT=DYNAMIC
--    · 不使用 JSON 列、生成列、窗口函数、CTE、CHECK 约束
--    · 被索引的 VARCHAR 一律 ≤ 191 字符（5.6 索引前缀双保险）
--    · 不建外键：引用完整性由应用层事务保证 + 每日一致性巡检（见 §5.7）
--    · 时间由应用写入，不用 ON UPDATE CURRENT_TIMESTAMP，避免时区歧义
--  执行：mysql -h127.0.0.1 -P3307 -uroot -p hqlib < 001_init.sql
--  撤回：mysql -h127.0.0.1 -P3307 -uroot -p hqlib < 001_init_rollback.sql
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
-- 与生产 MySQL 5.7 / 8.0 / 8.4 的默认严格档一致，避免「本机宽松、生产严格」的建表差异
SET SQL_MODE = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- ---------------------------------------------------------------------------
-- 1. 内容域
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS hq_issue (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  no          SMALLINT UNSIGNED NOT NULL COMMENT '期次号',
  label       VARCHAR(32)  NOT NULL COMMENT '展示名，如「第 25 期」',
  starts_at   DATETIME     NOT NULL COMMENT '投稿开始',
  ends_at     DATETIME     NOT NULL COMMENT '投稿截止（早 7 点）',
  status      ENUM('planned','active','closed') NOT NULL DEFAULT 'planned',
  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_no (no),
  KEY idx_status (status, starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='期次';

CREATE TABLE IF NOT EXISTS hq_author (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code           CHAR(26)     NOT NULL COMMENT 'ULID，对外标识',
  name           VARCHAR(64)  NOT NULL COMMENT '笔名',
  name_norm      VARCHAR(64)  NOT NULL COMMENT '归一化笔名（去空格/全半角），用于去重',
  contact_cipher VARBINARY(512) NULL COMMENT '手机/邮箱/QQ，AES-256-GCM',
  contact_hint   VARCHAR(64)  NULL COMMENT '掩码提示，如 138****0000',
  bio            VARCHAR(500) NULL,
  created_at     DATETIME     NOT NULL,
  updated_at     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_code (code),
  KEY idx_name_norm (name_norm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='作者';

CREATE TABLE IF NOT EXISTS hq_genre (
  id         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(32) NOT NULL,
  name       VARCHAR(32) NOT NULL,
  sort       SMALLINT    NOT NULL DEFAULT 0,
  created_at DATETIME    NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='体裁';

CREATE TABLE IF NOT EXISTS hq_tag (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(32) NOT NULL,
  slug       VARCHAR(48) NOT NULL,
  kind       ENUM('system','custom') NOT NULL DEFAULT 'system',
  created_at DATETIME    NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_name (name),
  UNIQUE KEY uk_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='题材标签';

CREATE TABLE IF NOT EXISTS hq_work (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                CHAR(26)     NOT NULL COMMENT 'ULID，对外 URL 用',
  issue_id            SMALLINT UNSIGNED NOT NULL,
  author_id           INT UNSIGNED NULL,
  genre_id            SMALLINT UNSIGNED NULL,
  title               VARCHAR(200) NOT NULL,
  subtitle            VARCHAR(200) NULL,
  word_count          INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '中文字符数，应用维护',
  summary             VARCHAR(500) NULL COMMENT '一句话梗概',
  status              ENUM('draft','imported','analyzing','analyzed','published','archived')
                        NOT NULL DEFAULT 'imported',
  current_analysis_id BIGINT UNSIGNED NULL COMMENT '当前对外展示的分析版本',
  source              ENUM('manual','import','text') NOT NULL DEFAULT 'manual',
  published_at        DATETIME NULL,
  deleted_at          DATETIME NULL,
  created_by          INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL,
  updated_at          DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_code (code),
  KEY idx_issue_status (issue_id, status),
  KEY idx_author (author_id),
  KEY idx_genre (genre_id),
  KEY idx_published (published_at, id),
  KEY idx_title (title(64)),
  KEY idx_status_deleted (status, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='作品';

CREATE TABLE IF NOT EXISTS hq_work_tag (
  work_id    INT UNSIGNED NOT NULL,
  tag_id     INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (work_id, tag_id),
  KEY idx_tag (tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='作品-标签';

CREATE TABLE IF NOT EXISTS hq_work_file (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  work_id     INT UNSIGNED NOT NULL,
  storage_key VARCHAR(191) NOT NULL COMMENT '相对 storage/uploads 的路径',
  orig_name   VARCHAR(191) NOT NULL COMMENT '原始文件名（仅展示，不参与路径拼接）',
  mime        VARCHAR(100) NOT NULL,
  size_bytes  INT UNSIGNED NOT NULL,
  sha256      CHAR(64)     NOT NULL,
  encoding    VARCHAR(16)  NOT NULL DEFAULT 'UTF-8',
  char_count  INT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_work (work_id, id),
  KEY idx_sha (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='原文文件';

CREATE TABLE IF NOT EXISTS hq_work_version (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  work_id     INT UNSIGNED NOT NULL,
  file_id     BIGINT UNSIGNED NOT NULL,
  version_no  SMALLINT UNSIGNED NOT NULL,
  sha256      CHAR(64) NOT NULL,
  char_count  INT UNSIGNED NOT NULL DEFAULT 0,
  note        VARCHAR(200) NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_work_ver (work_id, version_no),
  KEY idx_sha (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='原文版本';

-- ---------------------------------------------------------------------------
-- 2. 分析域（四件产出）
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS hq_analysis (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code            CHAR(26) NOT NULL COMMENT 'ULID',
  work_id         INT UNSIGNED NOT NULL,
  work_version_id BIGINT UNSIGNED NOT NULL,
  status          ENUM('queued','running','partially_failed','succeeded','failed','published','superseded')
                    NOT NULL DEFAULT 'queued',
  model           VARCHAR(32) NOT NULL DEFAULT 'deepseek-flash',
  prompt_version  VARCHAR(16) NOT NULL DEFAULT 'v1',
  input_hash      CHAR(64) NOT NULL COMMENT 'sha256(正文+prompt版本+模型+参数)',
  source          ENUM('ai','human','mixed') NOT NULL DEFAULT 'ai',
  is_current      TINYINT NOT NULL DEFAULT 0 COMMENT '仅用于查询加速，事实以 hq_work.current_analysis_id 为准',
  token_in        INT UNSIGNED NOT NULL DEFAULT 0,
  token_out       INT UNSIGNED NOT NULL DEFAULT 0,
  cost_micro      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '百万分之一元',
  error_code      VARCHAR(48) NULL,
  error_msg       VARCHAR(500) NULL,
  started_at      DATETIME NULL,
  finished_at     DATETIME NULL,
  created_by      INT UNSIGNED NULL,
  published_at    DATETIME NULL,
  published_by    INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL,
  updated_at      DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_code (code),
  KEY idx_work_time (work_id, created_at),
  KEY idx_work_current (work_id, is_current),
  KEY idx_status (status),
  KEY idx_hash (input_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='一次完整分析';

CREATE TABLE IF NOT EXISTS hq_analysis_stage (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  analysis_id    BIGINT UNSIGNED NOT NULL,
  stage_code     VARCHAR(24) NOT NULL COMMENT 'S1_INDEX / S2_PLOT / S3_FORESHADOW / S4_ADVICE / S5_REVISION / S6_ASSEMBLE',
  status         ENUM('pending','running','succeeded','failed','stale') NOT NULL DEFAULT 'pending',
  attempt        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  model          VARCHAR(32) NULL,
  prompt_version VARCHAR(16) NULL,
  input_tokens   INT UNSIGNED NOT NULL DEFAULT 0,
  output_tokens  INT UNSIGNED NOT NULL DEFAULT 0,
  cached_tokens  INT UNSIGNED NOT NULL DEFAULT 0,
  latency_ms     INT UNSIGNED NOT NULL DEFAULT 0,
  http_code      SMALLINT UNSIGNED NULL,
  error_code     VARCHAR(48) NULL,
  error_msg      VARCHAR(500) NULL,
  raw_path       VARCHAR(191) NULL COMMENT 'storage/artifacts 下的相对路径',
  started_at     DATETIME NULL,
  finished_at    DATETIME NULL,
  created_at     DATETIME NOT NULL,
  updated_at     DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_analysis_stage (analysis_id, stage_code),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='阶段执行记录';

CREATE TABLE IF NOT EXISTS hq_index_card (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  analysis_id BIGINT UNSIGNED NOT NULL,
  one_liner   VARCHAR(300) NOT NULL COMMENT '一句话定位',
  theme_note  VARCHAR(500) NULL COMMENT '母题说明',
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_analysis (analysis_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='索引卡';

CREATE TABLE IF NOT EXISTS hq_index_term (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  canonical   VARCHAR(64) NOT NULL COMMENT '规范词，唯一',
  category    ENUM('theme','tech','society','image','tone') NOT NULL,
  definition  VARCHAR(500) NULL,
  weight_base DECIMAL(6,3) NOT NULL DEFAULT 1.000 COMMENT '全局稀有度权重',
  status      ENUM('active','merged','deprecated') NOT NULL DEFAULT 'active',
  merged_into INT UNSIGNED NULL COMMENT '归并目标（status=merged 时有效）',
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_canonical (canonical),
  KEY idx_category (category, status),
  KEY idx_merged (merged_into)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='索引词条';

CREATE TABLE IF NOT EXISTS hq_index_term_alias (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  term_id    INT UNSIGNED NOT NULL,
  alias      VARCHAR(64) NOT NULL,
  source     ENUM('ai','human') NOT NULL DEFAULT 'ai',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_alias (alias),
  KEY idx_term (term_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='索引词别名';

CREATE TABLE IF NOT EXISTS hq_work_term (
  work_id     INT UNSIGNED NOT NULL,
  term_id     INT UNSIGNED NOT NULL,
  role        ENUM('main','sub') NOT NULL DEFAULT 'sub',
  weight      TINYINT UNSIGNED NOT NULL DEFAULT 50 COMMENT '1-100',
  evidence    VARCHAR(200) NULL COMMENT '原文支撑短语',
  analysis_id BIGINT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (work_id, term_id),
  KEY idx_term_weight (term_id, weight),
  KEY idx_analysis (analysis_id),
  KEY idx_work_role (work_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='作品×词条（倒排）';

CREATE TABLE IF NOT EXISTS hq_plot_summary (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  analysis_id BIGINT UNSIGNED NOT NULL,
  who         VARCHAR(300) NOT NULL COMMENT '谁',
  want        VARCHAR(300) NOT NULL COMMENT '要什么',
  cost        VARCHAR(300) NOT NULL COMMENT '付出什么',
  logline     VARCHAR(300) NULL COMMENT '合成的一句话',
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_analysis (analysis_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='剧情三句话';

CREATE TABLE IF NOT EXISTS hq_plot_beat (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  analysis_id  BIGINT UNSIGNED NOT NULL,
  seq          TINYINT UNSIGNED NOT NULL,
  phase        ENUM('start','turn','crisis','end') NOT NULL,
  title        VARCHAR(120) NOT NULL,
  detail       TEXT NULL,
  quote        VARCHAR(300) NULL,
  position_pct TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100 相对位置',
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_analysis_seq (analysis_id, seq),
  KEY idx_phase (analysis_id, phase)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='剧情节点轴';

CREATE TABLE IF NOT EXISTS hq_foreshadow (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  analysis_id    BIGINT UNSIGNED NOT NULL,
  content        VARCHAR(300) NOT NULL,
  setup_beat_id  BIGINT UNSIGNED NULL COMMENT '埋下节点',
  payoff_beat_id BIGINT UNSIGNED NULL COMMENT '回收节点，null 表示未回收',
  state          ENUM('planted','paid','open') NOT NULL DEFAULT 'open',
  created_at     DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_analysis_state (analysis_id, state),
  KEY idx_setup (setup_beat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='伏笔台账';

CREATE TABLE IF NOT EXISTS hq_suggestion (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  analysis_id BIGINT UNSIGNED NOT NULL,
  layer       ENUM('structure','execution','detail') NOT NULL,
  seq         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  title       VARCHAR(160) NOT NULL,
  problem     TEXT NOT NULL,
  impact      VARCHAR(300) NULL COMMENT '改动的代价/牵动范围',
  priority    TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT '1-5 排序用，非评分',
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_analysis_layer (analysis_id, layer, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='建议（三层）';

CREATE TABLE IF NOT EXISTS hq_suggestion_path (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  suggestion_id BIGINT UNSIGNED NOT NULL,
  seq           TINYINT UNSIGNED NOT NULL,
  style         ENUM('conservative','radical','neutral') NOT NULL DEFAULT 'neutral',
  content       VARCHAR(500) NOT NULL,
  created_at    DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_suggestion (suggestion_id, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='建议的具体路径';

CREATE TABLE IF NOT EXISTS hq_revision (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  analysis_id BIGINT UNSIGNED NOT NULL,
  summary     VARCHAR(500) NULL,
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_analysis (analysis_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='修改方向';

CREATE TABLE IF NOT EXISTS hq_revision_item (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  revision_id    BIGINT UNSIGNED NOT NULL,
  rank_no        TINYINT UNSIGNED NOT NULL COMMENT '影响面从大到小',
  target         VARCHAR(200) NOT NULL COMMENT '改哪里',
  action         VARCHAR(300) NOT NULL COMMENT '怎么改',
  conservative   VARCHAR(500) NOT NULL COMMENT '保守走法',
  radical        VARCHAR(500) NOT NULL COMMENT '激进走法',
  impact_scope   VARCHAR(300) NOT NULL COMMENT '牵动范围',
  decision_note  VARCHAR(300) NULL COMMENT '决定权说明',
  created_at     DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_revision_rank (revision_id, rank_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='修改清单条目';

CREATE TABLE IF NOT EXISTS hq_analysis_revision (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  analysis_id BIGINT UNSIGNED NOT NULL,
  version_no  SMALLINT UNSIGNED NOT NULL,
  snapshot    MEDIUMTEXT NOT NULL COMMENT '四件产出整份 JSON 快照',
  editor_id   INT UNSIGNED NOT NULL,
  note        VARCHAR(200) NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_analysis_ver (analysis_id, version_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='人工编辑快照';

-- ---------------------------------------------------------------------------
-- 3. AI 调用与提示词
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS hq_ai_call (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  analysis_id       BIGINT UNSIGNED NULL,
  stage_code        VARCHAR(24) NOT NULL,
  model             VARCHAR(32) NOT NULL,
  prompt_version    VARCHAR(16) NOT NULL,
  request_sha       CHAR(64) NOT NULL,
  request_id        VARCHAR(64) NULL COMMENT 'DeepSeek 返回的 id',
  http_code         SMALLINT UNSIGNED NULL,
  status            ENUM('ok','error') NOT NULL,
  prompt_tokens     INT UNSIGNED NOT NULL DEFAULT 0,
  completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  cached_tokens     INT UNSIGNED NOT NULL DEFAULT 0,
  latency_ms        INT UNSIGNED NOT NULL DEFAULT 0,
  cost_micro        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  error_code        VARCHAR(48) NULL,
  created_at        DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_analysis (analysis_id, stage_code),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='模型调用计量';

CREATE TABLE IF NOT EXISTS hq_prompt_template (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(32) NOT NULL COMMENT 'S1_INDEX 等',
  version       VARCHAR(16) NOT NULL COMMENT 'v1',
  system_prompt MEDIUMTEXT NOT NULL,
  user_template MEDIUMTEXT NOT NULL,
  schema_json   MEDIUMTEXT NULL,
  params_json   VARCHAR(500) NULL COMMENT 'temperature/max_tokens 等',
  status        ENUM('draft','active','retired') NOT NULL DEFAULT 'draft',
  created_at    DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_code_ver (code, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='提示词版本库';

-- ---------------------------------------------------------------------------
-- 4. 队列
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS hq_job (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type         VARCHAR(32) NOT NULL COMMENT 'analysis.run / analysis.stage / import.csv / search.reindex',
  payload_json MEDIUMTEXT NOT NULL,
  status       ENUM('queued','running','succeeded','failed','canceled') NOT NULL DEFAULT 'queued',
  priority     TINYINT UNSIGNED NOT NULL DEFAULT 5,
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
  available_at DATETIME NOT NULL,
  locked_by    VARCHAR(64) NULL,
  locked_at    DATETIME NULL,
  last_error   VARCHAR(500) NULL,
  result_json  MEDIUMTEXT NULL,
  created_at   DATETIME NOT NULL,
  updated_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_pick (status, available_at, priority, id),
  KEY idx_type_status (type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='异步任务';

CREATE TABLE IF NOT EXISTS hq_job_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id       BIGINT UNSIGNED NOT NULL,
  level        VARCHAR(8) NOT NULL DEFAULT 'info',
  message      VARCHAR(500) NOT NULL,
  context_json TEXT NULL,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_job (job_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='任务日志';

-- ---------------------------------------------------------------------------
-- 5. 用户与权限
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS hq_role (
  id         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(24) NOT NULL COMMENT 'admin/editor/reviewer/viewer',
  name       VARCHAR(32) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='角色';

CREATE TABLE IF NOT EXISTS hq_permission (
  id         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(32) NOT NULL COMMENT 'work.read 等',
  name       VARCHAR(48) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='权限码';

CREATE TABLE IF NOT EXISTS hq_role_permission (
  role_id       SMALLINT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='角色-权限';

CREATE TABLE IF NOT EXISTS hq_user (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username          VARCHAR(64) NOT NULL,
  email             VARCHAR(191) NULL,
  password_hash     VARCHAR(255) NOT NULL COMMENT 'Argon2id',
  role_id           SMALLINT UNSIGNED NOT NULL,
  status            ENUM('active','disabled','locked') NOT NULL DEFAULT 'active',
  totp_secret_cipher VARBINARY(255) NULL,
  totp_enabled      TINYINT NOT NULL DEFAULT 0,
  failed_attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until      DATETIME NULL,
  last_login_at     DATETIME NULL,
  last_login_ip_hash CHAR(64) NULL,
  created_at        DATETIME NOT NULL,
  updated_at        DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_username (username),
  KEY idx_role (role_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='后台账号';

CREATE TABLE IF NOT EXISTS hq_session (
  id           VARCHAR(64) NOT NULL COMMENT '会话 ID 的 sha256',
  user_id      INT UNSIGNED NOT NULL,
  token_hash   CHAR(64) NULL COMMENT '记住我 token 的哈希',
  ip_hash      CHAR(64) NULL,
  ua_hash      CHAR(64) NULL,
  expires_at   DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_user (user_id),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='服务端会话';

-- ---------------------------------------------------------------------------
-- 6. 治理：审计 / 导入 / 检索 / 限流 / 配置 / 媒体 / 迁移
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS hq_audit_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_id     INT UNSIGNED NULL,
  action       VARCHAR(48) NOT NULL COMMENT 'work.update / analysis.publish ...',
  target_type  VARCHAR(32) NOT NULL,
  target_id    VARCHAR(64) NULL,
  before_json  MEDIUMTEXT NULL,
  after_json   MEDIUMTEXT NULL,
  ip_hash      CHAR(64) NULL,
  ua_hash      CHAR(64) NULL,
  trace_id     CHAR(32) NULL,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_actor_time (actor_id, created_at),
  KEY idx_target (target_type, target_id),
  KEY idx_action_time (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='审计日志（只追加）';

CREATE TABLE IF NOT EXISTS hq_import_batch (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        CHAR(26) NOT NULL,
  source_type ENUM('csv','files','paste') NOT NULL,
  filename    VARCHAR(191) NULL,
  total       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ok          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  failed      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status      ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  created_by  INT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL,
  finished_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_code (code),
  KEY idx_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='批量导入批次';

CREATE TABLE IF NOT EXISTS hq_import_row (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id   BIGINT UNSIGNED NOT NULL,
  row_no     SMALLINT UNSIGNED NOT NULL,
  raw_json   TEXT NULL,
  status     ENUM('ok','error','skipped') NOT NULL DEFAULT 'error',
  work_id    INT UNSIGNED NULL,
  error      VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_batch (batch_id, row_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='导入行结果';

CREATE TABLE IF NOT EXISTS hq_search_keyword (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  work_id    INT UNSIGNED NOT NULL,
  keyword    VARCHAR(64) NOT NULL,
  weight     SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_work_kw (work_id, keyword),
  KEY idx_kw (keyword, weight)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='检索关键词（2-gram + 索引词）';

CREATE TABLE IF NOT EXISTS hq_rate_limit (
  bucket_key   VARCHAR(128) NOT NULL COMMENT 'route:ip:window',
  hits         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL,
  expires_at   DATETIME NOT NULL,
  PRIMARY KEY (bucket_key),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='限流计数';

CREATE TABLE IF NOT EXISTS hq_setting (
  k          VARCHAR(64) NOT NULL,
  v          VARCHAR(500) NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='系统配置（不含密钥）';

CREATE TABLE IF NOT EXISTS hq_media (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  storage_key VARCHAR(191) NOT NULL,
  mime        VARCHAR(80) NOT NULL,
  size_bytes  INT UNSIGNED NOT NULL,
  sha256      CHAR(64) NOT NULL,
  width       SMALLINT UNSIGNED NULL,
  height      SMALLINT UNSIGNED NULL,
  owner_type  VARCHAR(24) NULL,
  owner_id    BIGINT UNSIGNED NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_owner (owner_type, owner_id),
  KEY idx_sha (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='图片资源';

CREATE TABLE IF NOT EXISTS hq_migration (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  filename    VARCHAR(120) NOT NULL,
  checksum    CHAR(64) NOT NULL,
  applied_at  DATETIME NOT NULL,
  duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uk_filename (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='迁移记录';

SET FOREIGN_KEY_CHECKS = 1;
