<?php

declare(strict_types=1);

namespace App\Application\Source;

/**
 * 官网站内文章 API 客户端。
 *
 * ## 两个接口，`key` 的语义**完全不同**（这是最容易踩的坑）
 *
 * | 接口 | key 是什么 | 例子 |
 * | --- | --- | --- |
 * | `hqContents/contentsList` | 平台公共密钥（固定串） | `?key=QyAPIZKw&page=1&limit=50` |
 * | `hqContents/contentsInfo` | **文章 cid** | `?key=2460&isMd=0` |
 *
 * 详情接口把 `key` 当 cid 用，这个设计在官网前端里也看不出来 —— 页面代码写的是
 * `{key: this.cid}`，读代码的人很容易以为那是公共密钥然后一直抓不到数据
 * （实测 `contentsInfo?key=QyAPIZKw&cid=2460` 永远返回「该文章不存在」）。
 * 所以这里两个方法各用各的参数，不共用一个 `key` 变量。
 *
 * ## 为什么必须指定 CA
 *
 * 本机 PHP 没有配 `curl.cainfo`，而该站证书链在严格校验下会报
 * `self-signed certificate in certificate chain`。浏览器能容忍，curl 不能。
 * 不为一个读取公开内容的客户端去改全局 php.ini（那是安全配置），
 * 改为在请求里显式指定 CA 文件；路径由配置给出，换机器只需改 .env。
 *
 * ## 失败不抛异常给页面
 *
 * 这是「浏览外部站点的只读工具」，不是交易链路。网络抖动时页面应当退化成
 * 「这次没拉到」而不是整页 500 —— 调用方拿到 null 自己决定怎么提示。
 */
final class SourceApi
{
    /** 列表每页上限。传更大的值不会报错，但服务端照样只给 50 条 */
    public const MAX_LIMIT = 50;

    private const TIMEOUT = 30;

    public function __construct(
        private readonly string $base,
        private readonly string $publicKey,
        private readonly string $caFile,
    ) {
    }

    /**
     * 文章列表（分页）。
     *
     * @param string $searchKey 关键词，空串表示不过滤。服务端按标题匹配，total 会变。
     * @return array{total:int, data:list<array<string,mixed>>}|null
     */
    public function list(int $page = 1, int $limit = self::MAX_LIMIT, string $searchKey = ''): ?array
    {
        $query = [
            'key'   => $this->publicKey,
            'page'  => max(1, $page),
            'limit' => max(1, min(self::MAX_LIMIT, $limit)),
        ];
        if ($searchKey !== '') {
            $query['searchKey'] = $searchKey;
        }

        $raw = $this->get('hqContents/contentsList', $query);
        if ($raw === null || (int) ($raw['code'] ?? 0) !== 1) {
            return null;
        }

        return [
            'total' => (int) ($raw['total'] ?? 0),
            'data'  => is_array($raw['data'] ?? null) ? array_values($raw['data']) : [],
        ];
    }

    /**
     * 单篇详情（含全文）。
     *
     * @return array<string,mixed>|null 不存在或请求失败都返回 null
     */
    public function detail(int $cid): ?array
    {
        if ($cid <= 0) {
            return null;
        }

        // 注意：这里的 key 是 cid，不是公共密钥。isMd 请求 Markdown 原文。
        $raw = $this->get('hqContents/contentsInfo', [
            'key'  => (string) $cid,
            'isMd' => 0,
        ]);

        if ($raw === null) {
            return null;
        }

        // 详情接口的两个响应形状**不对称**，这是本类最容易写错的地方：
        //   · 成功：没有 code 字段，cid/title 直接铺在顶层；
        //   · 失败：{"msg":"该文章不存在","code":0}，没有 cid。
        // 所以不能用「code === 1」判成功 —— 成功的 code 是 null，会被当成失败，
        // 表现就是「详情页永远 404，而列表页一切正常」。
        if (!isset($raw['cid']) && !isset($raw['title'])) {
            return null;
        }

        return $raw;
    }

    /**
     * 某位作者在站内的评稿统计。
     *
     * 「评稿」在这个站是独立于评论的一件东西：`review/reviewList` 的每条记录
     * 带五个维度的打分（`score` 拆成电子/文笔/节奏/人物/立意）和五段评语，
     * 全站一万三千多条；而 `hqComments/commentsList` 是短文评论，对象可以是文章
     * 也可以是某篇评稿。**判「有没有评稿行为」只能看前者**，拿评论代替会把
     * 「只回了句『好看』」算成评过稿。
     *
     * 过滤参数放在 `searchParams` 这个 JSON 串里。实测有效的键：
     * `uid`（评稿人）、`cid`（被评文章）；无效的键会被静默忽略 —— 传错不会报错，
     * 只会还你全站总量，所以这里只传实测过的。
     *
     * 只要 `limit=1` 就能同时拿到 total 和最新一条的时间：判「最近有没有评过」
     * 只需要最新那条，不需要把某人的几百条评稿全翻一遍。
     *
     * @return array{total:int, last:int}|null 请求失败返回 null（调用方要能区分
     *         「没评过稿」和「这次没查到」—— 前者 total=0，后者 null）
     */
    public function userReviews(int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }

        return $this->countBy('review/reviewList', ['uid' => $uid], ['order' => 'created']);
    }

    /**
     * 某位作者的评论统计（短评，不是评稿）。
     *
     * 评论记录的作者在 `authorId`（`ownerId` 是**被评论文章的作者**，两个键
     * 一字之差、数字完全不同：实测同一人 authorId 43 条、ownerId 29 条）。
     *
     * @return array{total:int, last:int}|null
     */
    public function userComments(int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }

        return $this->countBy('hqComments/commentsList', ['authorId' => $uid], []);
    }

    /**
     * 某一期练笔的**全部评稿**（分页）。
     *
     * 过滤键是 `activeid`（活动 mid），不是 `mid` —— 这是实测出来的差别：
     * `searchParams={"mid":"99"}` 会被静默忽略，还你全站一万三千多条；
     * `{"activeid":"99"}` 才是 271 条。传错不报错，只是数字突然变成全站总量，
     * 这种错在报表上极难发现，所以键名写死在这里、不给调用方拼。
     *
     * 每行都带 `uid`（评稿人）、`cid`（被评作品）、`isAi`（是否机器评稿）：
     * 前者用来去重算「评稿成员数」，中者用来把评稿限定在「本期计入的作品」上，
     * 后者用来把 AI 评稿单列 —— 机器评稿算进「有多少人评过稿」是错的。
     *
     * @return array{total:int, data:list<array<string,mixed>>}|null
     */
    public function reviewsByIssue(int $mid, int $page = 1, int $limit = self::MAX_LIMIT): ?array
    {
        if ($mid <= 0) {
            return null;
        }

        return $this->pageList('review/reviewList', ['activeid' => $mid], $page, $limit);
    }

    /**
     * 活动（期次）详情：官网上 `/pages/contents/active?id=X` 那一页的主数据。
     *
     * 返回里的 `count` / `wordCount` / `reviewNum` / `reviewPeople` 是站方自己
     * 算好的聚合值。**不能拿它当唯一真相** —— 它的 `count` 把未公开的也算进去，
     * 而需求要的是「公开/未公开分开」；但它是一个独立口径的对照值，
     * 与我们自己数出来的差得远，就说明有一边的口径变了，值得停下来看一眼。
     *
     * @return array<string,mixed>|null
     */
    public function activity(int $mid): ?array
    {
        if ($mid <= 0) {
            return null;
        }

        $raw = $this->get('hqMetas/metaInfo', ['key' => (string) $mid]);
        if ($raw === null || (int) ($raw['code'] ?? 0) !== 1) {
            return null;
        }

        return is_array($raw['data'] ?? null) ? $raw['data'] : null;
    }

    /**
     * 通用分页列表：`searchParams` 里放 JSON、其余是顶层参数。
     *
     * `limit` 在服务端被封顶 50（实测传 500 也只回 50 条），所以调用方
     * 不要指望一次拉完，按 total 翻页。
     *
     * @param array<string,int|string> $filter
     * @return array{total:int, data:list<array<string,mixed>>}|null
     */
    public function pageList(string $path, array $filter, int $page = 1, int $limit = self::MAX_LIMIT): ?array
    {
        $raw = $this->get($path, [
            'searchParams' => (string) json_encode($filter, JSON_UNESCAPED_UNICODE),
            'limit'        => max(1, min(self::MAX_LIMIT, $limit)),
            'page'         => max(1, $page),
        ]);

        if ($raw === null || (int) ($raw['code'] ?? 0) !== 1) {
            return null;
        }

        return [
            'total' => (int) ($raw['total'] ?? 0),
            'data'  => is_array($raw['data'] ?? null) ? array_values($raw['data']) : [],
        ];
    }

    /**
     * 「按某个键过滤，只取第一条」的通用形态。
     *
     * @param array<string,int|string> $filter 放进 searchParams 的 JSON
     * @param array<string,string>     $extra  额外查询参数（如 order）
     * @return array{total:int, last:int}|null
     */
    private function countBy(string $path, array $filter, array $extra): ?array
    {
        $raw = $this->get($path, array_merge($extra, [
            'searchParams' => (string) json_encode($filter, JSON_UNESCAPED_UNICODE),
            'limit'        => 1,
            'page'         => 1,
        ]));

        // 这两个接口与 contentsInfo 不同，成功时**有** code=1；失败是 code=0 + msg。
        if ($raw === null || (int) ($raw['code'] ?? 0) !== 1) {
            return null;
        }

        $rows  = is_array($raw['data'] ?? null) ? $raw['data'] : [];
        $first = is_array($rows[0] ?? null) ? $rows[0] : null;

        return [
            'total' => (int) ($raw['total'] ?? 0),
            'last'  => is_array($first) ? (int) ($first['created'] ?? 0) : 0,
        ];
    }

    /**
     * @param array<string, scalar> $query
     * @return array<string,mixed>|null
     */
    private function get(string $path, array $query): ?array
    {
        $url = rtrim($this->base, '/') . '/' . ltrim($path, '/') . '?' . http_build_query($query);

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => 'HuangqiLibraryAdmin/1.0',
            // 不跟随重定向（E-48）：出站目标只允许是配置里那一个主机。
            // 允许 302 就等于允许对方把请求带到任意地址 —— SSRF 的常见入口。
            CURLOPT_FOLLOWLOCATION => false,
            // 只读公开内容，但**不关校验**：关掉等于把「读到的是不是那个站」这件事
            // 交给网络。指定 CA 能解决就得指定 CA。
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        // CA 路径可能被 open_basedir 挡住（本机 php.ini 只允许 server 目录及其下）。
        // 那种情况下 is_file() 本身会抛 warning，而 bootstrap 把 warning 转成异常 ——
        // 于是一个「证书没找到」会变成整页 500。这里包起来：读不到就不传，
        // 让 curl 用系统默认 CA，失败时按普通网络错误处理。
        if ($this->caFile !== '') {
            try {
                $readable = is_file($this->caFile);
            } catch (\Throwable) {
                $readable = false;
            }
            if ($readable) {
                $options[CURLOPT_CAINFO] = $this->caFile;
            }
        }
        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status !== 200 || !is_string($body) || $body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
