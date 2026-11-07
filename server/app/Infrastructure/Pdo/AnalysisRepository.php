<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdo;

use App\Application\Analysis\Taxonomy;
use App\Support\Ulid;
use PDO;
use RuntimeException;

/**
 * 分析聚合仓储：一次分析（hq_analysis）+ 六阶段记录（hq_analysis_stage）+ 四件产出（8 张表）。
 *
 * 事务边界：**S6 组装时一次事务写完四件产出**。原因是「半份分析」比「没有分析」更危险——
 * 前台会渲染出缺章的结果，而作者以为已经拆完了。所以宁可整篇回滚，也不留半成品。
 */
final class AnalysisRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $artifactDir,
    ) {
    }

    // ------------------------------------------------------------- 分析主表

    public function create(
        int $workId,
        int $workVersionId,
        string $model,
        string $promptVersion,
        string $inputHash,
        ?int $createdBy = null,
    ): array {
        $code = Ulid::generate();

        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_analysis
                (code, work_id, work_version_id, status, model, prompt_version, input_hash, source,
                 token_in, token_out, cost_micro, created_by, created_at, updated_at)
             VALUES
                (:code, :work, :version, \'queued\', :model, :pver, :hash, \'ai\',
                 0, 0, 0, :creator, NOW(), NOW())'
        );
        $stmt->execute([
            ':code'    => $code,
            ':work'    => $workId,
            ':version' => $workVersionId,
            ':model'   => $model,
            ':pver'    => $promptVersion,
            ':hash'    => $inputHash,
            ':creator' => $createdBy,
        ]);

        return ['id' => (int) $this->pdo->lastInsertId(), 'code' => $code];
    }

    /**
     * 幂等复用：同一 input_hash 已经成功跑过，就直接复用，不再花钱（§6.6）。
     *
     * @return array<string,mixed>|null
     */
    public function findReusable(string $inputHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM hq_analysis
              WHERE input_hash = :hash AND status IN (\'succeeded\', \'published\')
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':hash' => $inputHash]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, w.title, w.code AS work_code, w.word_count
               FROM hq_analysis a
               JOIN hq_work w ON w.id = a.work_id
              WHERE a.code = :code LIMIT 1'
        );
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, w.title, w.code AS work_code, w.word_count
               FROM hq_analysis a
               JOIN hq_work w ON w.id = a.work_id
              WHERE a.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function listForWork(int $workId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, code, status, model, prompt_version, source, token_in, token_out, cost_micro,
                    error_code, started_at, finished_at, created_at
               FROM hq_analysis WHERE work_id = :work ORDER BY id DESC'
        );
        $stmt->execute([':work' => $workId]);

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null 该作品最新一次分析 */
    public function latestForWork(int $workId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM hq_analysis WHERE work_id = :work ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':work' => $workId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function setStatus(int $analysisId, string $status, ?string $errorCode = null, ?string $errorMsg = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE hq_analysis
                SET status = :status, error_code = :code, error_msg = :msg,
                    started_at = CASE WHEN :st1 = \'running\' AND started_at IS NULL THEN NOW() ELSE started_at END,
                    finished_at = CASE WHEN :st2 IN (\'succeeded\',\'failed\',\'partially_failed\') THEN NOW() ELSE finished_at END,
                    updated_at = NOW()
              WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $status,
            ':code'   => $errorCode,
            ':msg'    => $errorMsg === null ? null : mb_substr($errorMsg, 0, 500),
            ':st1'    => $status,
            ':st2'    => $status,
            ':id'     => $analysisId,
        ]);
    }

    public function addUsage(int $analysisId, int $tokenIn, int $tokenOut, int $costMicro): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE hq_analysis
                SET token_in = token_in + :tin, token_out = token_out + :tout,
                    cost_micro = cost_micro + :cost, updated_at = NOW()
              WHERE id = :id'
        );
        $stmt->execute([
            ':tin'  => max(0, $tokenIn),
            ':tout' => max(0, $tokenOut),
            ':cost' => max(0, $costMicro),
            ':id'   => $analysisId,
        ]);
    }

    // ------------------------------------------------------------- 阶段记录

    public function stageStart(int $analysisId, string $stageCode, string $model, string $promptVersion): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_analysis_stage
                (analysis_id, stage_code, status, attempt, model, prompt_version, started_at, created_at, updated_at)
             VALUES (:aid, :code, \'running\', 1, :model, :pver, NOW(), NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                status = \'running\', attempt = attempt + 1, model = VALUES(model),
                prompt_version = VALUES(prompt_version), started_at = NOW(), updated_at = NOW(),
                error_code = NULL, error_msg = NULL'
        );
        $stmt->execute([
            ':aid'   => $analysisId,
            ':code'  => $stageCode,
            ':model' => $model,
            ':pver'  => $promptVersion,
        ]);
    }

    /**
     * @param array{in?:int, out?:int, cached?:int, latency?:int, http?:?int} $metrics
     */
    public function stageFinish(
        int $analysisId,
        string $stageCode,
        string $status,
        array $metrics = [],
        ?string $errorCode = null,
        ?string $errorMsg = null,
        ?string $rawPath = null,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE hq_analysis_stage
                SET status = :status, input_tokens = input_tokens + :tin, output_tokens = output_tokens + :tout,
                    cached_tokens = cached_tokens + :cached, latency_ms = latency_ms + :latency,
                    http_code = :http, error_code = :ecode, error_msg = :emsg,
                    raw_path = COALESCE(:raw, raw_path), finished_at = NOW(), updated_at = NOW()
              WHERE analysis_id = :aid AND stage_code = :code'
        );
        $stmt->execute([
            ':status' => $status,
            ':tin'    => (int) ($metrics['in'] ?? 0),
            ':tout'   => (int) ($metrics['out'] ?? 0),
            ':cached' => (int) ($metrics['cached'] ?? 0),
            ':latency' => (int) ($metrics['latency'] ?? 0),
            ':http'   => $metrics['http'] ?? null,
            ':ecode'  => $errorCode,
            ':emsg'   => $errorMsg === null ? null : mb_substr($errorMsg, 0, 500),
            ':raw'    => $rawPath,
            ':aid'    => $analysisId,
            ':code'   => $stageCode,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function stagesOf(int $analysisId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT stage_code, status, attempt, model, prompt_version, input_tokens, output_tokens,
                    cached_tokens, latency_ms, http_code, error_code, error_msg, started_at, finished_at
               FROM hq_analysis_stage WHERE analysis_id = :aid'
        );
        $stmt->execute([':aid' => $analysisId]);

        return $stmt->fetchAll();
    }

    /**
     * 阶段原始响应落盘：storage/artifacts/{analysis_id}/{stage}.json
     *
     * 只存模型响应，不存请求全文（全文在 storage/uploads，§7.5）。
     * 返回相对路径，落库只存相对路径，换机器/换盘不用改数据。
     */
    public function saveRaw(int $analysisId, string $stageCode, array $payload): string
    {
        $dir = $this->artifactDir . '/' . $analysisId;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('无法创建产物目录：' . $dir);
        }

        $relative = $analysisId . '/' . $stageCode . '.json';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($dir . '/' . $stageCode . '.json', $json) === false) {
            throw new RuntimeException('产物写入失败：' . $relative);
        }
        @chmod($dir . '/' . $stageCode . '.json', 0640);

        return $relative;
    }

    // ------------------------------------------------------------- 四件产出

    /**
     * 一次事务写入全部四件产出。任一步失败则整体回滚。
     *
     * @param array{index_card:array, terms:list<array>}                                   $index
     * @param array{summary:array, beats:list<array>}                                      $plot
     * @param array{foreshadows:list<array>}                                               $foreshadow
     * @param array{suggestions:list<array>}                                               $advice
     * @param array{summary:string, items:list<array>}                                     $revision
     */
    public function saveArtifacts(
        int $analysisId,
        int $workId,
        array $index,
        array $plot,
        array $foreshadow,
        array $advice,
        array $revision,
        array $reader = [],
    ): void {
        $this->pdo->beginTransaction();
        try {
            $this->clearArtifacts($analysisId, $workId);
            $this->writeIndex($analysisId, $workId, $index);
            $beatIds = $this->writePlot($analysisId, $plot);
            $this->writeForeshadow($analysisId, $foreshadow, $beatIds);
            $this->writeAdvice($analysisId, $advice);
            $this->writeRevision($analysisId, $revision);
            $this->writeReader($analysisId, $reader);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * 读者视角（S7）。
     *
     * 单独一张表而不是塞进 hq_index_card：这两块的生命周期不同 ——
     * 改关键词不该把读者页的「看点」一起作废，反之亦然。
     *
     * @param array<string,mixed> $reader
     */
    private function writeReader(int $analysisId, array $reader): void
    {
        if ($reader === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_reader_brief
                (analysis_id, hook, appeal_json, visual_tone, visual_lens, visual_ref, visual_scene,
                 audience_primary, audience_also, audience_avoid, audience_gate, terms_note, created_at, updated_at)
             VALUES (:aid, :hook, :appeal, :tone, :lens, :ref, :scene, :primary, :also, :avoid, :gate, :note, NOW(), NOW())'
        );

        $visual   = is_array($reader['visual'] ?? null) ? $reader['visual'] : [];
        $audience = is_array($reader['audience'] ?? null) ? $reader['audience'] : [];

        $stmt->execute([
            ':aid'     => $analysisId,
            ':hook'    => mb_substr((string) ($reader['hook'] ?? ''), 0, 300),
            ':appeal'  => json_encode($reader['appeal'] ?? [], JSON_UNESCAPED_UNICODE),
            ':tone'    => mb_substr((string) ($visual['tone'] ?? ''), 0, 300),
            ':lens'    => mb_substr((string) ($visual['lens'] ?? ''), 0, 300),
            ':ref'     => mb_substr((string) ($visual['reference'] ?? ''), 0, 400),
            ':scene'   => mb_substr((string) ($visual['scene'] ?? ''), 0, 400),
            ':primary' => mb_substr((string) ($audience['primary'] ?? ''), 0, 300),
            ':also'    => mb_substr((string) ($audience['also'] ?? ''), 0, 300),
            ':avoid'   => mb_substr((string) ($audience['avoid'] ?? ''), 0, 300),
            ':gate'    => mb_substr((string) ($audience['gate'] ?? ''), 0, 300),
            ':note'    => mb_substr((string) ($reader['terms_note'] ?? ''), 0, 300),
        ]);
    }

    /** 清掉这篇分析已有的产出（重跑时用；不动其他分析的数据） */
    public function clearArtifacts(int $analysisId, int $workId): void
    {
        // 词条关联按 **work_id** 删，不按 analysis_id。
        // 一篇作品在 hq_work_term 里只该有「当前这份分析」的词条；如果按 analysis_id 删，
        // 上一版分析留下的关联会原地不动，新词再 upsert 进来 —— 结果就是页面同时挂着
        // 新旧两套词（旧词条没有定义、还带着专名），词库的引用计数也跟着虚高。
        $this->pdo->prepare('DELETE FROM hq_work_term WHERE work_id = :wid')->execute([':wid' => $workId]);
        $this->pdo->prepare('DELETE FROM hq_index_card WHERE analysis_id = :aid')->execute([':aid' => $analysisId]);
        $this->pdo->prepare('DELETE FROM hq_reader_brief WHERE analysis_id = :aid')->execute([':aid' => $analysisId]);
        $this->pdo->prepare('DELETE FROM hq_foreshadow WHERE analysis_id = :aid')->execute([':aid' => $analysisId]);
        $this->pdo->prepare('DELETE FROM hq_plot_beat WHERE analysis_id = :aid')->execute([':aid' => $analysisId]);
        $this->pdo->prepare('DELETE FROM hq_plot_summary WHERE analysis_id = :aid')->execute([':aid' => $analysisId]);

        $ids = $this->pdo->prepare('SELECT id FROM hq_suggestion WHERE analysis_id = :aid');
        $ids->execute([':aid' => $analysisId]);
        $suggestionIds = array_map(static fn (array $r): int => (int) $r['id'], $ids->fetchAll());
        foreach ($suggestionIds as $sid) {
            $this->pdo->prepare('DELETE FROM hq_suggestion_path WHERE suggestion_id = :sid')->execute([':sid' => $sid]);
        }
        $this->pdo->prepare('DELETE FROM hq_suggestion WHERE analysis_id = :aid')->execute([':aid' => $analysisId]);

        $rev = $this->pdo->prepare('SELECT id FROM hq_revision WHERE analysis_id = :aid');
        $rev->execute([':aid' => $analysisId]);
        $revId = $rev->fetchColumn();
        if ($revId !== false) {
            $this->pdo->prepare('DELETE FROM hq_revision_item WHERE revision_id = :rid')->execute([':rid' => $revId]);
            $this->pdo->prepare('DELETE FROM hq_revision WHERE id = :rid')->execute([':rid' => $revId]);
        }
    }

    /** @param array{index_card:array, terms:list<array>} $index */
    private function writeIndex(int $analysisId, int $workId, array $index): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_index_card (analysis_id, one_liner, theme_note, created_at, updated_at)
             VALUES (:aid, :one, :note, NOW(), NOW())'
        );
        $stmt->execute([
            ':aid'  => $analysisId,
            ':one'  => mb_substr((string) ($index['index_card']['one_liner'] ?? ''), 0, 300),
            ':note' => mb_substr((string) ($index['index_card']['theme_note'] ?? ''), 0, 500),
        ]);

        $findTerm   = $this->pdo->prepare('SELECT id, definition, is_canonical FROM hq_index_term WHERE canonical = :c LIMIT 1');
        $insertTerm = $this->pdo->prepare(
            'INSERT INTO hq_index_term (canonical, category, definition, is_canonical, weight_base, status, created_at, updated_at)
             VALUES (:c, :cat, :def, :canon, 1.000, \'active\', NOW(), NOW())'
        );
        // 词表扩充后，库里已有的同名词要跟着升为通行词 —— 否则它会被当成表外词，
        // 还得再挣一篇引用才肯露面
        $raiseCanon = $this->pdo->prepare(
            'UPDATE hq_index_term SET is_canonical = 1, updated_at = NOW() WHERE id = :id AND is_canonical = 0'
        );
        // 定义是词库的正文：老词条没有定义时用新分析补上。已有的定义不覆盖 ——
        // 那是别人校过的措辞，一次重跑不该把它冲掉。
        $fillDefinition = $this->pdo->prepare(
            'UPDATE hq_index_term SET definition = :def, updated_at = NOW()
              WHERE id = :id AND (definition IS NULL OR definition = \'\')'
        );
        $insertAlias = $this->pdo->prepare(
            'INSERT IGNORE INTO hq_index_term_alias (term_id, alias, source, created_at) VALUES (:tid, :alias, \'ai\', NOW())'
        );
        $upsertWorkTerm = $this->pdo->prepare(
            'INSERT INTO hq_work_term (work_id, term_id, role, weight, evidence, analysis_id, created_at)
             VALUES (:work, :term, :role, :weight, :evidence, :aid, NOW())
             ON DUPLICATE KEY UPDATE role = VALUES(role), weight = VALUES(weight),
                                     evidence = VALUES(evidence), analysis_id = VALUES(analysis_id)'
        );

        foreach ($index['terms'] as $term) {
            // 通行词表里的词免检进词库；表外新词要挣到第二篇作品才露面
            $isCanon = Taxonomy::categoryOf((string) $term['canonical']) !== null ? 1 : 0;

            $findTerm->execute([':c' => $term['canonical']]);
            $row    = $findTerm->fetch();
            $termId = $row === false ? false : (int) $row['id'];

            if ($termId === false) {
                $insertTerm->execute([
                    ':c'     => $term['canonical'],
                    ':cat'   => $term['category'],
                    ':def'   => $term['definition'] === '' ? null : mb_substr((string) $term['definition'], 0, 500),
                    ':canon' => $isCanon,
                ]);
                $termId = (int) $this->pdo->lastInsertId();
            } else {
                if ($isCanon === 1 && (int) $row['is_canonical'] === 0) {
                    $raiseCanon->execute([':id' => $termId]);
                }
                if ($term['definition'] !== '' && ($row['definition'] === null || $row['definition'] === '')) {
                    $fillDefinition->execute([
                        ':def' => mb_substr((string) $term['definition'], 0, 500),
                        ':id'  => $termId,
                    ]);
                }
            }

            foreach ($term['aliases'] as $alias) {
                // 别名全局唯一：已被别的词条占用就跳过，不抢不覆盖
                $insertAlias->execute([':tid' => $termId, ':alias' => $alias]);
            }

            $upsertWorkTerm->execute([
                ':work'     => $workId,
                ':term'     => $termId,
                ':role'     => $term['role'],
                ':weight'   => (int) $term['weight'],
                ':evidence' => $term['evidence'] === '' ? null : mb_substr($term['evidence'], 0, 200),
                ':aid'      => $analysisId,
            ]);
        }
    }

    /**
     * @param array{summary:array, beats:list<array>} $plot
     * @return array<int,int> seq => beat_id
     */
    private function writePlot(int $analysisId, array $plot): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_plot_summary (analysis_id, who, want, cost, logline, created_at, updated_at)
             VALUES (:aid, :who, :want, :cost, :logline, NOW(), NOW())'
        );
        $stmt->execute([
            ':aid'     => $analysisId,
            ':who'     => mb_substr((string) $plot['summary']['who'], 0, 300),
            ':want'    => mb_substr((string) $plot['summary']['want'], 0, 300),
            ':cost'    => mb_substr((string) $plot['summary']['cost'], 0, 300),
            ':logline' => mb_substr((string) ($plot['summary']['logline'] ?? ''), 0, 300),
        ]);

        $insert = $this->pdo->prepare(
            'INSERT INTO hq_plot_beat (analysis_id, seq, phase, title, detail, quote, position_pct, created_at)
             VALUES (:aid, :seq, :phase, :title, :detail, :quote, :pos, NOW())'
        );

        $ids = [];
        foreach ($plot['beats'] as $beat) {
            $insert->execute([
                ':aid'   => $analysisId,
                ':seq'   => (int) $beat['seq'],
                ':phase' => $beat['phase'],
                ':title' => mb_substr((string) $beat['title'], 0, 120),
                ':detail' => $beat['detail'] === '' ? null : (string) $beat['detail'],
                ':quote' => $beat['quote'] === '' ? null : mb_substr((string) $beat['quote'], 0, 300),
                ':pos'   => (int) $beat['position_pct'],
            ]);
            $ids[(int) $beat['seq']] = (int) $this->pdo->lastInsertId();
        }

        return $ids;
    }

    /** @param array{foreshadows:list<array>} $foreshadow @param array<int,int> $beatIds */
    private function writeForeshadow(int $analysisId, array $foreshadow, array $beatIds): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_foreshadow (analysis_id, content, setup_beat_id, payoff_beat_id, state, created_at)
             VALUES (:aid, :content, :setup, :payoff, :state, NOW())'
        );

        foreach ($foreshadow['foreshadows'] as $item) {
            $setup  = $beatIds[(int) $item['setup_seq']] ?? null;
            if ($setup === null) {
                continue;   // 闸门已保证存在，这里只是双保险
            }
            $payoff = $item['payoff_seq'] === null ? null : ($beatIds[(int) $item['payoff_seq']] ?? null);

            $stmt->execute([
                ':aid'     => $analysisId,
                ':content' => mb_substr((string) $item['content'], 0, 300),
                ':setup'   => $setup,
                ':payoff'  => $payoff,
                ':state'   => $payoff === null ? 'open' : 'paid',
            ]);
        }
    }

    /** @param array{suggestions:list<array>} $advice */
    private function writeAdvice(int $analysisId, array $advice): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO hq_suggestion (analysis_id, layer, seq, title, problem, impact, priority, created_at, updated_at)
             VALUES (:aid, :layer, :seq, :title, :problem, :impact, :priority, NOW(), NOW())'
        );
        $insertPath = $this->pdo->prepare(
            'INSERT INTO hq_suggestion_path (suggestion_id, seq, style, content, created_at)
             VALUES (:sid, :seq, :style, :content, NOW())'
        );

        $seqByLayer = [];
        foreach ($advice['suggestions'] as $suggestion) {
            $layer = (string) $suggestion['layer'];
            $seqByLayer[$layer] = ($seqByLayer[$layer] ?? 0) + 1;

            $insert->execute([
                ':aid'     => $analysisId,
                ':layer'   => $layer,
                ':seq'     => $seqByLayer[$layer],
                ':title'   => mb_substr((string) $suggestion['title'], 0, 160),
                ':problem' => (string) $suggestion['problem'],
                ':impact'  => $suggestion['impact'] === '' ? null : mb_substr((string) $suggestion['impact'], 0, 300),
                // priority 只用于列表排序（结构层在前），不是评分
                ':priority' => $layer === 'structure' ? 1 : ($layer === 'execution' ? 3 : 5),
            ]);

            $suggestionId = (int) $this->pdo->lastInsertId();

            foreach ($suggestion['paths'] as $i => $path) {
                $insertPath->execute([
                    ':sid'     => $suggestionId,
                    ':seq'     => $i + 1,
                    ':style'   => $path['style'],
                    ':content' => mb_substr((string) $path['content'], 0, 500),
                ]);
            }
        }
    }

    /** @param array{summary:string, items:list<array>} $revision */
    private function writeRevision(int $analysisId, array $revision): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_revision (analysis_id, summary, created_at, updated_at)
             VALUES (:aid, :summary, NOW(), NOW())'
        );
        $stmt->execute([
            ':aid'     => $analysisId,
            ':summary' => mb_substr((string) $revision['summary'], 0, 500),
        ]);

        $revisionId = (int) $this->pdo->lastInsertId();

        $insert = $this->pdo->prepare(
            'INSERT INTO hq_revision_item
                (revision_id, rank_no, target, action, conservative, radical, impact_scope, decision_note, created_at)
             VALUES (:rid, :rank, :target, :action, :cons, :radi, :scope, :note, NOW())'
        );

        foreach ($revision['items'] as $item) {
            $insert->execute([
                ':rid'    => $revisionId,
                ':rank'   => (int) $item['rank'],
                ':target' => mb_substr((string) $item['target'], 0, 200),
                ':action' => mb_substr((string) $item['action'], 0, 300),
                ':cons'   => mb_substr((string) $item['conservative'], 0, 500),
                ':radi'   => mb_substr((string) $item['radical'], 0, 500),
                ':scope'  => mb_substr((string) $item['impact_scope'], 0, 300),
                ':note'   => $item['decision_note'] === '' ? null : mb_substr((string) $item['decision_note'], 0, 300),
            ]);
        }
    }

    /**
     * 读回四件产出（后台详情页与前台渲染共用）。
     *
     * @return array<string,mixed>
     */
    public function loadArtifacts(int $analysisId): array
    {
        $card = $this->pdo->prepare('SELECT one_liner, theme_note FROM hq_index_card WHERE analysis_id = :aid LIMIT 1');
        $card->execute([':aid' => $analysisId]);
        $indexCard = $card->fetch() ?: null;

        // 这里的 t.visibility 不能省：详情页会渲染产物里的词条条目（角色、权重、原文引证），
        // 少了这个条件，后台把某条词隐藏之后，它的定义与引证照样从这一路漏到页面上。
        // 与 LibraryRepository::TERM_LIVE 同一个口径。
        $terms = $this->pdo->prepare(
            'SELECT t.id, t.canonical, t.category, t.definition, wt.role, wt.weight, wt.evidence
               FROM hq_work_term wt
               JOIN hq_index_term t ON t.id = wt.term_id AND t.visibility = \'visible\'
              WHERE wt.analysis_id = :aid
              ORDER BY wt.role = \'main\' DESC, t.category, wt.weight DESC'
        );
        $terms->execute([':aid' => $analysisId]);

        $summaryStmt = $this->pdo->prepare('SELECT who, want, cost, logline FROM hq_plot_summary WHERE analysis_id = :aid LIMIT 1');
        $summaryStmt->execute([':aid' => $analysisId]);
        $summary = $summaryStmt->fetch() ?: null;

        $beatsStmt = $this->pdo->prepare(
            'SELECT id, seq, phase, title, detail, quote, position_pct
               FROM hq_plot_beat WHERE analysis_id = :aid ORDER BY seq'
        );
        $beatsStmt->execute([':aid' => $analysisId]);
        $beats = $beatsStmt->fetchAll();

        $beatTitle = [];
        foreach ($beats as $beat) {
            $beatTitle[(int) $beat['id']] = [
                'id'    => (int) $beat['id'],
                'seq'   => (int) $beat['seq'],
                'title' => (string) $beat['title'],
            ];
        }

        $foreStmt = $this->pdo->prepare(
            'SELECT id, content, setup_beat_id, payoff_beat_id, state FROM hq_foreshadow WHERE analysis_id = :aid ORDER BY id'
        );
        $foreStmt->execute([':aid' => $analysisId]);
        $foreshadows = [];
        foreach ($foreStmt->fetchAll() as $row) {
            // id 必须带出来：后台的「改 / 删这条伏笔」按它定位。早先只给展示形状，
            // 结果模板里这两个按钮做不出来，只能看不能动。
            $foreshadows[] = [
                'id'         => (int) $row['id'],
                'content'    => (string) $row['content'],
                'state'      => (string) $row['state'],
                'setup'      => $beatTitle[(int) $row['setup_beat_id']] ?? null,
                'payoff'     => $row['payoff_beat_id'] === null ? null : ($beatTitle[(int) $row['payoff_beat_id']] ?? null),
            ];
        }

        $sugStmt = $this->pdo->prepare(
            'SELECT id, layer, seq, title, problem, impact FROM hq_suggestion WHERE analysis_id = :aid ORDER BY priority, seq'
        );
        $sugStmt->execute([':aid' => $analysisId]);
        $pathStmt = $this->pdo->prepare(
            'SELECT id, seq, style, content FROM hq_suggestion_path WHERE suggestion_id = :sid ORDER BY seq'
        );
        $suggestions = [];
        foreach ($sugStmt->fetchAll() as $row) {
            $pathStmt->execute([':sid' => (int) $row['id']]);
            $suggestions[] = [
                'id'      => (int) $row['id'],
                'layer'   => (string) $row['layer'],
                'title'   => (string) $row['title'],
                'problem' => (string) $row['problem'],
                'impact'  => (string) ($row['impact'] ?? ''),
                'paths'   => $pathStmt->fetchAll(),
            ];
        }

        $revStmt = $this->pdo->prepare('SELECT id, summary FROM hq_revision WHERE analysis_id = :aid LIMIT 1');
        $revStmt->execute([':aid' => $analysisId]);
        $revRow = $revStmt->fetch();
        $revision = null;
        if (is_array($revRow)) {
            $itemStmt = $this->pdo->prepare(
                'SELECT id, rank_no, target, action, conservative, radical, impact_scope, decision_note
                   FROM hq_revision_item WHERE revision_id = :rid ORDER BY rank_no'
            );
            $itemStmt->execute([':rid' => (int) $revRow['id']]);
            $revision = ['summary' => (string) ($revRow['summary'] ?? ''), 'items' => $itemStmt->fetchAll()];
        }

        $readerStmt = $this->pdo->prepare(
            'SELECT hook, appeal_json, visual_tone, visual_lens, visual_ref, visual_scene,
                    audience_primary, audience_also, audience_avoid, audience_gate, terms_note
               FROM hq_reader_brief WHERE analysis_id = :aid LIMIT 1'
        );
        $readerStmt->execute([':aid' => $analysisId]);
        $readerRow = $readerStmt->fetch();
        $reader    = null;
        if (is_array($readerRow)) {
            $appeal = json_decode((string) ($readerRow['appeal_json'] ?? '[]'), true);

            $reader = [
                'hook'       => (string) $readerRow['hook'],
                'appeal'     => is_array($appeal) ? $appeal : [],
                'visual'     => [
                    'tone'      => (string) $readerRow['visual_tone'],
                    'lens'      => (string) $readerRow['visual_lens'],
                    'reference' => (string) $readerRow['visual_ref'],
                    'scene'     => (string) $readerRow['visual_scene'],
                ],
                'audience'   => [
                    'primary' => (string) $readerRow['audience_primary'],
                    'also'    => (string) $readerRow['audience_also'],
                    'avoid'   => (string) $readerRow['audience_avoid'],
                    'gate'    => (string) $readerRow['audience_gate'],
                ],
                'terms_note' => (string) ($readerRow['terms_note'] ?? ''),
            ];
        }

        return [
            'index_card'  => $indexCard === null ? null : [
                'one_liner'  => (string) $indexCard['one_liner'],
                'theme_note' => (string) ($indexCard['theme_note'] ?? ''),
            ],
            'terms'       => $terms->fetchAll(),
            'reader'      => $reader,
            'summary'     => $summary === null ? null : [
                'who'     => (string) $summary['who'],
                'want'    => (string) $summary['want'],
                'cost'    => (string) $summary['cost'],
                'logline' => (string) ($summary['logline'] ?? ''),
            ],
            'beats'       => $beats,
            'foreshadows' => $foreshadows,
            'suggestions' => $suggestions,
            'revision'    => $revision,
        ];
    }

    /** @return array<string,mixed>|null 最新一次分析的四件产出（作品详情页用） */
    public function loadLatestArtifacts(int $workId): ?array
    {
        $latest = $this->latestForWork($workId);
        if ($latest === null) {
            return null;
        }

        return $this->loadArtifacts((int) $latest['id']) + ['analysis' => $latest];
    }

    // ------------------------------------------------------- 阶段原始响应（重跑用）

    /**
     * 读某个阶段的**原始 JSON**（storage/artifacts/{id}/{stage}.json）。
     *
     * 为什么重跑不读 loadArtifacts：那是"组装后的四件产出"，形状已经被 S6 改写过
     * （索引词从 {canonical, aliases, …} 变成了 {canonical, role, weight, …}）。
     * 重跑 S4/S5 要的是**阶段当时吃进去的形状**，否则提示词里的前序结果会对不上。
     */
    public function loadRaw(int $analysisId, string $stageCode): ?array
    {
        $path = $this->artifactDir . '/' . $analysisId . '/' . $stageCode . '.json';
        if (!is_file($path)) {
            return null;
        }

        $json = @file_get_contents($path);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) && isset($data['data']) && is_array($data['data']) ? $data['data'] : null;
    }

    // ------------------------------------------------------------- 发布 / 下线

    /**
     * 发布一次分析：它成为这篇作品的"当前版本"，同作品其它已发布版本转为 superseded。
     *
     * 三条同时写：分析状态、作品指针（current_analysis_id）、作品状态。
     * 分开写就会出现「作品显示已发布，但前台读不到当前版本」的中间态。
     */
    public function publish(int $analysisId, int $workId, int $actorId): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE hq_analysis
                    SET status = \'superseded\', is_current = 0, updated_at = NOW()
                  WHERE work_id = :work AND id <> :id AND status = \'published\''
            )->execute([':work' => $workId, ':id' => $analysisId]);

            $this->pdo->prepare(
                'UPDATE hq_analysis
                    SET status = \'published\', is_current = 1, published_at = NOW(),
                        published_by = :actor, updated_at = NOW()
                  WHERE id = :id'
            )->execute([':actor' => $actorId, ':id' => $analysisId]);

            $this->pdo->prepare(
                'UPDATE hq_work
                    SET status = \'published\', current_analysis_id = :aid,
                        published_at = COALESCE(published_at, NOW()), updated_at = NOW()
                  WHERE id = :work'
            )->execute([':aid' => $analysisId, ':work' => $workId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** 下线：分析退回 succeeded（产物还在，只是不再对外），作品回到已分析 */
    public function unpublish(int $analysisId, int $workId): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE hq_analysis SET status = \'succeeded\', is_current = 0, updated_at = NOW() WHERE id = :id'
            )->execute([':id' => $analysisId]);

            $this->pdo->prepare(
                'UPDATE hq_work
                    SET status = \'analyzed\', current_analysis_id = NULL, published_at = NULL, updated_at = NOW()
                  WHERE id = :work AND (current_analysis_id = :aid OR current_analysis_id IS NULL)'
            )->execute([':work' => $workId, ':aid' => $analysisId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // --------------------------------------------------------- 产出快照（留痕）

    /**
     * 给四件产出拍一张快照（hq_analysis_revision）。
     *
     * 编辑与重跑都会改变产出，没有快照就无法回答"是谁把这条建议删掉的"。
     * version_no 由库内最大值 +1 得到，并靠 (analysis_id, version_no) 的唯一性兜住并发。
     *
     * **快照一律拍在改动之前**（调用方负责顺序），所以每一版存的是「那次改动之前的样子」，
     * 回滚任意一版都等于撤销对应那一次操作。拍在改动之后的话，被覆盖掉的旧值没有副本。
     *
     * $markEdited：编辑（含归并、加删条目）走默认 true，把 source 记成 mixed ——
     * 界面上「来源」那一栏才不会在人工改过二十次之后还写着"模型生成"。
     * 发布不是编辑，调用方传 false。
     */
    public function snapshot(int $analysisId, int $editorId, string $note, ?array $payload = null, bool $markEdited = true): int
    {
        $data = $payload ?? $this->loadArtifacts($analysisId);

        $next = $this->pdo->prepare('SELECT COALESCE(MAX(version_no), 0) + 1 FROM hq_analysis_revision WHERE analysis_id = :aid');
        $next->execute([':aid' => $analysisId]);
        $versionNo = (int) $next->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_analysis_revision (analysis_id, version_no, snapshot, editor_id, note, created_at)
             VALUES (:aid, :ver, :snap, :editor, :note, NOW())'
        );
        $stmt->execute([
            ':aid'    => $analysisId,
            ':ver'    => $versionNo,
            ':snap'   => (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':editor' => $editorId,
            ':note'   => mb_substr($note, 0, 200),
        ]);

        if ($markEdited) {
            // 只在还是纯 'ai' 时翻牌：人工撰写过的（human）不该被降级成 mixed
            $this->pdo->prepare(
                "UPDATE hq_analysis SET source = 'mixed', updated_at = NOW() WHERE id = :aid AND source = 'ai'"
            )->execute([':aid' => $analysisId]);
        }

        return $versionNo;
    }

    /** @return list<array<string,mixed>> */
    public function snapshots(int $analysisId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.version_no, r.note, r.created_at, u.username AS editor
               FROM hq_analysis_revision r
               LEFT JOIN hq_user u ON u.id = r.editor_id
              WHERE r.analysis_id = :aid
              ORDER BY r.version_no DESC'
        );
        $stmt->execute([':aid' => $analysisId]);

        return $stmt->fetchAll();
    }

    /**
     * 把若干个阶段标为过期（单阶段重跑、下游没有跟着重跑时用）。
     *
     * @param list<string> $stageCodes
     */
    public function markStale(int $analysisId, array $stageCodes): void
    {
        if ($stageCodes === []) {
            return;
        }

        $in = implode(',', array_fill(0, count($stageCodes), '?'));
        $sql = 'UPDATE hq_analysis_stage SET status = \'stale\', updated_at = NOW()
                 WHERE analysis_id = ? AND stage_code IN (' . $in . ')
                   AND status <> \'running\'';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$analysisId], array_values($stageCodes)));
    }

    /** 重跑后刷新"这次是用哪版提示词、按哪个输入哈希跑的" */
    public function updateRunMeta(int $analysisId, string $promptVersion, string $inputHash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE hq_analysis SET prompt_version = :pver, input_hash = :hash, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':pver' => $promptVersion, ':hash' => $inputHash, ':id' => $analysisId]);
    }

    // ------------------------------------------------- 四件产出的就地编辑（M4）

    /**
     * 改一条建议的正文（标题 / 问题 / 影响）。
     *
     * 只允许改这三个字段：层级（structure/execution/detail）与序号是结构信息，
     * 结构由模型给，人改结构应当走"重跑 S4"，而不是在界面上把一条执行层建议偷偷改成结构层。
     *
     * @param array{title?:string, problem?:string, impact?:string} $fields
     */
    public function updateSuggestion(int $suggestionId, int $analysisId, array $fields): void
    {
        $sets = [];
        $params = [':id' => $suggestionId, ':aid' => $analysisId];

        if (isset($fields['title'])) {
            $sets[] = 'title = :title';
            $params[':title'] = mb_substr(trim($fields['title']), 0, 160);
        }
        if (isset($fields['problem'])) {
            $sets[] = 'problem = :problem';
            $params[':problem'] = trim($fields['problem']);
        }
        if (isset($fields['impact'])) {
            $sets[] = 'impact = :impact';
            $params[':impact'] = mb_substr(trim($fields['impact']), 0, 300);
        }

        if ($sets === []) {
            throw new RuntimeException('没有要更新的字段');
        }

        $sql = 'UPDATE hq_suggestion SET ' . implode(', ', $sets) . ', updated_at = NOW()
                 WHERE id = :id AND analysis_id = :aid';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('这条建议不属于该分析');
        }
    }

    /**
     * 改一条修改方向（两种走法 / 影响面 / 决定备注）。
     *
     * @param array{conservative?:string, radical?:string, impact_scope?:string, decision_note?:string} $fields
     */
    public function updateRevisionItem(int $analysisId, int $itemId, array $fields): void
    {
        $sets = [];
        $params = [':id' => $itemId, ':aid' => $analysisId];

        foreach ([
            'conservative'  => 500,
            'radical'       => 500,
            'impact_scope'  => 300,
            'decision_note' => 300,
        ] as $column => $limit) {
            if (isset($fields[$column])) {
                $sets[] = $column . ' = :' . $column;
                $params[':' . $column] = mb_substr(trim($fields[$column]), 0, $limit);
            }
        }

        if ($sets === []) {
            throw new RuntimeException('没有要更新的字段');
        }

        $sql = 'UPDATE hq_revision_item i
                  JOIN hq_revision r ON r.id = i.revision_id
                   SET ' . implode(', ', $sets) . '
                 WHERE i.id = :id AND r.analysis_id = :aid';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('这条修改方向不属于该分析');
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 四件产出的人工增删改
    //
    // 这一整段的共同前提：**一次 AI 调用都没有**。它们只写库，所以单次编辑的现金
    // 成本是 0 元 —— 和「单阶段重跑」完全是两回事（重跑要再付一次模型钱）。
    // 调用方（WorkController）负责在改动前拍快照、改完失效前台缓存与检索索引。
    // ═══════════════════════════════════════════════════════════════════════

    /** 节点阶段只认这四档；写错不该写入、也不该整条拒绝，退回 turn。 */
    private function phaseOr(mixed $phase): string
    {
        $phase = (string) $phase;

        return in_array($phase, ['start', 'turn', 'crisis', 'end'], true) ? $phase : 'turn';
    }

    private function beatBelongs(int $analysisId, int $beatId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM hq_plot_beat WHERE id = :id AND analysis_id = :aid LIMIT 1');
        $stmt->execute([':id' => $beatId, ':aid' => $analysisId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * 改索引卡（一句话定位 / 母题说明）。
     *
     * 用 upsert 而不是 update：模型偶尔整套产出残缺（中途失败、S6 只写了一半），
     * 这时候人工补一句话不该被「表里没这行」挡在门外。
     */
    public function updateIndexCard(int $analysisId, string $oneLiner, string $themeNote): void
    {
        if (trim($oneLiner) === '') {
            throw new RuntimeException('一句话定位不能为空');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_index_card (analysis_id, one_liner, theme_note, created_at, updated_at)
             VALUES (:aid, :one, :note, NOW(), NOW())
             ON DUPLICATE KEY UPDATE one_liner = VALUES(one_liner), theme_note = VALUES(theme_note), updated_at = NOW()'
        );
        $stmt->execute([
            ':aid'  => $analysisId,
            ':one'  => mb_substr(trim($oneLiner), 0, 300),
            ':note' => mb_substr(trim($themeNote), 0, 500),
        ]);
    }

    /**
     * 改剧情三句话（谁 / 要什么 / 付出什么 / 三句合一）。
     *
     * @param array{who?:string,want?:string,cost?:string,logline?:string} $fields
     */
    public function updatePlotSummary(int $analysisId, array $fields): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_plot_summary (analysis_id, who, want, cost, logline, created_at, updated_at)
             VALUES (:aid, :who, :want, :cost, :log, NOW(), NOW())
             ON DUPLICATE KEY UPDATE who = VALUES(who), want = VALUES(want), cost = VALUES(cost),
                                     logline = VALUES(logline), updated_at = NOW()'
        );
        $stmt->execute([
            ':aid'  => $analysisId,
            ':who'  => mb_substr(trim((string) ($fields['who'] ?? '')), 0, 300),
            ':want' => mb_substr(trim((string) ($fields['want'] ?? '')), 0, 300),
            ':cost' => mb_substr(trim((string) ($fields['cost'] ?? '')), 0, 300),
            ':log'  => mb_substr(trim((string) ($fields['logline'] ?? '')), 0, 300),
        ]);
    }

    /**
     * 在节点轴末尾补一个节点。
     *
     * seq 由库内最大值 +1 得到，不接受前端传入：uk_analysis_seq 撞键会让整次保存失败，
     * 而用户只是点了「加一个节点」。要调整顺序，用 position_pct。
     *
     * @param array{phase?:string,title?:string,detail?:string,quote?:string,position_pct?:int} $fields
     */
    public function createBeat(int $analysisId, array $fields): int
    {
        $title = trim((string) ($fields['title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('节点标题不能为空');
        }

        $next = $this->pdo->prepare('SELECT COALESCE(MAX(seq), 0) + 1 FROM hq_plot_beat WHERE analysis_id = :aid');
        $next->execute([':aid' => $analysisId]);
        $seq = (int) $next->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_plot_beat (analysis_id, seq, phase, title, detail, quote, position_pct, created_at)
             VALUES (:aid, :seq, :phase, :title, :detail, :quote, :pct, NOW())'
        );
        $stmt->execute([
            ':aid'    => $analysisId,
            ':seq'    => $seq,
            ':phase'  => $this->phaseOr($fields['phase'] ?? 'turn'),
            ':title'  => mb_substr($title, 0, 120),
            ':detail' => trim((string) ($fields['detail'] ?? '')),
            ':quote'  => mb_substr(trim((string) ($fields['quote'] ?? '')), 0, 300),
            ':pct'    => max(0, min(100, (int) ($fields['position_pct'] ?? 0))),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * 改一个节点。
     *
     * @param array{phase?:string,title?:string,detail?:string,quote?:string,position_pct?:int} $fields
     */
    public function updateBeat(int $analysisId, int $beatId, array $fields): void
    {
        if (!$this->beatBelongs($analysisId, $beatId)) {
            throw new RuntimeException('这个节点不属于该分析');
        }

        $sets   = [];
        $params = [':id' => $beatId];

        if (isset($fields['phase'])) {
            $sets[] = 'phase = :phase';
            $params[':phase'] = $this->phaseOr($fields['phase']);
        }
        if (isset($fields['title'])) {
            $title = trim((string) $fields['title']);
            if ($title === '') {
                throw new RuntimeException('节点标题不能为空');
            }
            $sets[] = 'title = :title';
            $params[':title'] = mb_substr($title, 0, 120);
        }
        if (isset($fields['detail'])) {
            $sets[] = 'detail = :detail';
            $params[':detail'] = trim((string) $fields['detail']);
        }
        if (isset($fields['quote'])) {
            $sets[] = 'quote = :quote';
            $params[':quote'] = mb_substr(trim((string) $fields['quote']), 0, 300);
        }
        if (isset($fields['position_pct'])) {
            $sets[] = 'position_pct = :pct';
            $params[':pct'] = max(0, min(100, (int) $fields['position_pct']));
        }

        if ($sets === []) {
            throw new RuntimeException('没有要更新的字段');
        }

        $this->pdo->prepare('UPDATE hq_plot_beat SET ' . implode(', ', $sets) . ' WHERE id = :id')
            ->execute($params);
    }

    /**
     * 删一个节点。
     *
     * 伏笔的两端存的是节点 id，节点没了必须一起清掉 —— 否则页面上那一行会渲染成
     * 「→ 」后面接空白，而且没有任何地方会报错。清完之后重算伏笔状态、重排 seq，
     * 让节点轴还是 1..n 连号。
     */
    public function deleteBeat(int $analysisId, int $beatId): void
    {
        if (!$this->beatBelongs($analysisId, $beatId)) {
            throw new RuntimeException('这个节点不属于该分析');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE hq_foreshadow SET payoff_beat_id = NULL WHERE payoff_beat_id = :id')
                ->execute([':id' => $beatId]);
            $this->pdo->prepare('UPDATE hq_foreshadow SET setup_beat_id = NULL WHERE setup_beat_id = :id')
                ->execute([':id' => $beatId]);
            $this->pdo->prepare('DELETE FROM hq_plot_beat WHERE id = :id')->execute([':id' => $beatId]);

            $this->resequenceBeats($analysisId);
            $this->refreshForeshadowState($analysisId);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** 把节点按现有顺序重排成 1..n（删掉中间一节后补上的空位由后面逐个前移填掉） */
    private function resequenceBeats(int $analysisId): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM hq_plot_beat WHERE analysis_id = :aid ORDER BY seq');
        $stmt->execute([':aid' => $analysisId]);
        $upd = $this->pdo->prepare('UPDATE hq_plot_beat SET seq = :seq WHERE id = :id');

        $i = 0;
        foreach ($stmt->fetchAll() as $row) {
            $i++;
            $upd->execute([':seq' => $i, ':id' => (int) $row['id']]);
        }
    }

    /**
     * 按两端的节点重算伏笔状态。
     *
     * planted / paid / open 是从 setup_beat_id、payoff_beat_id 推出来的，不是独立字段；
     * 手改状态会和实际挂接对不上，所以这里统一由结构反推。
     */
    private function refreshForeshadowState(int $analysisId): void
    {
        $this->pdo->prepare(
            "UPDATE hq_foreshadow
                SET state = CASE WHEN payoff_beat_id IS NOT NULL THEN 'paid'
                                 WHEN setup_beat_id IS NOT NULL THEN 'planted'
                                 ELSE 'open' END
              WHERE analysis_id = :aid"
        )->execute([':aid' => $analysisId]);
    }

    /**
     * 补一条伏笔。
     *
     * @param array{content?:string,setup_beat_id?:int|string|null,payoff_beat_id?:int|string|null} $fields
     */
    public function createForeshadow(int $analysisId, array $fields): int
    {
        $content = trim((string) ($fields['content'] ?? ''));
        if ($content === '') {
            throw new RuntimeException('伏笔内容不能为空');
        }

        $setup  = (int) ($fields['setup_beat_id'] ?? 0) ?: null;
        $payoff = (int) ($fields['payoff_beat_id'] ?? 0) ?: null;

        if ($setup !== null && !$this->beatBelongs($analysisId, $setup)) {
            throw new RuntimeException('埋下的节点不属于该分析');
        }
        if ($payoff !== null && !$this->beatBelongs($analysisId, $payoff)) {
            throw new RuntimeException('回收的节点不属于该分析');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_foreshadow (analysis_id, content, setup_beat_id, payoff_beat_id, state, created_at)
             VALUES (:aid, :content, :setup, :payoff, :state, NOW())'
        );
        $stmt->execute([
            ':aid'     => $analysisId,
            ':content' => mb_substr($content, 0, 300),
            ':setup'   => $setup,
            ':payoff'  => $payoff,
            ':state'   => $payoff !== null ? 'paid' : ($setup !== null ? 'planted' : 'open'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * 改一条伏笔。
     *
     * @param array{content?:string,setup_beat_id?:int|string|null,payoff_beat_id?:int|string|null} $fields
     */
    public function updateForeshadow(int $analysisId, int $foreshadowId, array $fields): void
    {
        $owns = $this->pdo->prepare('SELECT 1 FROM hq_foreshadow WHERE id = :id AND analysis_id = :aid LIMIT 1');
        $owns->execute([':id' => $foreshadowId, ':aid' => $analysisId]);
        if ($owns->fetchColumn() === false) {
            throw new RuntimeException('这条伏笔不属于该分析');
        }

        $sets   = [];
        $params = [':id' => $foreshadowId];

        if (isset($fields['content'])) {
            $content = trim((string) $fields['content']);
            if ($content === '') {
                throw new RuntimeException('伏笔内容不能为空');
            }
            $sets[] = 'content = :content';
            $params[':content'] = mb_substr($content, 0, 300);
        }
        if (array_key_exists('setup_beat_id', $fields)) {
            $setup = (int) ($fields['setup_beat_id'] ?? 0) ?: null;
            if ($setup !== null && !$this->beatBelongs($analysisId, $setup)) {
                throw new RuntimeException('埋下的节点不属于该分析');
            }
            $sets[] = 'setup_beat_id = :setup';
            $params[':setup'] = $setup;
        }
        if (array_key_exists('payoff_beat_id', $fields)) {
            $payoff = (int) ($fields['payoff_beat_id'] ?? 0) ?: null;
            if ($payoff !== null && !$this->beatBelongs($analysisId, $payoff)) {
                throw new RuntimeException('回收的节点不属于该分析');
            }
            $sets[] = 'payoff_beat_id = :payoff';
            $params[':payoff'] = $payoff;
        }

        if ($sets === []) {
            throw new RuntimeException('没有要更新的字段');
        }

        // 状态不由表单直接写：先更新两端，再让结构把状态推出来
        $this->pdo->prepare('UPDATE hq_foreshadow SET ' . implode(', ', $sets) . ' WHERE id = :id')
            ->execute($params);
        $this->refreshForeshadowState($analysisId);
    }

    public function deleteForeshadow(int $analysisId, int $foreshadowId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM hq_foreshadow WHERE id = :id AND analysis_id = :aid');
        $stmt->execute([':id' => $foreshadowId, ':aid' => $analysisId]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('这条伏笔不属于该分析');
        }
    }

    /**
     * 手工补一条建议。
     *
     * 层级沿用模型的三分法，seq 在层内自增。priority 用层内序号 —— 它只用来排序，
     * 不是评分，所以人工新增的建议不会因为"没打分"排到最后。
     *
     * @param array{layer?:string,title?:string,problem?:string,impact?:string} $fields
     */
    public function createSuggestion(int $analysisId, array $fields): int
    {
        $title   = trim((string) ($fields['title'] ?? ''));
        $problem = trim((string) ($fields['problem'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('建议标题不能为空');
        }
        if ($problem === '') {
            throw new RuntimeException('建议的「问题」不能为空');
        }

        $layer = (string) ($fields['layer'] ?? 'execution');
        if (!in_array($layer, ['structure', 'execution', 'detail'], true)) {
            $layer = 'execution';
        }

        $next = $this->pdo->prepare('SELECT COALESCE(MAX(seq), 0) + 1 FROM hq_suggestion WHERE analysis_id = :aid AND layer = :layer');
        $next->execute([':aid' => $analysisId, ':layer' => $layer]);
        $seq = (int) $next->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_suggestion (analysis_id, layer, seq, title, problem, impact, priority, created_at, updated_at)
             VALUES (:aid, :layer, :seq, :title, :problem, :impact, :prio, NOW(), NOW())'
        );
        $stmt->execute([
            ':aid'     => $analysisId,
            ':layer'   => $layer,
            ':seq'     => $seq,
            ':title'   => mb_substr($title, 0, 160),
            ':problem' => $problem,
            ':impact'  => mb_substr(trim((string) ($fields['impact'] ?? '')), 0, 300),
            ':prio'    => $seq,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** 删一条建议（连带它的路径，避免孤儿行） */
    public function deleteSuggestion(int $analysisId, int $suggestionId): void
    {
        $owns = $this->pdo->prepare('SELECT 1 FROM hq_suggestion WHERE id = :id AND analysis_id = :aid LIMIT 1');
        $owns->execute([':id' => $suggestionId, ':aid' => $analysisId]);
        if ($owns->fetchColumn() === false) {
            throw new RuntimeException('这条建议不属于该分析');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM hq_suggestion_path WHERE suggestion_id = :sid')
                ->execute([':sid' => $suggestionId]);
            $this->pdo->prepare('DELETE FROM hq_suggestion WHERE id = :id')->execute([':id' => $suggestionId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * 给建议补一条路径（保守 / 激进 / 折中）。
     *
     * @param array{style?:string,content?:string} $fields
     */
    public function createSuggestionPath(int $analysisId, int $suggestionId, array $fields): int
    {
        $owns = $this->pdo->prepare('SELECT 1 FROM hq_suggestion WHERE id = :id AND analysis_id = :aid LIMIT 1');
        $owns->execute([':id' => $suggestionId, ':aid' => $analysisId]);
        if ($owns->fetchColumn() === false) {
            throw new RuntimeException('这条建议不属于该分析');
        }

        $content = trim((string) ($fields['content'] ?? ''));
        if ($content === '') {
            throw new RuntimeException('路径内容不能为空');
        }

        $style = (string) ($fields['style'] ?? 'neutral');
        if (!in_array($style, ['conservative', 'radical', 'neutral'], true)) {
            $style = 'neutral';
        }

        $next = $this->pdo->prepare('SELECT COALESCE(MAX(seq), 0) + 1 FROM hq_suggestion_path WHERE suggestion_id = :sid');
        $next->execute([':sid' => $suggestionId]);
        $seq = (int) $next->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_suggestion_path (suggestion_id, seq, style, content, created_at)
             VALUES (:sid, :seq, :style, :content, NOW())'
        );
        $stmt->execute([
            ':sid'     => $suggestionId,
            ':seq'     => $seq,
            ':style'   => $style,
            ':content' => mb_substr($content, 0, 500),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * 改一条路径。路径没有 analysis_id，靠 join 回建议再回分析来圈定归属。
     *
     * @param array{style?:string,content?:string} $fields
     */
    public function updateSuggestionPath(int $analysisId, int $pathId, array $fields): void
    {
        $sets   = [];
        $params = [':id' => $pathId, ':aid' => $analysisId];

        if (isset($fields['style'])) {
            $style = (string) $fields['style'];
            $sets[] = 'p.style = :style';
            $params[':style'] = in_array($style, ['conservative', 'radical', 'neutral'], true) ? $style : 'neutral';
        }
        if (isset($fields['content'])) {
            $content = trim((string) $fields['content']);
            if ($content === '') {
                throw new RuntimeException('路径内容不能为空');
            }
            $sets[] = 'p.content = :content';
            $params[':content'] = mb_substr($content, 0, 500);
        }

        if ($sets === []) {
            throw new RuntimeException('没有要更新的字段');
        }

        $sql = 'UPDATE hq_suggestion_path p
                  JOIN hq_suggestion s ON s.id = p.suggestion_id
                   SET ' . implode(', ', $sets) . '
                 WHERE p.id = :id AND s.analysis_id = :aid';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('这条路径不属于该分析');
        }
    }

    public function deleteSuggestionPath(int $analysisId, int $pathId): void
    {
        $this->pdo->prepare(
            'DELETE p FROM hq_suggestion_path p
               JOIN hq_suggestion s ON s.id = p.suggestion_id
              WHERE p.id = :id AND s.analysis_id = :aid'
        )->execute([':id' => $pathId, ':aid' => $analysisId]);
    }

    /**
     * 补一条修改方向（按影响面排在最后）。
     *
     * 改动作目标（target）与做法（action）在界面上也能改 —— 人工加的这一条如果不给
     * 这两栏，就会变成"只有两种走法、不知道改哪里"的半条记录。
     *
     * @param array{target?:string,action?:string,conservative?:string,radical?:string,impact_scope?:string,decision_note?:string} $fields
     */
    public function createRevisionItem(int $analysisId, array $fields): int
    {
        $target = trim((string) ($fields['target'] ?? ''));
        $action = trim((string) ($fields['action'] ?? ''));
        if ($target === '' || $action === '') {
            throw new RuntimeException('「改哪里」和「怎么改」都要填');
        }

        $this->pdo->beginTransaction();
        try {
            $rev = $this->pdo->prepare('SELECT id FROM hq_revision WHERE analysis_id = :aid LIMIT 1');
            $rev->execute([':aid' => $analysisId]);
            $revId = $rev->fetchColumn();

            if ($revId === false) {
                $this->pdo->prepare(
                    'INSERT INTO hq_revision (analysis_id, summary, created_at, updated_at) VALUES (:aid, :summary, NOW(), NOW())'
                )->execute([':aid' => $analysisId, ':summary' => '']);
                $revId = (int) $this->pdo->lastInsertId();
            }

            $next = $this->pdo->prepare('SELECT COALESCE(MAX(rank_no), 0) + 1 FROM hq_revision_item WHERE revision_id = :rid');
            $next->execute([':rid' => (int) $revId]);
            $rank = (int) $next->fetchColumn();

            $stmt = $this->pdo->prepare(
                'INSERT INTO hq_revision_item
                    (revision_id, rank_no, target, action, conservative, radical, impact_scope, decision_note, created_at)
                 VALUES (:rid, :rank, :target, :action, :con, :rad, :impact, :note, NOW())'
            );
            $stmt->execute([
                ':rid'    => (int) $revId,
                ':rank'   => $rank,
                ':target' => mb_substr($target, 0, 200),
                ':action' => mb_substr($action, 0, 300),
                ':con'    => mb_substr(trim((string) ($fields['conservative'] ?? '')), 0, 500),
                ':rad'    => mb_substr(trim((string) ($fields['radical'] ?? '')), 0, 500),
                ':impact' => mb_substr(trim((string) ($fields['impact_scope'] ?? '')), 0, 300),
                ':note'   => mb_substr(trim((string) ($fields['decision_note'] ?? '')), 0, 300),
            ]);

            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();

            return $id;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** 删一条修改方向，并把后面的名次前移（否则会留下第 3、第 5 位这种断号） */
    public function deleteRevisionItem(int $analysisId, int $itemId): void
    {
        $owns = $this->pdo->prepare(
            'SELECT i.revision_id FROM hq_revision_item i
               JOIN hq_revision r ON r.id = i.revision_id
              WHERE i.id = :id AND r.analysis_id = :aid LIMIT 1'
        );
        $owns->execute([':id' => $itemId, ':aid' => $analysisId]);
        $revId = $owns->fetchColumn();

        if ($revId === false) {
            throw new RuntimeException('这条修改方向不属于该分析');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM hq_revision_item WHERE id = :id')->execute([':id' => $itemId]);

            $rows = $this->pdo->prepare('SELECT id FROM hq_revision_item WHERE revision_id = :rid ORDER BY rank_no');
            $rows->execute([':rid' => (int) $revId]);
            $upd = $this->pdo->prepare('UPDATE hq_revision_item SET rank_no = :rank WHERE id = :id');

            $i = 0;
            foreach ($rows->fetchAll() as $row) {
                $i++;
                $upd->execute([':rank' => $i, ':id' => (int) $row['id']]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * 改读者视角（S7）。
     *
     * 看点（appeal）是一组 {title, why}，界面按三条输入，这里重新拼回 JSON：
     * 空行直接丢掉，不写 "   " 这种占位，否则前台会渲染出三个空标题。
     *
     * @param array{hook?:string,appeal?:list<array{title?:string,why?:string}>,
     *              visual?:array<string,string>,audience?:array<string,string>,terms_note?:string} $fields
     */
    public function updateReaderBrief(int $analysisId, array $fields): void
    {
        $appeal = [];
        foreach ((array) ($fields['appeal'] ?? []) as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            $why   = trim((string) ($row['why'] ?? ''));
            if ($title === '' && $why === '') {
                continue;
            }
            $appeal[] = ['title' => $title, 'why' => $why];
        }

        $visual   = is_array($fields['visual'] ?? null) ? $fields['visual'] : [];
        $audience = is_array($fields['audience'] ?? null) ? $fields['audience'] : [];

        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_reader_brief
                (analysis_id, hook, appeal_json, visual_tone, visual_lens, visual_ref, visual_scene,
                 audience_primary, audience_also, audience_avoid, audience_gate, terms_note, created_at, updated_at)
             VALUES (:aid, :hook, :appeal, :tone, :lens, :ref, :scene, :primary, :also, :avoid, :gate, :note, NOW(), NOW())
             ON DUPLICATE KEY UPDATE hook = VALUES(hook), appeal_json = VALUES(appeal_json),
                 visual_tone = VALUES(visual_tone), visual_lens = VALUES(visual_lens),
                 visual_ref = VALUES(visual_ref), visual_scene = VALUES(visual_scene),
                 audience_primary = VALUES(audience_primary), audience_also = VALUES(audience_also),
                 audience_avoid = VALUES(audience_avoid), audience_gate = VALUES(audience_gate),
                 terms_note = VALUES(terms_note), updated_at = NOW()'
        );
        $stmt->execute([
            ':aid'     => $analysisId,
            ':hook'    => mb_substr(trim((string) ($fields['hook'] ?? '')), 0, 300),
            ':appeal'  => json_encode($appeal, JSON_UNESCAPED_UNICODE),
            ':tone'    => mb_substr(trim((string) ($visual['tone'] ?? '')), 0, 300),
            ':lens'    => mb_substr(trim((string) ($visual['lens'] ?? '')), 0, 300),
            ':ref'     => mb_substr(trim((string) ($visual['reference'] ?? '')), 0, 400),
            ':scene'   => mb_substr(trim((string) ($visual['scene'] ?? '')), 0, 400),
            ':primary' => mb_substr(trim((string) ($audience['primary'] ?? '')), 0, 300),
            ':also'    => mb_substr(trim((string) ($audience['also'] ?? '')), 0, 300),
            ':avoid'   => mb_substr(trim((string) ($audience['avoid'] ?? '')), 0, 300),
            ':gate'    => mb_substr(trim((string) ($audience['gate'] ?? '')), 0, 300),
            ':note'    => mb_substr(trim((string) ($fields['terms_note'] ?? '')), 0, 300),
        ]);
    }

    /**
     * 把四件产出回滚到某一版快照。
     *
     * 这是「编辑零成本」的保险：删错一条建议、改坏一句话，都能退回改动之前。
     * 快照存的是 loadArtifacts() 的形状（组装后的成品），所以重建时按这个形状读回去，
     * 而不是走 saveArtifacts() 那条吃阶段原始 JSON 的路。
     *
     * 自增 id 回不去，所以所有引用都按**业务键**重挂：
     *   · 节点按 seq（先插节点，拿到 seq → 新 id 的映射）
     *   · 伏笔的埋下/回收按 seq 查映射
     *   · 索引词按 canonical 查当前词表（词条已被归并或删掉的，跳过并报告）
     *   · 建议路径按所属建议、修改方向条目按 rank_no
     *
     * @return array{beats:int,foreshadows:int,suggestions:int,revision_items:int,terms:int,skipped_terms:list<string>}
     */
    public function restoreSnapshot(int $analysisId, int $workId, int $versionNo): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT snapshot FROM hq_analysis_revision WHERE analysis_id = :aid AND version_no = :ver LIMIT 1'
        );
        $stmt->execute([':aid' => $analysisId, ':ver' => $versionNo]);
        $json = $stmt->fetchColumn();

        if ($json === false) {
            throw new RuntimeException(sprintf('找不到 v%d 这一版快照', $versionNo));
        }

        $data = json_decode((string) $json, true);
        if (!is_array($data)) {
            throw new RuntimeException('这一版快照解不开（JSON 损坏）');
        }

        $stat = ['beats' => 0, 'foreshadows' => 0, 'suggestions' => 0, 'revision_items' => 0, 'terms' => 0, 'skipped_terms' => []];

        $this->pdo->beginTransaction();
        try {
            $this->clearArtifacts($analysisId, $workId);

            // 1. 索引卡
            $card = $data['index_card'] ?? null;
            if (is_array($card)) {
                $this->pdo->prepare(
                    'INSERT INTO hq_index_card (analysis_id, one_liner, theme_note, created_at, updated_at)
                     VALUES (:aid, :one, :note, NOW(), NOW())'
                )->execute([
                    ':aid'  => $analysisId,
                    ':one'  => mb_substr((string) ($card['one_liner'] ?? ''), 0, 300),
                    ':note' => mb_substr((string) ($card['theme_note'] ?? ''), 0, 500),
                ]);
            }

            // 2. 索引词：按 canonical 重挂，不重建词条本身
            $termLookup = $this->pdo->prepare('SELECT id FROM hq_index_term WHERE canonical = :c LIMIT 1');
            $insTerm    = $this->pdo->prepare(
                'INSERT INTO hq_work_term (work_id, term_id, role, weight, evidence, analysis_id, created_at)
                 VALUES (:work, :tid, :role, :weight, :ev, :aid, NOW())'
            );
            foreach ((array) ($data['terms'] ?? []) as $t) {
                $canonical = (string) ($t['canonical'] ?? '');
                $termLookup->execute([':c' => $canonical]);
                $termId = $termLookup->fetchColumn();

                if ($termId === false) {
                    $stat['skipped_terms'][] = $canonical;
                    continue;
                }

                $insTerm->execute([
                    ':work'   => $workId,
                    ':tid'    => (int) $termId,
                    ':role'   => ($t['role'] ?? 'sub') === 'main' ? 'main' : 'sub',
                    ':weight' => max(1, min(100, (int) ($t['weight'] ?? 50))),
                    ':ev'     => mb_substr((string) ($t['evidence'] ?? ''), 0, 200),
                    ':aid'    => $analysisId,
                ]);
                $stat['terms']++;
            }

            // 3. 剧情三句话
            $summary = $data['summary'] ?? null;
            if (is_array($summary)) {
                $this->pdo->prepare(
                    'INSERT INTO hq_plot_summary (analysis_id, who, want, cost, logline, created_at, updated_at)
                     VALUES (:aid, :who, :want, :cost, :log, NOW(), NOW())'
                )->execute([
                    ':aid'  => $analysisId,
                    ':who'  => mb_substr((string) ($summary['who'] ?? ''), 0, 300),
                    ':want' => mb_substr((string) ($summary['want'] ?? ''), 0, 300),
                    ':cost' => mb_substr((string) ($summary['cost'] ?? ''), 0, 300),
                    ':log'  => mb_substr((string) ($summary['logline'] ?? ''), 0, 300),
                ]);
            }

            // 4. 节点轴：按 seq 排序插入，记下 seq → 新 id
            $insBeat = $this->pdo->prepare(
                'INSERT INTO hq_plot_beat (analysis_id, seq, phase, title, detail, quote, position_pct, created_at)
                 VALUES (:aid, :seq, :phase, :title, :detail, :quote, :pct, NOW())'
            );
            $beatBySeq = [];
            $beats     = (array) ($data['beats'] ?? []);
            usort($beats, static fn (array $a, array $b): int => (int) ($a['seq'] ?? 0) <=> (int) ($b['seq'] ?? 0));
            foreach ($beats as $b) {
                $insBeat->execute([
                    ':aid'    => $analysisId,
                    ':seq'    => (int) ($b['seq'] ?? 0),
                    ':phase'  => $this->phaseOr($b['phase'] ?? 'turn'),
                    ':title'  => mb_substr((string) ($b['title'] ?? ''), 0, 120),
                    ':detail' => (string) ($b['detail'] ?? ''),
                    ':quote'  => mb_substr((string) ($b['quote'] ?? ''), 0, 300),
                    ':pct'    => max(0, min(100, (int) ($b['position_pct'] ?? 0))),
                ]);
                $beatBySeq[(int) ($b['seq'] ?? 0)] = (int) $this->pdo->lastInsertId();
                $stat['beats']++;
            }

            // 5. 伏笔：两端按 seq 换成新 id
            $insFore = $this->pdo->prepare(
                'INSERT INTO hq_foreshadow (analysis_id, content, setup_beat_id, payoff_beat_id, state, created_at)
                 VALUES (:aid, :content, :setup, :payoff, :state, NOW())'
            );
            foreach ((array) ($data['foreshadows'] ?? []) as $f) {
                $setupSeq  = isset($f['setup']['seq']) ? (int) $f['setup']['seq'] : null;
                $payoffSeq = isset($f['payoff']['seq']) ? (int) $f['payoff']['seq'] : null;
                $setup     = $setupSeq === null ? null : ($beatBySeq[$setupSeq] ?? null);
                $payoff    = $payoffSeq === null ? null : ($beatBySeq[$payoffSeq] ?? null);

                $insFore->execute([
                    ':aid'     => $analysisId,
                    ':content' => mb_substr((string) ($f['content'] ?? ''), 0, 300),
                    ':setup'   => $setup,
                    ':payoff'  => $payoff,
                    ':state'   => $payoff !== null ? 'paid' : ($setup !== null ? 'planted' : 'open'),
                ]);
                $stat['foreshadows']++;
            }

            // 6. 建议与路径
            $insSug  = $this->pdo->prepare(
                'INSERT INTO hq_suggestion (analysis_id, layer, seq, title, problem, impact, priority, created_at, updated_at)
                 VALUES (:aid, :layer, :seq, :title, :problem, :impact, :prio, NOW(), NOW())'
            );
            $insPath = $this->pdo->prepare(
                'INSERT INTO hq_suggestion_path (suggestion_id, seq, style, content, created_at)
                 VALUES (:sid, :seq, :style, :content, NOW())'
            );
            $layerSeq = [];
            foreach ((array) ($data['suggestions'] ?? []) as $s) {
                $layer = (string) ($s['layer'] ?? 'execution');
                if (!in_array($layer, ['structure', 'execution', 'detail'], true)) {
                    $layer = 'execution';
                }
                $layerSeq[$layer] = ($layerSeq[$layer] ?? 0) + 1;
                $seq = $layerSeq[$layer];

                $insSug->execute([
                    ':aid'     => $analysisId,
                    ':layer'   => $layer,
                    ':seq'     => $seq,
                    ':title'   => mb_substr((string) ($s['title'] ?? ''), 0, 160),
                    ':problem' => (string) ($s['problem'] ?? ''),
                    ':impact'  => mb_substr((string) ($s['impact'] ?? ''), 0, 300),
                    ':prio'    => $seq,
                ]);
                $sid = (int) $this->pdo->lastInsertId();
                $stat['suggestions']++;

                $pseq = 0;
                foreach ((array) ($s['paths'] ?? []) as $p) {
                    $pseq++;
                    $style = (string) ($p['style'] ?? 'neutral');
                    $insPath->execute([
                        ':sid'     => $sid,
                        ':seq'     => $pseq,
                        ':style'   => in_array($style, ['conservative', 'radical', 'neutral'], true) ? $style : 'neutral',
                        ':content' => mb_substr((string) ($p['content'] ?? ''), 0, 500),
                    ]);
                }
            }

            // 7. 修改方向
            $revision = $data['revision'] ?? null;
            if (is_array($revision)) {
                $this->pdo->prepare(
                    'INSERT INTO hq_revision (analysis_id, summary, created_at, updated_at) VALUES (:aid, :summary, NOW(), NOW())'
                )->execute([':aid' => $analysisId, ':summary' => (string) ($revision['summary'] ?? '')]);
                $revId = (int) $this->pdo->lastInsertId();

                $insItem = $this->pdo->prepare(
                    'INSERT INTO hq_revision_item
                        (revision_id, rank_no, target, action, conservative, radical, impact_scope, decision_note, created_at)
                     VALUES (:rid, :rank, :target, :action, :con, :rad, :impact, :note, NOW())'
                );
                $rank = 0;
                foreach ((array) ($revision['items'] ?? []) as $item) {
                    $rank++;
                    $insItem->execute([
                        ':rid'    => $revId,
                        ':rank'   => $rank,
                        ':target' => mb_substr((string) ($item['target'] ?? ''), 0, 200),
                        ':action' => mb_substr((string) ($item['action'] ?? ''), 0, 300),
                        ':con'    => mb_substr((string) ($item['conservative'] ?? ''), 0, 500),
                        ':rad'    => mb_substr((string) ($item['radical'] ?? ''), 0, 500),
                        ':impact' => mb_substr((string) ($item['impact_scope'] ?? ''), 0, 300),
                        ':note'   => mb_substr((string) ($item['decision_note'] ?? ''), 0, 300),
                    ]);
                    $stat['revision_items']++;
                }
            }

            // 8. 读者视角
            $reader = $data['reader'] ?? null;
            if (is_array($reader)) {
                $this->updateReaderBrief($analysisId, $reader);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $stat;
    }
}
