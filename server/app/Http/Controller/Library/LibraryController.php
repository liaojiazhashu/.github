<?php

declare(strict_types=1);

namespace App\Http\Controller\Library;

use App\Application\Library\PublicTextGuard;
use App\Http\Support\ErrorPage;
use App\Http\Support\PublicPageData;
use App\Infrastructure\Pdo\LibraryRepository;
use App\Support\Config;
use App\View\View;
use Psr\Http\Message\ResponseInterface;

/**
 * 公开馆藏控制器基类。
 *
 * 与后台基类（AdminController）的差别只有一条，但很关键：**这里不碰 Session**。
 * 公开页没有会话，也就没有 csrf / flash / 当前用户这些概念 —— 少掉它们之后，
 * 整页缓存才有可能（带 Set-Cookie 的响应不能进公共缓存）。
 */
abstract class LibraryController
{
    public function __construct(
        protected readonly View $view,
        protected readonly LibraryRepository $library,
        protected readonly Config $config,
        protected readonly PublicTextGuard $guard,
    ) {
    }

    /**
     * 把列表行的摘要文本过一遍遮蔽。
     *
     * 每个前台控制器都拿得到 $this->guard，是为了让「这道守卫存在」这件事无法被忘记：
     * 新增一个列表页时，构造器会强迫你面对它。
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    protected function maskRows(array $rows): array
    {
        return $this->guard->rows($rows);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function render(ResponseInterface $response, string $template, array $data = [], int $status = 200): ResponseInterface
    {
        $payload = array_merge(
            PublicPageData::build($this->library, $this->config),
            $data
        );

        return $this->view->render($response->withStatus($status), $template, $payload);
    }

    /**
     * 公开区的 404。刻意给出「回到馆藏」而不是一句冷冰冰的 Not Found ——
     * 作品下线、链接过期、手抄错一个字符，都该有一条出路。
     */
    protected function notFound(ResponseInterface $response, string $message): ResponseInterface
    {
        return $this->render($response, 'library/errors/404', [
            'page_title' => '没有这一页 · 银河图书馆',
            'noindex'    => true,
            'message'    => $message,
        ], 404);
    }

    protected function tooManyRequests(ResponseInterface $response, int $retryAfter): ResponseInterface
    {
        return ErrorPage::render(
            $response,
            429,
            '请求有点快',
            sprintf('请在 %d 秒后重试。', $retryAfter),
            '/library/works',
            '回到馆藏'
        );
    }

    /**
     * 结构化数据（application/ld+json）。
     *
     * 必须带 JSON_HEX_TAG：JSON 里出现 `</script>` 会直接闭合标签，而作品标题、作者名
     * 都是外部输入 —— 这不是理论风险，是 JSON-LD 最常见的注入点。
     *
     * @param array<string,mixed> $data
     */
    protected function jsonLd(array $data): string
    {
        return (string) json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }
}
