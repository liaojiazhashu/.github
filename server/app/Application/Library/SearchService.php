<?php

declare(strict_types=1);

namespace App\Application\Library;

use App\Infrastructure\Pdo\LibraryRepository;
use App\Support\Text;

/**
 * 检索：三层组合（§5.6）。
 *
 *   L3 关键词表  →  对上词即得候选集并按权重排序（最常用，10ms 级）
 *   LIKE 兜底    →  关键词表对不上时才用，且必须带上限
 *   相近词建议   →  全都没对上，给三个编辑距离最近的词条，而不是一句「无结果」
 *
 * 「先 L3 再 LIKE」不是为了快，而是为了**结果可解释**：L3 的候选集来自索引词与标题二元组，
 * 排序用的是这个词在这篇里有多重；LIKE 只能按发布时间排，对上再多也是巧合。
 */
final class SearchService
{
    private const MAX_QUERY = 64;

    public function __construct(
        private readonly LibraryRepository $library,
        private readonly PublicTextGuard $guard,
    ) {
    }

    /**
     * @return array{rows:list<array<string,mixed>>, mode:string, query:string, suggestions:list<array<string,mixed>>}
     *         mode: keyword | like | none
     */
    public function search(string $query): array
    {
        $query = $this->normalize($query);

        if ($query === '') {
            return ['rows' => [], 'mode' => 'none', 'query' => '', 'suggestions' => []];
        }

        // 被隐藏的词不做 2-gram 扩展。
        //
        // 不扩展的理由：搜「世代飞船」时会拆出「飞船」，而「飞船内舱」是一条公开词条，
        // 于是页面显示「共 1 篇」—— 词本身没出现，可结果却暗示馆里有这个话题。
        // 隐藏的词应当表现得和从不存在一样：只按整串查，查不到就是查不到。
        $keywords = $this->guard->isMasked($query)
            ? [$query]
            : $this->keywordsOf($query);
        $matches  = $this->library->matchKeywords($keywords);

        if ($matches !== []) {
            $rows = $this->library->attachMainTerms($this->library->listByWorkIds(array_column($matches, 'work_id')));

            // 保持 L3 的得分顺序（listByWorkIds 的返回顺序由 SQL 决定，不可依赖）
            $score = [];
            foreach ($matches as $match) {
                $score[$match['work_id']] = $match['score'];
            }
            usort($rows, static fn (array $a, array $b): int => ($score[(int) $b['id']] ?? 0) <=> ($score[(int) $a['id']] ?? 0));

            foreach ($rows as &$row) {
                $row['score'] = $score[(int) $row['id']] ?? 0;
            }
            unset($row);

            // 搜到的原因：读者搜「克制」，对上的词条叫「克制的悲伤」，不解释一句就说不清
            $matched = $this->library->matchedTerms(array_column($rows, 'id'), $keywords);
            foreach ($rows as &$row) {
                $row['matched'] = $matched[(int) $row['id']] ?? [];
            }
            unset($row);

            return ['rows' => $rows, 'mode' => 'keyword', 'query' => $query, 'suggestions' => []];
        }

        $rows = $this->library->attachMainTerms($this->library->searchLike(
            $query,
            40,
            // 查询词本身就是被隐藏的词时，不再翻作品简介：那是作者原文，
            // 不该被改写，但也不该成为隐藏词的检索入口。
            !$this->guard->isMasked($query)
        ));
        if ($rows !== []) {
            foreach ($rows as &$row) {
                $row['score']   = 0;
                $row['matched'] = [];
            }
            unset($row);

            return ['rows' => $rows, 'mode' => 'like', 'query' => $query, 'suggestions' => []];
        }

        return ['rows' => [], 'mode' => 'none', 'query' => $query, 'suggestions' => $this->similarTerms($query)];
    }

    /**
     * 相近词建议：按字符编辑距离取最近的几个词条（长度差超过 3 的直接跳过，省算力）。
     *
     * @return list<array<string,mixed>>
     */
    public function similarTerms(string $query, int $limit = 3): array
    {
        $query = $this->normalize($query);
        if ($query === '' || mb_strlen($query, 'UTF-8') < 2) {
            return [];
        }

        $queryLength = mb_strlen($query, 'UTF-8');
        $scored      = [];

        foreach ($this->library->allActiveTerms() as $term) {
            $length = mb_strlen($term['canonical'], 'UTF-8');
            if (abs($length - $queryLength) > 3) {
                continue;
            }
            $distance = Text::distance($query, $term['canonical']);
            if ($distance <= 3) {
                $scored[] = ['id' => $term['id'], 'canonical' => $term['canonical'], 'distance' => $distance];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $a['distance'] <=> $b['distance']);

        return array_slice($scored, 0, max(1, min(6, $limit)));
    }

    /** 检索串归一：折叠空白、去首尾、截断。不许空串进 SQL。 */
    public function normalize(string $query): string
    {
        $query = trim((string) preg_replace('/[\s\x{3000}]+/u', ' ', $query));

        return mb_substr($query, 0, self::MAX_QUERY, 'UTF-8');
    }

    /**
     * 一个检索串要匹配的关键词集合：整串 + 2-gram。
     *
     * 整串必须留着：搜「星际接触」时，索引词里那条就叫「星际接触」，
     * 只发二元组会把它的高权重摊薄到「星际」「际接」「接触」上。
     *
     * @return list<string>
     */
    public function keywordsOf(string $query): array
    {
        $keys = [$query];
        foreach (Text::grams($query) as $gram) {
            $keys[] = $gram;
        }

        return array_values(array_unique(array_filter($keys, static fn (string $k): bool => $k !== '')));
    }

    // ------------------------------------------------------- 关键词表维护

    /**
     * 按作品编号重建索引。发布 / 下线 / 编辑产出之后调这个。
     *
     * 作品已不是公开状态时 keywordSource 返回 null，replaceKeywords 走的是
     * 「删掉这篇的所有关键词」—— 所以这一个方法同时承担「建」与「拆」，
     * 调用方不需要先判断它现在是什么状态。
     */
    public function reindexByCode(string $code): int
    {
        $workId = $this->library->workIdByCode($code);

        return $workId > 0 ? $this->reindexWork($workId) : 0;
    }

    /**
     * 重建一篇作品的检索关键词。发布、重跑、编辑产出后都要调一次。
     *
     * @return int 写入的关键词条数
     */
    public function reindexWork(int $workId): int
    {
        $source = $this->library->keywordSource($workId);
        if ($source === null) {
            $this->library->deleteKeywords($workId);

            return 0;
        }

        return $this->library->replaceKeywords(
            $workId,
            (string) $source['title'],
            (string) ($source['author'] ?? ''),
            $source['terms'],
            $source['aliases'],
            $source['suggestions']
        );
    }

    /**
     * 全量重建（发布口径变了、词表归并之后跑一次）。
     *
     * @return array{works:int, keywords:int}
     */
    public function reindexAll(): array
    {
        $works = 0;
        $total = 0;

        foreach ($this->library->publishedWorkIds() as $workId) {
            $total += $this->reindexWork($workId);
            $works++;
        }

        return ['works' => $works, 'keywords' => $total];
    }
}
