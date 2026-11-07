<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdo;

use PDO;

/**
 * 练笔统计快照（hq_practice_stat / hq_practice_stat_run）。
 *
 * ## 为什么统计结果是「写进去再读出来」，不是每次查询时 SUM
 *
 * 三个理由，按重要性排：
 *
 * 1. **作者数与评稿成员数要去重**。同一个人投过三期，合计只能算一个作者；
 *    同一位置评过两期，合计只能算一个评稿成员。这不是 SUM 能表达的，
 *    必须在算的时候就用集合求并，然后把结果封存。
 * 2. **历史必须留得住**。官网那边撤一篇稿、补一条评稿，重算出来的数就变了。
 *    「上个月是 1183 篇」是一句已经说出去的话，不能因为数据源变了就变成假的。
 * 3. **页面要快**。现算一次是两分钟、一百多次外部请求，页面撑不住，
 *    也不该由一次页面访问去触发对别人服务器的抓取。
 *
 * ## 一次重算 = 一个 run + N+1 行
 *
 * run 是批次（开始/结束/用了哪份目录），stat 是明细。`issue_no = 0` 那一行是总计。
 * 总计**单独成行**而不是查询时聚合，就是为了让「去重后的合计」这件事只算一次、
 * 只存一处 —— 否则页面上的合计与命令行打印的合计迟早会不一样。
 *
 * 读取一律带 `run_id`：翻到一半时新一轮开始了，旧读法会把两轮的数混在一起，
 * 而这两轮之间可能正好有人上线了一期。
 */
final class PracticeStatRepository
{
    /** 期次行与总计行的分界。总计固定 0，期次从 1 开始 */
    public const TOTAL_ISSUE = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * 开一轮。
     *
     * `$startedAt` 由调用方给：这一轮的起点是「开始拉目录」的那一刻，不是「算完了
     * 开始写库」的那一刻。默认取当前时间只用于重跑单条记录这种边角场景 ——
     * 用它当常态，`seconds` 会永远是 0，而「这次重算花了多久」正是排查
     * 「为什么这个月的定时任务跑到 19 点还没完」时第一个要看的东西。
     *
     * @return array{id:int, code:string}
     */
    public function beginRun(string $code, string $triggered, string $note = '', ?string $startedAt = null): array
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_practice_stat_run (code, status, triggered, note, started_at)
             VALUES (:c, "running", :t, :n, :s)'
        );
        $stmt->execute([
            ':c' => $code,
            ':t' => $triggered,
            ':n' => $note === '' ? null : mb_substr($note, 0, 200),
            ':s' => $startedAt ?? $now,
        ]);

        return ['id' => (int) $this->pdo->lastInsertId(), 'code' => $code];
    }

    /**
     * 把一轮的结果整体写进去。
     *
     * 先删本轮旧行再插：同一轮里如果中途重试，残留的半套行会让「这一期有 61 篇」
     * 和「这一期有 60 篇」同时存在，页面上看起来像多了一期。
     *
     * @param list<array<string,mixed>> $rows 已按 issue_no 去重的明细（含总计行）
     */
    public function saveRows(int $runId, array $rows, string $catalogAt, int $catalogN, int $issues): void
    {
        $this->pdo->beginTransaction();
        try {
            $del = $this->pdo->prepare('DELETE FROM hq_practice_stat WHERE run_id = :r');
            $del->execute([':r' => $runId]);

            $sql = 'INSERT INTO hq_practice_stat
                (run_id, issue_no, source_mid, label,
                 works_public, works_hidden, works_rejected, works_total,
                 words_public, words_hidden, words_rejected, words_total,
                 authors, authors_public, works_anon,
                 review_count, review_people, review_ai, review_people_all,
                 site_works, site_words, site_review, site_review_people,
                 ended_at, reveal_at, fetched_at)
                VALUES
                (:run, :no, :mid, :label,
                 :wp, :wh, :wr, :wt,
                 :wsp, :wsh, :wsr, :wst,
                 :au, :aup, :anon,
                 :rc, :rp, :rai, :rpa,
                 :sw, :swd, :sr, :srp,
                 :ended, :reveal, :fetched)';
            $ins = $this->pdo->prepare($sql);

            foreach ($rows as $r) {
                $ins->execute([
                    ':run'    => $runId,
                    ':no'     => (int) $r['issue_no'],
                    ':mid'    => (int) ($r['source_mid'] ?? 0),
                    ':label'  => mb_substr((string) $r['label'], 0, 32),
                    ':wp'     => (int) $r['works_public'],
                    ':wh'     => (int) $r['works_hidden'],
                    ':wr'     => (int) $r['works_rejected'],
                    ':wt'     => (int) $r['works_total'],
                    ':wsp'    => (int) $r['words_public'],
                    ':wsh'    => (int) $r['words_hidden'],
                    ':wsr'    => (int) $r['words_rejected'],
                    ':wst'    => (int) $r['words_total'],
                    ':au'     => (int) $r['authors'],
                    ':aup'    => (int) $r['authors_public'],
                    ':anon'   => (int) $r['works_anon'],
                    ':rc'     => (int) $r['review_count'],
                    ':rp'     => (int) $r['review_people'],
                    ':rai'    => (int) $r['review_ai'],
                    ':rpa'    => (int) $r['review_people_all'],
                    ':sw'     => (int) $r['site_works'],
                    ':swd'    => (int) $r['site_words'],
                    ':sr'     => (int) $r['site_review'],
                    ':srp'    => (int) $r['site_review_people'],
                    ':ended'  => self::dt($r['ended_at'] ?? 0),
                    ':reveal' => self::dt($r['reveal_at'] ?? 0),
                    ':fetched' => (string) $r['fetched_at'],
                ]);
            }

            $upd = $this->pdo->prepare(
                'UPDATE hq_practice_stat_run
                    SET status = "done", issues = :i, catalog_at = :ca, catalog_n = :cn,
                        finished_at = :f, seconds = TIMESTAMPDIFF(SECOND, started_at, :f2)
                  WHERE id = :id'
            );
            $now = date('Y-m-d H:i:s');
            $upd->execute([
                ':i'  => $issues,
                ':ca' => $catalogAt === '' ? null : $catalogAt,
                ':cn' => $catalogN,
                ':f'  => $now,
                ':f2' => $now,
                ':id' => $runId,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function fail(int $runId, string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE hq_practice_stat_run
                SET status = "failed", error = :e, finished_at = :f,
                    seconds = TIMESTAMPDIFF(SECOND, started_at, :f2)
              WHERE id = :id'
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([':e' => mb_substr($error, 0, 500), ':f' => $now, ':f2' => $now, ':id' => $runId]);
    }

    /**
     * 最新一轮成功的统计。
     *
     * 「成功」是硬条件：失败的那一轮里可能只写了一半期次，拿它当数据源会让
     * 页面少显示几期而不报错。宁可显示上一轮完整的数，也不要显示这一轮残缺的数。
     *
     * @return array{run:array<string,mixed>, rows:list<array<string,mixed>>, total:array<string,mixed>}|null
     */
    public function latest(): ?array
    {
        $run = $this->pdo->query(
            'SELECT * FROM hq_practice_stat_run WHERE status = "done" ORDER BY id DESC LIMIT 1'
        )->fetch();

        if ($run === false) {
            return null;
        }

        return $this->load((int) $run['id']);
    }

    /**
     * @return array{run:array<string,mixed>, rows:list<array<string,mixed>>, total:array<string,mixed>}|null
     */
    public function load(int $runId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM hq_practice_stat_run WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $runId]);
        $run = $stmt->fetch();
        if ($run === false) {
            return null;
        }

        $rows = $this->pdo->prepare(
            'SELECT * FROM hq_practice_stat WHERE run_id = :r ORDER BY issue_no ASC'
        );
        $rows->execute([':r' => $runId]);
        $all = $rows->fetchAll() ?: [];

        $issues = [];
        $total  = null;
        foreach ($all as $r) {
            if ((int) $r['issue_no'] === self::TOTAL_ISSUE) {
                $total = $r;
            } else {
                $issues[] = $r;
            }
        }

        return ['run' => $run, 'rows' => $issues, 'total' => $total ?? []];
    }

    /** 最近几次重算，用于「上一次什么时候算的」。@return list<array<string,mixed>> */
    public function recentRuns(int $limit = 6): array
    {
        $limit = max(1, min(50, $limit));
        $sql = 'SELECT id, code, status, issues, catalog_at, catalog_n, triggered, note, error,
                       started_at, finished_at, seconds
                  FROM hq_practice_stat_run ORDER BY id DESC LIMIT ' . $limit;

        return $this->pdo->query($sql)->fetchAll() ?: [];
    }

    private static function dt(int $ts): ?string
    {
        return $ts > 0 ? date('Y-m-d H:i:s', $ts) : null;
    }
}
