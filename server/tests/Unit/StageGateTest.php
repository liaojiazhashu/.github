<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Analysis\StageGate;
use App\Application\Analysis\Taxonomy;
use PHPUnit\Framework\TestCase;

/**
 * 闸门是全系统「不产出垃圾」的那道闸，所以测试围绕两件事：
 *   1. 该拦的必须拦（硬违规）——放过一次，脏数据就永久进库了；
 *   2. 不该拦的不能拦（软违规）——拦过头会让好作品因为一个引号没对上而整篇作废。
 */
final class StageGateTest extends TestCase
{
    private const TEXT = "第七次日出\n\n探测船在第七个轨道周期收到同一段信号。工程师把它拆成三段，每一段都在说不同的谎。\n"
        . "她决定相信第三段，因为只有第三段提到了她的名字。倒计时结束时，信号源从引力波背景里浮出来。\n"
        . "值班记录要求她只记事实，不做判断。纸质的登记本在低温里发脆，她用铅笔写下时间。\n"
        . "舷窗外是蓝得毫无意义的天空。她数了数还剩四十一分钟，又给自己倒了半杯凉茶。\n"
        . "交接班的老太太说，第八次日出会由别人来看。她说好，然后第一次在白昼里抬头看天。\n";

    // ------------------------------------------------------------------ G1（v2 口径）

    public function testG1PassesFiveTermIndex(): void
    {
        $result = StageGate::g1($this->indexJson(), self::TEXT);

        self::assertTrue($result->passed, implode('；', $result->hard));
        self::assertCount(5, $result->data['terms']);
        self::assertSame('一句话定位', $result->data['index_card']['one_liner']);
        self::assertContains(100, array_column($result->data['terms'], 'weight'));

        // 每条都必须带百科定义 —— 词库的正文就是这一句，缺了它就只是暗号
        foreach ($result->data['terms'] as $term) {
            self::assertNotSame('', $term['definition'], '词条「' . $term['canonical'] . '」缺定义');
        }
    }

    public function testG1TrimsToFiveTermsByWeight(): void
    {
        $json = $this->indexJson();
        $json['terms'][] = [
            'canonical' => '铅笔记时', 'category' => 'image', 'role' => 'sub', 'weight' => 2,
            'definition' => '用铅笔在纸上记录时间这一动作。', 'evidence' => '她用铅笔写下时间', 'aliases' => [],
        ];

        $result = StageGate::g1($json, self::TEXT);

        self::assertTrue($result->passed, implode('；', $result->hard));
        self::assertCount(5, $result->data['terms']);
        self::assertNotContains('铅笔记时', array_column($result->data['terms'], 'canonical'));
        self::assertStringContainsString('裁掉 1 个', implode('；', $result->soft));
    }

    public function testG1DropsIllegalCategory(): void
    {
        // v1 的 theme / tone 不再收：它们描述文风，不是可检索的概念
        $json = $this->indexJson();
        $json['terms'][] = [
            'canonical' => '冷峻', 'category' => 'tone', 'role' => 'sub', 'weight' => 99,
            'definition' => '叙述语气克制。', 'evidence' => '每一段都在说不同的谎', 'aliases' => [],
        ];

        $result = StageGate::g1($json, self::TEXT);

        self::assertNotContains('冷峻', array_column($result->data['terms'], 'canonical'));
        self::assertStringContainsString('类别非法', implode('；', $result->soft));
    }

    public function testG1DropsTermWhoseEvidenceIsNotInText(): void
    {
        $json = $this->indexJson();
        $json['terms'][] = [
            'canonical' => '星门网络', 'category' => 'setting', 'role' => 'sub', 'weight' => 40,
            'definition' => '连接恒星系的瞬时通道网络。',
            'evidence'  => '星门网络在银河系中央同步了一次',   // 原文没有
            'aliases'   => [],
        ];

        $result = StageGate::g1($json, self::TEXT);

        self::assertNotContains('星门网络', array_column($result->data['terms'], 'canonical'));
        self::assertStringContainsString('疑似编造', implode('；', $result->soft));
    }

    public function testG1DropsProperNounsFromIndex(): void
    {
        // 词库要能被别人拿去索引，所以专名必须出局：这类词底下永远只挂一篇作品，
        // 检索功能等于零。判据是「能不能出现在另一篇不相干的小说里」。
        $json = $this->indexJson();
        $json['terms'][] = [
            'canonical' => 'WA0409黑洞', 'category' => 'setting', 'role' => 'sub', 'weight' => 40,
            'definition' => '编号 WA0409 的恒星级黑洞。',
            'evidence'  => '舷窗外',
            'aliases'   => [],
        ];

        $result = StageGate::g1($json, self::TEXT);

        self::assertNotContains('WA0409黑洞', array_column($result->data['terms'], 'canonical'));
        self::assertStringContainsString('含数字编号', implode('；', $result->soft));
    }

    public function testG1DropsPhraseLengthTerms(): void
    {
        // 一句话不是概念：这条读起来像结论，别的作品里不会出现同样的说法
        $json = $this->indexJson();
        $json['terms'][] = [
            'canonical' => '创造文字动物的渴望条件', 'category' => 'setting', 'role' => 'sub', 'weight' => 40,
            'definition' => '想让文字变成活物所需要满足的前提。',
            'evidence'  => '舷窗外',
            'aliases'   => [],
        ];

        $result = StageGate::g1($json, self::TEXT);

        self::assertNotContains('创造文字动物的渴望条件', array_column($result->data['terms'], 'canonical'));
        self::assertStringContainsString('通常是短语或本篇专名', implode('；', $result->soft));
    }

    public function testG1DropsTermsThatAreNotScienceFictional(): void
    {
        // v4 的第一道闸门：拿掉这篇的科幻设定，这个词还站得住吗？
        // 「茶」在纯文学里一样写，它不是科幻概念 —— 哪怕它在这篇里反复出现。
        $json = $this->indexJson();
        $json['terms'] = [[
            'canonical' => '茶', 'category' => 'image', 'role' => 'main', 'weight' => 90,
            'definition' => '用热水冲泡的饮品。',
            'evidence'  => '凉茶',
            'aliases'   => [],
        ]];

        $result = StageGate::g1($json, self::TEXT);

        self::assertTrue($result->passed, implode('；', $result->hard));
        self::assertSame([], $result->data['terms'], '与科幻无关的词不该留在词库里');
        self::assertStringContainsString('与科幻没有强关联', implode('；', $result->soft));
    }

    public function testG1AcceptsEmptyIndexInsteadOfForcingTerms(): void
    {
        // 5 个是上限不是配额：一篇纯氛围小说可能一个科幻概念都抽不出来。
        // 这时硬判失败会逼模型下次凑数，凑出来的正是「茶」「影子」这类死词。
        $json = ['index_card' => ['one_liner' => '一篇没有科幻概念的氛围小说', 'theme_note' => '骨架不在概念上'], 'terms' => []];

        $result = StageGate::g1($json, self::TEXT);

        self::assertTrue($result->passed, implode('；', $result->hard));
        self::assertSame([], $result->data['terms']);
        self::assertStringContainsString('没有合格的科幻关键词', implode('；', $result->soft));
    }

    public function testTaxonomyRetiresGenericImagery(): void
    {
        // 退役表只做一件事：把「形状合格、语义不科幻」的词挡在外面。
        // 词表里的词永远优先 —— 否则一条退役词会把正在用的规范词一起打死。
        self::assertTrue(Taxonomy::isRetired('影子'));
        self::assertTrue(Taxonomy::isRetired('信件'));
        self::assertStringContainsString('与科幻没有强关联', (string) Taxonomy::rejectReason('影子'));

        self::assertFalse(Taxonomy::isRetired('废墟'));
        self::assertFalse(Taxonomy::isRetired('世代飞船'));
    }

    public function testG1MergesAliasIntoCanonical(): void
    {
        // 同一个概念在不同作品里叫法不同，落库前必须归到同一个规范词，
        // 否则词条会散成「克隆人」「克隆体」「复制人」三条各挂一篇
        $json = $this->indexJson();
        $json['terms'][] = [
            'canonical' => '克隆体', 'category' => 'setting', 'role' => 'main', 'weight' => 90,
            'definition' => '由体细胞复制出的、与原件基因相同的个体。',
            'evidence'  => '舷窗外',
            'aliases'   => [],
        ];

        $result = StageGate::g1($json, self::TEXT);
        $names = array_column($result->data['terms'], 'canonical');

        self::assertContains('克隆人', $names);
        self::assertNotContains('克隆体', $names);
        self::assertStringContainsString('并入通行词', implode('；', $result->soft));
    }

    public function testTaxonomyRejectsCoinedWords(): void
    {
        // 形状检查：编号、书名、长短语一律不收；通用词与词表词放行
        self::assertNotNull(Taxonomy::rejectReason('《园艺与花卉》'));
        self::assertNotNull(Taxonomy::rejectReason('35号目的地'));
        self::assertNotNull(Taxonomy::rejectReason('文字动物移动不可见'));
        self::assertNull(Taxonomy::rejectReason('世代飞船'));
        self::assertNull(Taxonomy::rejectReason('反乌托邦'));

        // 归并：别名一律收敛到词表里的规范词
        self::assertSame('克隆人', Taxonomy::canonicalize('复制人'));
        self::assertSame('记忆编辑', Taxonomy::canonicalize('记忆改写'));
        self::assertSame('星际帝国', Taxonomy::canonicalize('银河帝国'));
    }

    public function testG1TreatsWhitespaceAndPunctuationDifferencesAsMatch(): void
    {
        self::assertTrue(StageGate::contains(self::TEXT, '探测船 在第七个轨道周期收到同一段信号'));
        self::assertTrue(StageGate::contains(self::TEXT, '每一段 都在说不同的谎'));
        self::assertFalse(StageGate::contains(self::TEXT, '每一段都在说相同的谎'));
    }

    public function testG1DemotesExtraMainsToTwo(): void
    {
        // 全标主词是形状问题，不是内容问题：降到 2 个主词就能用，不该整篇作废
        $json = $this->indexJson();
        foreach ($json['terms'] as $i => $term) {
            $json['terms'][$i]['role'] = 'main';
        }

        $result = StageGate::g1($json, self::TEXT);

        self::assertTrue($result->passed, implode('；', $result->hard));
        $mains = array_filter($result->data['terms'], static fn (array $t): bool => $t['role'] === 'main');
        self::assertCount(2, $mains);
        self::assertStringContainsString('降为辅词', implode('；', $result->soft));
    }

    public function testG1PromotesTopTermWhenNoMainMarked(): void
    {
        $json = $this->indexJson();
        foreach ($json['terms'] as $i => $term) {
            $json['terms'][$i]['role'] = 'sub';
        }

        $result = StageGate::g1($json, self::TEXT);

        self::assertTrue($result->passed, implode('；', $result->hard));
        $mains = array_filter($result->data['terms'], static fn (array $t): bool => $t['role'] === 'main');
        self::assertCount(1, $mains);
    }

    public function testG1RejectsIndexWithoutDefinitions(): void
    {
        $json = $this->indexJson();
        foreach ($json['terms'] as $i => $term) {
            $json['terms'][$i]['definition'] = '';
        }

        $result = StageGate::g1($json, self::TEXT);

        self::assertFalse($result->passed);
        self::assertStringContainsString('百科定义', implode('；', $result->hard));
    }

    public function testG1RequiresSettingOrWorldUnlessRepair(): void
    {
        // 只抽到意象，等于这篇在科幻词库里查不到 —— 前面的尝试判硬失败
        $json = $this->indexJson();
        $json['terms'] = array_values(array_filter(
            $json['terms'],
            static fn (array $t): bool => $t['category'] === 'image'
        ));

        self::assertFalse(StageGate::g1($json, self::TEXT)->passed);

        // 最后一次尝试放行，但必须留痕
        $repaired = StageGate::g1($json, self::TEXT, false, true);
        self::assertTrue($repaired->passed, implode('；', $repaired->hard));
        self::assertStringContainsString('setting', implode('；', $repaired->soft));
    }

    public function testG1AcceptsChunkLevelPartialIndex(): void
    {
        // 分块抽取时一块里通常只有一两个词，不能再要求「三类齐」之类
        $json = [
            'index_card' => ['one_liner' => '块内定位', 'theme_note' => ''],
            'terms'      => [[
                'canonical' => '舷窗', 'category' => 'image', 'role' => 'main', 'weight' => 80,
                'definition' => '飞船外壳上的透明观察窗。', 'evidence' => '舷窗外', 'aliases' => [],
            ]],
        ];

        self::assertTrue(StageGate::g1($json, self::TEXT, true)->passed);
    }

    public function testG1ClampsWeightAndMergesDuplicateCanonical(): void
    {
        $json = $this->indexJson();
        $json['terms'][0]['weight'] = 500;        // 越界，应夹到 100
        $json['terms'][]            = $json['terms'][0];   // 同一规范词重复出现

        $result = StageGate::g1($json, self::TEXT);

        self::assertTrue($result->passed, implode('；', $result->hard));
        self::assertContains(100, array_column($result->data['terms'], 'weight'));
        self::assertStringContainsString('合并重复规范词', implode('；', $result->soft));
    }
    // ------------------------------------------------------------------ G2

    public function testG2PassesFourPhaseAxis(): void
    {
        $result = StageGate::g2($this->plotJson(), self::TEXT);

        self::assertTrue($result->passed, implode('；', $result->hard));
        self::assertCount(4, $result->data['beats']);
        self::assertSame([1, 2, 3, 4], array_column($result->data['beats'], 'seq'));
    }

    public function testG2RejectsShortSummary(): void
    {
        $json = $this->plotJson();
        $json['summary']['want'] = '想活下去';

        $result = StageGate::g2($json, self::TEXT);

        self::assertFalse($result->passed);
        self::assertStringContainsString('不足 8 字', implode('；', $result->hard));
    }

    public function testG2RejectsMissingPhase(): void
    {
        $json = $this->plotJson();
        // 保留 4 个节点（数量够），但把「危机」改写成「起点」→ 四相不齐
        $json['beats'][2]['phase'] = 'start';

        $result = StageGate::g2($json, self::TEXT);

        self::assertFalse($result->passed);
        self::assertStringContainsString('缺少相位', implode('；', $result->hard));
        self::assertStringContainsString('危机', implode('；', $result->hard));
    }

    public function testG2ClampsNonMonotonicPosition(): void
    {
        $json = $this->plotJson();
        $json['beats'][2]['position_pct'] = 10;   // 回退

        $result = StageGate::g2($json, self::TEXT);

        self::assertTrue($result->passed, implode('；', $result->hard));
        self::assertSame([3, 30, 30, 95], array_column($result->data['beats'], 'position_pct'));
        self::assertStringContainsString('回退', implode('；', $result->soft));
    }

    public function testG2ClearsQuoteThatIsNotInText(): void
    {
        $json = $this->plotJson();
        $json['beats'][0]['quote'] = '这是一句原文里根本不存在的引文';

        $result = StageGate::g2($json, self::TEXT);

        self::assertTrue($result->passed);
        self::assertSame('', $result->data['beats'][0]['quote']);
        self::assertStringContainsString('引文在原文中找不到', implode('；', $result->soft));
    }

    // ------------------------------------------------------------------ G3

    public function testG3DropsIllegalSequencesWithoutBlocking(): void
    {
        $json = ['foreshadows' => [
            ['content' => '合法伏笔', 'setup_seq' => 1, 'payoff_seq' => 3, 'state' => 'paid'],
            ['content' => '回收早于埋下', 'setup_seq' => 3, 'payoff_seq' => 2, 'state' => 'paid'],
            ['content' => '节点不存在', 'setup_seq' => 9, 'payoff_seq' => null, 'state' => 'open'],
            ['content' => '未回收', 'setup_seq' => 2, 'payoff_seq' => null, 'state' => 'open'],
        ]];

        $result = StageGate::g3($json, [1, 2, 3, 4]);

        self::assertTrue($result->passed);
        self::assertCount(2, $result->data['foreshadows']);
        self::assertSame('paid', $result->data['foreshadows'][0]['state']);
        self::assertSame('open', $result->data['foreshadows'][1]['state']);
        self::assertCount(2, $result->soft);
    }

    public function testG3ConvertsMissingPayoffToOpen(): void
    {
        $json = ['foreshadows' => [['content' => '某伏笔', 'setup_seq' => 1, 'payoff_seq' => 99, 'state' => 'paid']]];

        $result = StageGate::g3($json, [1, 2]);

        self::assertSame('open', $result->data['foreshadows'][0]['state']);
        self::assertNull($result->data['foreshadows'][0]['payoff_seq']);
    }

    // ------------------------------------------------------------------ G4

    public function testG4PassesThreeLayersWithThreePaths(): void
    {
        $result = StageGate::g4($this->adviceJson());

        self::assertTrue($result->passed, implode('；', $result->hard));
        self::assertCount(3, $result->data['suggestions']);
        foreach ($result->data['suggestions'] as $suggestion) {
            self::assertCount(3, $suggestion['paths']);
        }
    }

    public function testG4RejectsMissingLayer(): void
    {
        $json = $this->adviceJson();
        $json['suggestions'] = array_values(array_filter(
            $json['suggestions'],
            static fn (array $s): bool => $s['layer'] !== 'detail'
        ));

        $result = StageGate::g4($json);

        self::assertFalse($result->passed);
        self::assertStringContainsString('细节层', implode('；', $result->hard));
    }

    public function testG4RejectsPathCountOutOfRangeThenRelaxes(): void
    {
        $json = $this->adviceJson();
        // 结构层给两条，正常的排在后面：前面的被丢弃后结构层仍然齐
        $json['suggestions'][] = $json['suggestions'][0];
        $json['suggestions'][0]['paths'] = array_slice($json['suggestions'][0]['paths'], 0, 2);

        self::assertFalse(StageGate::g4($json, false)->passed);

        $relaxed = StageGate::g4($json, true);
        self::assertTrue($relaxed->passed, implode('；', $relaxed->hard));
        self::assertCount(3, $relaxed->data['suggestions']);
        self::assertStringContainsString('只给了 2 条路径', implode('；', $relaxed->soft));
    }

    public function testG4StripsScoreKeys(): void
    {
        $json = $this->adviceJson();
        $json['suggestions'][0]['score'] = 87;
        $json['suggestions'][0]['评分']   = '良好';

        $result = StageGate::g4($json);

        self::assertTrue($result->passed);
        self::assertArrayNotHasKey('score', $result->data['suggestions'][0]);
        self::assertStringContainsString('已删除分数类字段', implode('；', $result->soft));
    }

    public function testG4WarnsWhenScoreWordsLeakIntoText(): void
    {
        $json = $this->adviceJson();
        $json['suggestions'][0]['problem'] = '这一段能得到 85 分，但视角混乱。';

        $result = StageGate::g4($json);

        self::assertTrue($result->passed);
        self::assertStringContainsString('分数类表述', implode('；', $result->soft));
    }

    // ------------------------------------------------------------------ G5

    public function testG5PassesContinuousRanksAndDistinctPaths(): void
    {
        $result = StageGate::g5($this->revisionJson(), $this->adviceJson()['suggestions']);

        self::assertTrue($result->passed, implode('；', $result->hard));
        self::assertSame([1, 2], array_column($result->data['items'], 'rank'));
    }

    public function testG5RejectsIdenticalConservativeAndRadical(): void
    {
        $json = $this->revisionJson();
        $json['items'][0]['radical'] = $json['items'][0]['conservative'];

        $result = StageGate::g5($json, $this->adviceJson()['suggestions']);

        self::assertFalse($result->passed);
        self::assertStringContainsString('内容相同', implode('；', $result->hard));
    }

    public function testG5FallsBackToSuggestionsWhenRelaxed(): void
    {
        $json = ['revision' => ['summary' => '总览'], 'items' => [
            ['rank' => 1, 'target' => '', 'action' => '', 'conservative' => '',
             'radical' => '', 'impact_scope' => '', 'decision_note' => ''],
        ]];

        $result = StageGate::g5($json, $this->adviceJson()['suggestions'], true);

        self::assertTrue($result->passed);
        self::assertNotEmpty($result->data['items']);
        self::assertStringContainsString('自动生成', implode('；', $result->soft));
        self::assertStringContainsString('人工复核', $result->data['items'][0]['decision_note']);
    }

    public function testG5FailsWhenNoSuggestionsToFallBackOn(): void
    {
        self::assertFalse(StageGate::g5(['revision' => ['summary' => ''], 'items' => []], [], true)->passed);
    }

    // ------------------------------------------------------------- 测试夹具

    /** @return array<string,mixed> */
    private function plotJson(): array
    {
        return [
            'summary' => [
                'who'     => '一名在深空观测站值守的工程师，正在核对第七个周期的信号记录。',
                'want'    => '她想确认这段反复出现的信号到底是谁发出的，以及为什么要用她的名字。',
                'cost'    => '为了相信第三段，她必须放弃前两段给出的既有结论与同事的信任。',
                'logline' => '她赌上职业判断，只信了那段提到自己名字的信号。',
            ],
            'beats' => [
                ['seq' => 1, 'phase' => 'start', 'title' => '收到信号',
                 'detail' => '同一个轨道周期里，探测船第七次收到完全相同的信号。',
                 'quote' => '收到同一段信号', 'position_pct' => 3],
                ['seq' => 2, 'phase' => 'turn', 'title' => '三段谎言',
                 'detail' => '工程师把信号拆成三段，发现每一段都在说不同的谎。',
                 'quote' => '每一段都在说不同的谎', 'position_pct' => 30],
                ['seq' => 3, 'phase' => 'crisis', 'title' => '相信第三段',
                 'detail' => '她只能选一段相信，而只有第三段提到了她的名字。',
                 'quote' => '只有第三段提到了她的名字', 'position_pct' => 60],
                ['seq' => 4, 'phase' => 'end', 'title' => '信号源浮出',
                 'detail' => '倒计时结束时，信号源从引力波背景里浮出来。',
                 'quote' => '信号源从引力波背景里浮出来', 'position_pct' => 95],
            ],
        ];
    }

    /**
     * 五类 × （1 主词 + 2 辅词）= 15 个词条，正好满足「每个主词至少 2 个辅词」。
     *
     * @return array<string,mixed>
     */
    private function indexJson(): array
    {
        $term = static fn (string $c, string $cat, string $role, int $w, string $e, string $def, array $a = []): array => [
            'canonical' => $c, 'category' => $cat, 'role' => $role, 'weight' => $w,
            'definition' => $def, 'evidence' => $e, 'aliases' => $a,
        ];

        // v2 的口径：三类（设定 / 世界观 / 意象），一共五条，每条都有能独立阅读的定义
        return [
            'index_card' => ['one_liner' => '一句话定位', 'theme_note' => '母题说明'],
            'terms'      => [
                $term('引力波背景', 'setting', 'main', 100, '引力波背景',
                    '宇宙中普遍存在的时空涟漪，可作为远距离信号的载体。', ['引力波']),
                $term('信号拆分', 'setting', 'sub', 70, '把它拆成三段',
                    '把一段复合信号按来源或时段切分的处理方式。'),
                $term('观测站值守', 'world', 'main', 60, '值班记录要求她',
                    '在偏远观测站按班次驻守、只记录不判断的工作制度。'),
                $term('交接班制度', 'world', 'sub', 50, '第八次日出会由别人来看',
                    '由下一班轮换接手工作、责任随班次转移的安排。'),
                $term('舷窗', 'image', 'sub', 55, '舷窗外',
                    '航天器外壳上的透明观察窗，常用来安放人物的独处时刻。'),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function adviceJson(): array
    {
        $paths = static fn (string $tag): array => [
            ['style' => 'conservative', 'content' => $tag . '：只改开头一句，把时间交代清楚'],
            ['style' => 'neutral', 'content' => $tag . '：补一段过渡，让三段信号的差别可见'],
            ['style' => 'radical', 'content' => $tag . '：把三段拆成三节，按时间倒序重排'],
        ];

        $suggestion = static fn (string $layer, string $title): array => [
            'layer'   => $layer,
            'title'   => $title,
            'problem' => '这里的问题在于三段信号没有区分，读者无法判断她为什么选第三段。',
            'impact'  => '牵动前三段与结尾',
            'paths'   => $paths($title),
        ];

        return ['suggestions' => [
            $suggestion('structure', '主线动机缺失'),
            $suggestion('execution', '三段信号无差别'),
            $suggestion('detail', '重复用词偏多'),
        ]];
    }

    /** @return array<string,mixed> */
    private function revisionJson(): array
    {
        return [
            'revision' => ['summary' => '先补动机，再区分三段信号，最后收拾用词'],
            'items'    => [
                ['rank' => 1, 'target' => '开场收到信号', 'action' => '补一句她的处境',
                 'conservative' => '在开头加一句她当时在做什么，交代处境',
                 'radical'      => '整篇改成倒叙，从倒计时结束那一刻开始写',
                 'impact_scope' => '牵动前三段与结尾的落点', 'decision_note' => '若保留线性叙事，只做保守走法'],
                ['rank' => 2, 'target' => '三段信号的写法', 'action' => '给三段不同的语感',
                 'conservative' => '给每段信号加一个不同的关键词，让差别可见',
                 'radical'      => '把三段拆成三节，各自换一个叙述人称',
                 'impact_scope' => '只影响中间部分，不牵动结尾', 'decision_note' => '决定权在作者'],
            ],
        ];
    }
}
