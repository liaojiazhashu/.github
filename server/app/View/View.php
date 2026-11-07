<?php

declare(strict_types=1);

namespace App\View;

use Psr\Http\Message\ResponseInterface;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use RuntimeException;

/**
 * 模板渲染。Twig 默认自动转义，且不开启 autoescape 白名单之外的任何原始输出。
 */
final class View
{
    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(ResponseInterface $response, string $template, array $data = []): ResponseInterface
    {
        try {
            $html = $this->twig->render($template . '.twig', $data);
        } catch (LoaderError | RuntimeError | SyntaxError $e) {
            // 模板错误不能把路径与堆栈吐给浏览器
            throw new RuntimeException('模板渲染失败：' . $e->getMessage(), 0, $e);
        }

        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
