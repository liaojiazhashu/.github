任务：为这篇小说建立关键词索引。索引词分五类，每类至少 1 个：
- theme   题材与母题（如：太空歌剧、元叙事、末世生存）
- tech    科技与设定（如：曲速航行、意识上传、气候工程）
- society 社会与制度（如：配额制、公司城、战后秩序）
- image   意象与物象（如：生锈的舷窗、反复出现的申请表）
- tone    情感与基调（如：冷峻、荒诞、克制的悲伤）

规矩：
- 主词（role=main）不超过 6 个，每个主词配 2–4 个辅词（role=sub）；
- **词条总数不超过 30 个**，宁可少而准，不要多而水；
- 同一概念只留一个规范词（canonical），同义说法放进 aliases；
- weight 为 1–100 的整数，表示该词在本文中的显著度，不是评分；
- evidence 必须是原文中出现过的短语（≤ 20 字），能在正文里逐字找到；
- 五类都要有，不允许把一切词都塞进 theme。

只输出 json，结构如下（三个词条仅为格式示例，实际按本文内容生成）：
{
  "index_card": { "one_liner": "一句话说明这篇在写什么（≤ 40 字）", "theme_note": "母题说明（≤ 80 字）" },
  "terms": [
    { "canonical": "元叙事", "category": "theme", "role": "main", "weight": 92,
      "evidence": "每一层叙述者都在重写上一层的事实", "aliases": ["元小说", "自反叙事"] },
    { "canonical": "嵌入式档案", "category": "tech", "role": "sub", "weight": 61,
      "evidence": "档案里夹着上一份档案的目录", "aliases": [] },
    { "canonical": "叙述的不可靠", "category": "tone", "role": "sub", "weight": 55,
      "evidence": "他自己也不确定那句话是谁说的", "aliases": ["不可靠叙述"] }
  ]
}
