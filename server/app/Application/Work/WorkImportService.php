<?php

declare(strict_types=1);

namespace App\Application\Work;

use App\Infrastructure\Import\TextExtractor;
use App\Infrastructure\Pdo\AuditLogger;
use App\Infrastructure\Pdo\WorkRepository;
use App\Infrastructure\Storage\UploadStore;
use App\Support\Text;
use App\Support\Ulid;
use PDO;
use RuntimeException;
use Throwable;

/**
 * 作品导入用例。
 *
 * 事务边界：**文件先落盘，数据库后提交**。顺序不能反——
 * 若先写库再写文件，文件写失败会留下一条指向空气的作品；反过来最多留下一个孤儿文件，
 * 由巡检脚本按 mtime 清理（孤儿文件不可怕，坏引用才可怕）。
 */
final class WorkImportService
{
    /** 单篇正文上限（字符），防止一次导入把 thinking 预算和内存吃光 */
    private const MAX_CHARS = 2_000_000;

    /**
     * 单文件上限（字节），与 php.ini 的 upload_max_filesize 对齐。
     *
     * public 是刻意的：`bin/import.php` 的 --dry 必须用同一份判据做预演。
     * 预演和实跑各写一份白名单，迟早会漂移，而漂移的那天预演就在骗人。
     */
    public const MAX_BYTES = 33_554_432;

    /** 可导入的扩展名，同样供 CLI 预演复用 */
    public const ALLOWED_EXT = ['txt', 'md', 'markdown', 'docx'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly WorkRepository $works,
        private readonly UploadStore $store,
        private readonly TextExtractor $extractor,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array{title:string, author:string, issue_no:int, genre_code:?string, tags:string,
     *              summary:?string, source:string} $meta
     * @param array{tmp_path:string, orig_name:string, ext:string, mime:string} $file
     * @return array{code:string, work_id:int, warnings:list<string>}
     */
    public function importFile(array $meta, array $file, int $actorId): array
    {
        $title  = trim($meta['title']);
        $author = trim($meta['author']);

        if ($title === '' || mb_strlen($title) > 200) {
            throw new RuntimeException('标题为空或超长（上限 200 字）');
        }
        if ($author === '' || mb_strlen($author) > 64) {
            throw new RuntimeException('作者笔名为空或超长（上限 64 字）');
        }

        $issueNo = (int) $meta['issue_no'];
        if ($issueNo < 1 || $issueNo > 999) {
            throw new RuntimeException('期次号不合法');
        }

        $ext = strtolower($file['ext']);
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            throw new RuntimeException('只接受 txt / md / docx');
        }

        $bytes = (int) ($file['size_bytes'] ?? 0);
        if ($bytes <= 0 && is_file($file['tmp_path'])) {
            $bytes = (int) filesize($file['tmp_path']);
        }
        if ($bytes > self::MAX_BYTES) {
            throw new RuntimeException('文件超过 ' . (int) round(self::MAX_BYTES / 1048576) . ' MiB 上限');
        }

        $warnings = [];
        $parsed   = $this->extractor->extract($file['tmp_path'], $ext);
        $body     = $parsed['text'];

        if ($body === '') {
            throw new RuntimeException('正文为空');
        }
        $charCount = Text::countChars($body);
        if (mb_strlen($body) > self::MAX_CHARS) {
            throw new RuntimeException('正文过长，超过单篇上限');
        }
        if ($charCount < 500) {
            $warnings[] = '正文仅 ' . $charCount . ' 字，明显低于练笔规则的下限 3000 字';
        }

        $stored = $this->store->store($file['tmp_path'], $ext);

        try {
            $dup = $this->pdo->prepare('SELECT work_id FROM hq_work_file WHERE sha256 = :sha LIMIT 1');
            $dup->execute([':sha' => $stored['sha256']]);
            $dupWorkId = $dup->fetchColumn();
            if ($dupWorkId !== false) {
                $warnings[] = '相同内容的文件已导入过（作品 ID ' . (int) $dupWorkId . '），本次仍按新作品处理';
            }

            $this->pdo->beginTransaction();

            $issueId = $this->works->ensureIssue($issueNo);
            $authorId = $this->works->findOrCreateAuthor($author);

            $genreId = null;
            $genreCode = trim((string) ($meta['genre_code'] ?? ''));
            if ($genreCode !== '') {
                $genre = $this->works->findGenreByCode($genreCode);
                if ($genre === null) {
                    throw new RuntimeException('体裁不存在');
                }
                $genreId = (int) $genre['id'];
            }

            $bundle = $this->works->createWork([
                'issue_id'   => $issueId,
                'author_id'  => $authorId,
                'genre_id'   => $genreId,
                'title'      => $title,
                'subtitle'   => null,
                'word_count' => $charCount,
                'summary'    => $meta['summary'] !== null && trim($meta['summary']) !== ''
                    ? mb_substr(trim($meta['summary']), 0, 500)
                    : Text::excerpt($body, 120),
                'status'     => 'imported',
                'source'     => in_array($meta['source'], ['manual', 'import', 'text'], true) ? $meta['source'] : 'import',
                'created_by' => $actorId,
            ]);

            $fileId = $this->works->addFile($bundle['id'], [
                'storage_key' => $stored['key'],
                'orig_name'   => mb_substr($file['orig_name'], 0, 180),
                'mime'        => mb_substr($file['mime'], 0, 100),
                'size_bytes'  => $stored['size'],
                'sha256'      => $stored['sha256'],
                'encoding'    => $parsed['encoding'],
                'char_count'  => $charCount,
            ]);

            $this->works->addVersion($bundle['id'], $fileId, 1, $stored['sha256'], $charCount, '首次导入');

            $tagRaw = (string) ($meta['tags'] ?? '');
            if ($tagRaw !== '') {
                $tags = array_values(array_filter(array_map('trim', preg_split('/[,，\s]+/u', $tagRaw) ?: [])));
                $this->works->attachTags($bundle['id'], array_slice($tags, 0, 12));
            }

            $this->audit->log($actorId, 'work.import', 'work', $bundle['code'], null, [
                'title'      => $title,
                'author'     => $author,
                'issue_no'   => $issueNo,
                'word_count' => $charCount,
                'encoding'   => $parsed['encoding'],
                'sha256'     => $stored['sha256'],
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->store->delete($stored['key']);
            throw $e;
        }

        return ['code' => $bundle['code'], 'work_id' => $bundle['id'], 'warnings' => $warnings];
    }

    /**
     * 批量导入：每个文件一篇，标题默认取文件名（去扩展名）。
     *
     * @param list<array{tmp_path:string, orig_name:string, ext:string, mime:string}> $files
     * @param array{author:string, issue_no:int, genre_code:?string, tags:string} $common
     * @return array{ok:int, failed:int, results:list<array<string, mixed>>}
     */
    public function importBatch(array $files, array $common, int $actorId): array
    {
        $batchCode = Ulid::generate();
        $batchId   = $this->works->createBatch($batchCode, 'files', null, count($files), $actorId);

        $ok     = 0;
        $failed = 0;
        $rows   = [];

        foreach ($files as $index => $file) {
            $rowNo = $index + 1;
            $title = trim((string) preg_replace('/\.(txt|md|markdown|docx)$/i', '', $file['orig_name']));

            try {
                $result = $this->importFile([
                    'title'      => $title === '' ? '未命名-' . $rowNo : $title,
                    'author'     => $common['author'],
                    'issue_no'   => $common['issue_no'],
                    'genre_code' => $common['genre_code'],
                    'tags'       => $common['tags'],
                    'summary'    => null,
                    'source'     => 'import',
                ], $file, $actorId);

                $ok++;
                $rows[] = ['row' => $rowNo, 'name' => $file['orig_name'], 'ok' => true, 'code' => $result['code'], 'warnings' => $result['warnings']];
                $this->works->addBatchRow($batchId, $rowNo, ['name' => $file['orig_name']], 'ok', $result['work_id'], null);
            } catch (Throwable $e) {
                $failed++;
                $rows[] = ['row' => $rowNo, 'name' => $file['orig_name'], 'ok' => false, 'error' => $e->getMessage()];
                $this->works->addBatchRow($batchId, $rowNo, ['name' => $file['orig_name']], 'error', null, $e->getMessage());
            }
        }

        $this->works->finishBatch($batchId, $ok, $failed);
        $this->audit->log($actorId, 'work.import_batch', 'import_batch', $batchCode, null, [
            'total' => count($files), 'ok' => $ok, 'failed' => $failed,
        ]);

        return ['ok' => $ok, 'failed' => $failed, 'results' => $rows];
    }
}
