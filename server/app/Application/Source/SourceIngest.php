<?php

declare(strict_types=1);

namespace App\Application\Source;

use App\Application\Work\WorkImportService;
use App\Infrastructure\Pdo\AuditLogger;
use App\Infrastructure\Pdo\WorkRepository;
use App\Support\Config;
use App\Support\Text;
use RuntimeException;

/**
 * 把官网上的一篇文章抓成馆内作品。
 *
 * 「一键拆解」要能一次跑完 1177 篇，就不能假设作品已经在馆里 —— 它们大多数还在官网。
 * 所以流水线的第一步是这一步：cid → 正文 → 馆内作品（含原文版本）。
 *
 * 为什么不直接自己 INSERT：导入这件事已经有一整套规则（期次补齐、作者去重、
 * 正文编码归一、sha256 去重提示、审计留痕、文件先落盘再提交事务）。抄一遍的下场是
 * 两条路对「作者同名不同人」「正文少于 500 字」给出不同答案。这里只做三件导入服务
 * 不认识的事：抓、清洗、记来源 cid。
 *
 * 匿名作品一律拒绝：揭晓前官网连作者名都是「匿名用户」，导进来就是一篇没有归属的
 * 作品，等揭晓后还要删掉重导。宁可留到揭晓之后再抓（用户也是这样要求的）。
 */
final class SourceIngest
{
    /** 正文下限：练笔规则是 3000 字，但拆解本身只要求 500；低于 500 连阶段一都过不了 */
    private const MIN_CHARS = 500;

    public function __construct(
        private readonly SourceApi $api,
        private readonly WorkRepository $works,
        private readonly WorkImportService $importer,
        private readonly AuditLogger $audit,
        private readonly Config $config,
    ) {
    }

    /**
     * @return array{work_id:int, code:string, created:bool, chars:int, title:string}
     */
    public function ingest(int $cid, int $issueNo, int $actorId): array
    {
        if ($cid <= 0) {
            throw new RuntimeException('cid 不合法');
        }

        // 幂等：同一篇被重复派发（重试、或两个批次重叠）时直接返回已有的那一篇。
        // 这一步必须在抓取之前 —— 抓一篇两万字的正文要一次请求和一次落盘。
        $existing = $this->works->findBySourceCid($cid);
        if ($existing !== null) {
            return ['work_id' => $existing['id'], 'code' => $existing['code'], 'created' => false, 'chars' => 0, 'title' => ''];
        }

        $detail = $this->api->detail($cid);
        if ($detail === null) {
            throw new RuntimeException('官网取不到这篇正文（cid ' . $cid . '），可能已被删除或改了可见性');
        }

        if ((int) ($detail['isAnonymous'] ?? 0) === 1) {
            throw new RuntimeException('匿名作品不导入：揭晓前拿不到真实作者，等揭晓后再抓（cid ' . $cid . '）');
        }

        $title  = trim((string) ($detail['title'] ?? ''));
        $author = trim((string) (($detail['userJson']['name'] ?? '')));
        if ($title === '') {
            throw new RuntimeException('官网这篇没有标题（cid ' . $cid . '）');
        }
        if ($author === '' || $author === '匿名用户') {
            throw new RuntimeException('官网这篇没有可用作者名（cid ' . $cid . '），按匿名处理不导入');
        }

        $body = self::htmlToText((string) ($detail['text'] ?? ''));
        $chars = mb_strlen($body, 'UTF-8');
        if ($chars < self::MIN_CHARS) {
            throw new RuntimeException(sprintf('正文只有 %d 字，低于拆解下限 %d（cid %d）', $chars, self::MIN_CHARS, $cid));
        }

        $issueNo = $issueNo > 0 ? $issueNo : self::issueOf($detail);
        if ($issueNo <= 0) {
            throw new RuntimeException('判不出这篇属于哪一期（cid ' . $cid . '）');
        }

        $tmp = $this->writeTemp($body, $cid);

        try {
            $result = $this->importer->importFile([
                'title'      => mb_substr($title, 0, 200),
                'author'     => mb_substr($author, 0, 64),
                'issue_no'   => $issueNo,
                'genre_code' => null,
                'tags'       => '',
                'summary'    => Text::excerpt($body, 120),
                'source'     => 'import',
            ], [
                'tmp_path'   => $tmp,
                'orig_name'  => mb_substr($title, 0, 60) . '.txt',
                'ext'        => 'txt',
                'mime'       => 'text/plain',
                'size_bytes' => (int) filesize($tmp),
            ], $actorId);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        // 来源 cid 补在导入之后：导入服务面对的是「一个文件」，它不知道官网。
        // 这一步失败必须抛出去 —— 缺 cid 的作品下一轮会被当成「还没入馆」再抓一遍。
        $this->works->setSourceCid($result['work_id'], $cid);

        $this->audit->log($actorId, 'work.ingest', 'work', $result['code'], null, [
            'cid' => $cid, 'issue_no' => $issueNo, 'chars' => $chars, 'title' => $title,
        ]);

        return ['work_id' => $result['work_id'], 'code' => $result['code'], 'created' => true, 'chars' => $chars, 'title' => $title];
    }

    /**
     * 官网正文是富文本 HTML，拆解要的是纯文本。
     *
     * 只做四件事，且顺序不能乱：先删脚本样式（否则它们的文字会混进正文）、
     * 再把块级标签换成换行（`</p>` 直接 strip 会把两段粘成一句）、最后才去标签。
     * 实体解码放在去标签之后 —— 先解码会把 `&lt;p&gt;` 变成真标签再被删掉，
     * 正文里本来写着的 `<p>` 就消失了。
     */
    public static function htmlToText(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $html = str_replace(["\r\n", "\r"], "\n", $html);
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = (string) preg_replace('#</(p|div|h[1-6]|li|blockquote|section|article|tr)\s*>#i', "\n", $html);
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);          // &nbsp;
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);

        return Text::normalizeBody($text);
    }

    /**
     * 从详情里的 active[] 判期号。
     *
     * 与目录同步用的是同一套中文数字解析（SourceCatalog::parseChineseNumber），
     * 不另写一遍 —— 两条路对「第二十六期」给出不同数字，一键拆解就会把作品排到错的期。
     *
     * @param array<string,mixed> $detail
     */
    private static function issueOf(array $detail): int
    {
        $best = 0;
        foreach (($detail['active'] ?? []) as $activity) {
            if (!is_array($activity)) {
                continue;
            }
            $name = (string) ($activity['name'] ?? '');
            if (!str_contains($name, SourceCatalog::PRACTICE_MARK)) {
                continue;
            }
            $no = SourceCatalog::parseChineseNumber($name);
            $best = max($best, $no);
        }

        return $best;
    }

    /** 临时文件落在项目内：php.ini 的 open_basedir 挡着系统临时目录（这是刻意的） */
    private function writeTemp(string $body, int $cid): string
    {
        $dir = rtrim($this->config->string('app.root'), '/\\') . '/storage/import-stage/ingest';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('临时目录创建失败：' . $dir);
        }

        $path = $dir . '/cid-' . $cid . '-' . bin2hex(random_bytes(4)) . '.txt';
        if (file_put_contents($path, $body) === false) {
            throw new RuntimeException('临时文件写入失败');
        }

        return $path;
    }
}
