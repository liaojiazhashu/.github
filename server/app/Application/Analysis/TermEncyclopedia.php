<?php

declare(strict_types=1);

namespace App\Application\Analysis;

use App\Infrastructure\Pdo\TermEncyclopediaRepository;

/**
 * 词条百科：每个词条的起源历史与世界经典（详情页四要素之二、三）。
 *
 * ## 数据来源的优先级
 *
 *   1. 数据库 hq_term_encyclopedia —— 后台可改，改完即生效；
 *   2. 回落 resources/taxonomy/term_encyclopedia.php —— 68 条人工基线。
 *
 * 先库后文件不是过渡期的权宜，而是这次搬迁的安全阀：迁移漏掉一条、或者有人
 * 在后台把某条的历史清空，老内容仍然从文件里读得到，词条页不会突然少两节。
 * 库里「有行但内容全空」也按未命中处理，回落文件。
 *
 * ## 为什么从「静态类」改成「实例 + 构造注入」
 *
 * 原来的 TermEncyclopedia::find() 是静态的，好处是控制器不用多一个构造参数。
 * 但它现在要查库，静态方法只有三条出路：
 *   a. 在类内部 new PDO —— 口令与连接策略会散进 Application 层，绝不能做；
 *   b. 用一个静态寄存器/服务定位器给类喂连接 —— 全局可变状态，CLI 与 Web 的
 *      装配顺序不同就会踩到「还没初始化」；
 *   c. 由容器构造，构造器接收仓储。
 *
 * 选 c。项目本来就用 PHP-DI 装配控制器（TermAdminController / HiddenController
 * 都是构造注入），多一个参数只是容器的一行绑定，而依赖变成显式的、可替换的。
 * 仓储参数**可空**：单元测试要能在不连库的情况下验证文件回落逻辑，
 * bin 脚本也有只关心文件结构的场景 —— null 就是「只读文件」。
 *
 * 词条详情页固定四要素，数据来源各管一段：
 *   1. 词条释义 —— hq_index_term.definition；
 *   2. 起源与发展 —— 本类 history；
 *   3. 世界科幻中的它 —— 本类 classics（至多 3 部）；
 *   4. 馆内作品 —— hq_work_term 实时查询。
 */
final class TermEncyclopedia
{
    private const PATH = __DIR__ . '/../../../resources/taxonomy/term_encyclopedia.php';

    /** @var array<string, array{history:string, classics:list<array{title:string,author:string,year:int}>}>|null 归一化键 => 条目 */
    private ?array $index = null;

    public function __construct(private readonly ?TermEncyclopediaRepository $repo = null)
    {
    }

    /**
     * 查一个词条的百科条目；查不到返回 null（页面隐藏对应两节，而不是报错）。
     *
     * $termId 为 0 或仓储未注入时直接走文件 —— 单测与只读脚本都靠这条路径。
     *
     * @return array{history:string, classics:list<array{title:string,author:string,year:int}>}|null
     */
    public function find(int $termId, string $canonical): ?array
    {
        if ($termId > 0 && $this->repo !== null) {
            $row = $this->repo->findByTermId($termId);
            if ($row !== null && ($row['history'] !== '' || $row['classics'] !== [])) {
                return $row;
            }
        }

        return $this->index()[self::key($canonical)] ?? null;
    }

    /** 文件基线条目数，自检与监控用（不含库里的新增） */
    public function size(): int
    {
        return count($this->index());
    }

    /** @return array<string, array{history:string, classics:list<array{title:string,author:string,year:int}>}> */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $raw = is_file(self::PATH) ? require self::PATH : [];
        $out = [];
        foreach ((array) $raw as $canonical => $entry) {
            if (!is_array($entry) || !isset($entry['history'], $entry['classics'])) {
                continue;
            }
            $out[self::key((string) $canonical)] = [
                'history'  => (string) $entry['history'],
                'classics' => array_values(array_map(
                    static fn (array $c): array => [
                        'title'  => (string) ($c['title'] ?? ''),
                        'author' => (string) ($c['author'] ?? ''),
                        'year'   => (int) ($c['year'] ?? 0),
                    ],
                    array_values(array_filter((array) $entry['classics'], 'is_array'))
                )),
            ];
        }

        return $this->index = $out;
    }

    /** 与 Taxonomy::key 同一规则：去空白、转小写，保证两表键能对上 */
    private static function key(string $text): string
    {
        $text = preg_replace('/\s+/u', '', $text) ?? $text;

        return mb_strtolower($text, 'UTF-8');
    }
}
