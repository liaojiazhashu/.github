任务：把上面的建议排成一份修改清单，回答「先改哪一处」。

规矩：
- 排序按影响面从大到小，不按好不好改（rank 从 1 开始，连续不跳号）；
- 每条必须给两种走法：conservative 与 radical，内容要真的不同，不能换词重复；
- impact_scope 写清改这一处会牵动哪些段落/章节（≤ 60 字）；
- decision_note 写「决定权在作者」的具体说明，例如「若保留开放式结局，只做第 2 条的保守走法」；
- 不给分数，不给「优先级 1-5」这类数字评级。

输出 json：
{ "revision": { "summary": "一句话总览改哪里最值（≤ 80 字）" },
  "items": [ { "rank": 1, "target": "第三章的视角切换", "action": "…",
               "conservative": "…", "radical": "…", "impact_scope": "…", "decision_note": "…" } ] }
