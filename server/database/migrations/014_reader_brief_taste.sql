-- 014：读者视角补齐「口味卡三件」——五轴坐标 + 内容提示 + 编辑判断
--
-- 为什么必须补这三列：
--
--   拆解方案 v2.0（_restore-backup/04-projects/银河图书馆/universal/）给「读者视角」定的
--   唯一目的是：让一个没读过这本书的人，30 秒内做出「读 / 不读 / 再看看」的决定。
--   为此定义了「口味卡七件」：hook（钩子）、fit（适合谁）、unfit（不适合谁）、
--   axes（五轴坐标）、cost（阅读代价）、notes（内容提示）、verdict（编辑判断）。
--
--   但 v4 落库时只落了三件半：hook / appeal(≈fit) / audience(≈fit+unfit+cost) / terms_note。
--   口味卡里最关键的「这本书到底是什么样的」信息全部缺失：
--
--     axes    五轴坐标 —— 用「口味坐标」代替「评分」当主角，是方案文档 §3.1 的核心创新。
--             五个轴（科学含量 / 黑暗程度 / 节奏 / 门槛 / 内向度）各 0–100，描述
--             「这本书是什么样」，不是「有多好」。没这五轴，读者无法判断口味匹配。
--     notes   内容提示 —— 读者最需要、最容易踩雷的信息（暴力、自伤、儿童受伤害等），
--             用 level 0–3 表达强度，说类别说强度不说情节。方案文档 §3.2 原话：
--             「读者最需要也最容易踩雷，因为它是唯一不能从简介和书封判断的信息」。
--     verdict 编辑判断 —— 一句人话结论（≤60 字），替读者把前六件收拢成一句话。
--
--   这三件是「口味卡」的灵魂，缺了它们，读者页只剩「讲什么 + 适合谁」，
--   退化成作者向拆解结果的换皮展示，而不是「帮读者决定要不要读」的说明书。
--
-- 为什么 axes / notes 用 JSON、verdict 用 VARCHAR：
--   axes 是固定五键的对象，notes 是 0–N 条的同构数组，都只按 analysis_id 取整行、
--   不需要按子字段检索，用 JSON 存可以避免为「未来可能增删的轴 / 类别」反复改表。
--   verdict 是单个短文本，直接 VARCHAR 即可，与 hook / terms_note 对齐。
--
-- 为什么所有轴和 note 都允许 NULL：
--   方案文档 §3.1 原话「真判断不了就写 null（宁缺勿造）」。五轴允许个别轴为 null，
--   notes 允许整列为 null（表示「没有需要提示的内容」），verdict 允许空串（兜底）。

ALTER TABLE hq_reader_brief
    ADD COLUMN `axes_json`  TEXT         NULL
        COMMENT '口味五轴 {hardness,darkness,pace,access,inward} 各 0-100 或 null；不给 50，不确定给 null' AFTER `terms_note`,
    ADD COLUMN `notes_json` TEXT         NULL
        COMMENT '内容提示 [{category,level,note}] level 0-3；说类别说强度不说情节，可空' AFTER `axes_json`,
    ADD COLUMN `verdict`    VARCHAR(200) NOT NULL DEFAULT ''
        COMMENT '编辑判断（≤60 字，一句人话结论，替读者收拢口味卡）' AFTER `notes_json`;

-- 已有数据：这三列对旧行保持 NULL / 空串，前端用 {% if ... is not null %} 隐藏整块，
-- 不会把旧数据渲染成「全是 0」或「没有内容提示」的假象。等作品重跑 S7 后自然补齐。
