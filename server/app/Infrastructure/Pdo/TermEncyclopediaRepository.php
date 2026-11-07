<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdo;

use PDO;

/**
 * 词条百科（hq_term_encyclopedia）：详情页四要素里的「起源与发展」「世界科幻中的它」。
 *
 * 这两段文字原来只活在 resources/taxonomy/term_encyclopedia.php 里，改一个词条
 * 等于改代码 + 部署，而且那个目录属于 AI 拆解流水线 —— 运营补一句考据却被要求
 * 重跑拆解，这是这次搬迁要解决的唯一问题。
 *
 * 一条词条一行（uk_term）。读路径的设计见 App\Application\Analysis\TermEncyclopedia：
 * 先查库、查不到回落文件，所以文件里的 68 条基线仍是兜底，不会因为迁移漏数据而消失。
 */
final class TermEncyclopediaRepository
{
    /** 世界科幻中的它至多 3 部。上限写在数据库之外，是因为它首先是产品规则 */
    public const MAX_CLASSICS = 3;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * 读一个词条的百科内容。
     *
     * classics_json 解不出来时返回空数组而不是抛：这一列是人工/脚本写入的，
     * 一行坏数据不该让整个词条页 500 —— 缺一节内容比整页打不开好得多。
     *
     * @return array{history: string, classics: list<array{title: string, author: string, year: int}>}|null
     */
    public function findByTermId(int $termId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT history, classics_json FROM hq_term_encyclopedia WHERE term_id = :t LIMIT 1'
        );
        $stmt->execute([':t' => $termId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'history'  => (string) $row['history'],
            'classics' => self::decodeClassics($row['classics_json'] ?? null),
        ];
    }

    /**
     * 写入或覆盖一个词条的百科内容。
     *
     * 用 ON DUPLICATE KEY UPDATE 而不是「先查再决定 INSERT/UPDATE」：
     * 后者在两个人同时保存时会撞 uk_term，前者交给唯一索引保证原子性。
     *
     * @param list<array{title?: string, author?: string, year?: int|string}> $classics
     */
    public function upsert(int $termId, string $history, array $classics, ?int $userId): void
    {
        // VARCHAR(1000) 是按「字」算的（utf8mb4），超长会在严格模式下抛 1406。
        // 这里截断而不是抛：后台表单已经限了 maxlength，走到这一步的多半是脚本导入。
        $history = mb_substr($history, 0, 1000, 'UTF-8');

        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_term_encyclopedia
                 (term_id, history, classics_json, updated_by, created_at, updated_at)
             VALUES (:t, :h, :c, :u, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                 history      = VALUES(history),
                 classics_json = VALUES(classics_json),
                 updated_by   = VALUES(updated_by),
                 updated_at   = NOW()'
        );
        $stmt->execute([
            ':t' => $termId,
            ':h' => $history,
            ':c' => self::encodeClassics($classics),
            ':u' => $userId,
        ]);
    }

    /**
     * 把文件里的基线灌进库。
     *
     * `--apply` 会覆盖「库里已有且内容不同」的行 —— 这是刻意的：这个命令的用途是
     * 回灌基线，不是增量补空。不想被覆盖的场景（后台刚改过）应当先看预览输出。
     * `$dryRun` 让预览与落库走同一段比对逻辑，避免「预览说 3 条、实际写 5 条」。
     *
     * $termIdByName 的键按词名给（未归一化），内部统一归一化后匹配；
     * 匹配不到的在调用方（bin/console.php）列出来 —— 那是文件里的死键。
     *
     * @param array<string, array{history: string, classics: list<array{title: string, author: string, year: int}>}> $map
     * @param array<string, int> $termIdByName
     * @return array{inserted: int, updated: int, skipped: int}
     */
    public function seedFromFile(array $map, array $termIdByName, ?int $userId, bool $dryRun = false): array
    {
        $lookup = [];
        foreach ($termIdByName as $name => $id) {
            $lookup[self::normalize((string) $name)] = (int) $id;
        }

        $existing = $this->loadExisting();

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        foreach ($map as $canonical => $entry) {
            $termId = $lookup[self::normalize((string) $canonical)] ?? 0;
            if ($termId <= 0) {
                $skipped++;   // 文件里有、库里没有对应词条
                continue;
            }

            $history  = (string) ($entry['history'] ?? '');
            $classics = self::normalizeClassics((array) ($entry['classics'] ?? []));

            if (!isset($existing[$termId])) {
                $inserted++;
                if (!$dryRun) {
                    $this->upsert($termId, $history, $classics, $userId);
                }
                continue;
            }

            $same = $existing[$termId]['history'] === mb_substr($history, 0, 1000, 'UTF-8')
                && $existing[$termId]['classics'] === $classics;

            if ($same) {
                $skipped++;
                continue;
            }

            $updated++;
            if (!$dryRun) {
                $this->upsert($termId, $history, $classics, $userId);
            }
        }

        return ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * 一次读全表（播种时最多几千行，比逐条 SELECT 划算，也让比对逻辑集中在一处）。
     *
     * @return array<int, array{history: string, classics: list<array{title: string, author: string, year: int}>}>
     */
    private function loadExisting(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT term_id, history, classics_json FROM hq_term_encyclopedia') as $row) {
            $out[(int) $row['term_id']] = [
                'history'  => (string) $row['history'],
                'classics' => self::decodeClassics($row['classics_json'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{title: string, author: string, year: int}>
     */
    private static function decodeClassics(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return self::normalizeClassics($decoded);
    }

    /**
     * @param array<mixed> $classics
     * @return list<array{title: string, author: string, year: int}>
     */
    private static function normalizeClassics(array $classics): array
    {
        $out = [];
        foreach (array_slice(array_values($classics), 0, self::MAX_CLASSICS) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title  = trim((string) ($item['title'] ?? ''));
            $author = trim((string) ($item['author'] ?? ''));
            $year   = (int) ($item['year'] ?? 0);
            if ($title === '' && $author === '' && $year === 0) {
                continue;   // 空行
            }
            $out[] = ['title' => $title, 'author' => $author, 'year' => $year];
        }

        return $out;
    }

    /**
     * @param list<array{title?: string, author?: string, year?: int|string}> $classics
     */
    private static function encodeClassics(array $classics): string
    {
        $json = json_encode(
            self::normalizeClassics($classics),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return $json === false ? '[]' : $json;
    }

    /**
     * 词名归一化：与 TermEncyclopedia::key / Taxonomy::key 同一规则（去空白、转小写）。
     *
     * 公开出来是为了让 bin/console.php 的预览能用同一把尺子判断「文件里的键在库里
     * 找不找得到」—— 预览说死键 0 条、落库却匹配不上，这种分歧最难查。
     */
    public static function normalize(string $text): string
    {
        $text = preg_replace('/\s+/u', '', $text) ?? $text;

        return mb_strtolower($text, 'UTF-8');
    }
}
