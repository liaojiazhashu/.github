<?php

declare(strict_types=1);

namespace App\Http\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * 中间件层的最小错误页（不依赖 Twig，避免「模板坏了连错误页都出不来」）。
 * 只输出固定文案，不反射任何用户输入。
 */
final class ErrorPage
{
    public static function render(
        ResponseInterface $response,
        int $status,
        string $title,
        string $message,
        string $backHref = '/admin',
        string $backLabel = '返回后台'
    ): ResponseInterface {
        $safeTitle   = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $safeHref    = htmlspecialchars($backHref, ENT_QUOTES, 'UTF-8');
        $safeLabel   = htmlspecialchars($backLabel, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$safeTitle} · 银河图书馆</title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
background:#0B0E14;color:#E8EDF5;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}
.card{max-width:520px;margin:24px;padding:40px 36px;border:1px solid #242E3E;background:#111722}
.code{font-size:13px;letter-spacing:.18em;color:#4FC1E0;text-transform:uppercase}
h1{margin:12px 0 10px;font-size:22px;font-weight:500}
p{margin:0;color:#8A97AB;line-height:1.7;font-size:14px}
a{display:inline-block;margin-top:18px;padding:5px 0;color:#4FC1E0;text-decoration:none;font-size:14px;line-height:1.5}
a:hover{text-decoration:underline}
</style>
</head>
<body>
<div class="card">
<div class="code">{$status}</div>
<h1>{$safeTitle}</h1>
<p>{$safeMessage}</p>
<a href="{$safeHref}">{$safeLabel}</a>
</div>
</body>
</html>
HTML;

        $response->getBody()->write($html);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
