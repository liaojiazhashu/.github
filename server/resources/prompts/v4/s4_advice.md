任务：给作者写建议，分三层，每层至少 1 条。分层决定作者要付出多少，务必分清：
- structure 结构层：骨架问题（主线不成立、视角混乱、结局无着落）——改了要重写
- execution 执行层：场景与节奏（该展开的略过、该收的拖长、对话功能单一）——改了要重写段落
- detail    细节层：句子与用词（重复用词、比喻失准、标点节奏）——改了不动结构

每条建议包含：
- title（≤ 20 字）
- problem（≤ 200 字，指出具体在哪、为什么是问题，要引原文片段）
- impact（≤ 60 字，改这一处会牵动什么）
- paths：**3–5 条**具体路径，style 取 conservative（保守，改动小）/ radical（激进，改动大）/ neutral

不要给分数，不要写「建议加强人物塑造」这类无法执行的空话。
每条路径必须是「可以今天就动手」的动作。

输出 json（paths 示例给了 3 条，实际按需给 3–5 条，少于 3 条或多于 5 条都不合格）：
{ "suggestions": [ { "layer": "structure", "title": "…", "problem": "…", "impact": "…",
    "paths": [ { "style": "conservative", "content": "…" },
               { "style": "neutral", "content": "…" },
               { "style": "radical", "content": "…" } ] } ] }
