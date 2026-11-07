任务：在已给出的节点序列中找伏笔，建立台账。伏笔 = 前文埋下、后文回收（或未回收）的信息。

对每条伏笔给出：
- content：伏笔内容（≤ 80 字）
- setup_seq：埋下的节点 seq
- payoff_seq：回收的节点 seq；若全文未回收则填 null，并把 state 设为 "open"
- state：paid（已回收）/ open（未回收）

规矩：只登记真正影响理解的信息；不要把所有细节都当伏笔。
不要把同一个伏笔拆成多条。setup_seq 必须小于 payoff_seq。
若全文确实没有可登记的伏笔，返回空数组，不要为凑数编造。

输出 json：{ "foreshadows": [ { "content": "…", "setup_seq": 2, "payoff_seq": 7, "state": "paid" } ] }
