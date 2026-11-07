<?php

declare(strict_types=1);

/**
 * 配置聚合：只从环境变量读，不在代码里写死任何密钥或主机名。
 * 返回结构见规划文档 §4.2；键路径用点号访问（Config::get('db.port')）。
 */

$env = static function (string $key, ?string $default = null): ?string {
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return is_string($value) ? trim($value) : $default;
};

$bool = static fn (string $key, bool $default): bool => match (strtolower((string) $env($key, $default ? '1' : '0'))) {
    '1', 'true', 'yes', 'on'  => true,
    '0', 'false', 'no', 'off' => false,
    default                   => $default,
};

$root = dirname(__DIR__);

return [
    'app' => [
        'name'     => '银河图书馆',
        'env'      => $env('APP_ENV', 'production'),
        'debug'    => $bool('APP_DEBUG', false),
        'url'      => $env('APP_URL', 'http://127.0.0.1:8090'),
        'timezone' => $env('APP_TIMEZONE', 'Asia/Shanghai'),
        'root'     => $root,
        'kek'      => $env('APP_KEK', ''),
    ],

    'db' => [
        'host'    => $env('DB_HOST', '127.0.0.1'),
        'port'    => (int) $env('DB_PORT', '3306'),
        'name'    => $env('DB_NAME', 'hqlib'),
        'user'    => $env('DB_USER', ''),
        'pass'    => $env('DB_PASS', ''),
        'charset' => $env('DB_CHARSET', 'utf8mb4'),
    ],

    'session' => [
        // 会话名在 php.ini 里是 hq_admin；这里只做兜底与生命周期
        'name'     => $env('SESSION_NAME', 'hq_admin'),
        'lifetime' => (int) $env('SESSION_LIFETIME', '1800'),
        'secure'   => $bool('SESSION_COOKIE_SECURE', false),
    ],

    'security' => [
        'login_max_attempts' => (int) $env('LOGIN_MAX_ATTEMPTS', '5'),
        'login_lock_minutes' => (int) $env('LOGIN_LOCK_MINUTES', '15'),
        'totp_enforce'       => $bool('TOTP_ENFORCE', false),
        'rate_limit'         => [
            'login'      => [5, 60],    // 5 次 / 60 秒（IP 维度）
            'admin_write' => [120, 60],
        ],
    ],

    'upload' => [
        'max_bytes'   => (int) $env('UPLOAD_MAX_BYTES', '33554432'), // 32 MiB，与 php.ini 对齐
        'allowed_ext' => ['txt', 'md', 'markdown', 'docx'],
        'dir'         => $root . '/storage/uploads',
    ],

    'storage' => [
        'logs'      => $root . '/storage/logs',
        'cache'     => $root . '/storage/cache',
        'artifacts' => $root . '/storage/artifacts',
    ],

    'log' => [
        'level' => $env('LOG_LEVEL', 'info'),
        'dir'   => $root . '/storage/logs',
    ],

    // 告警通道（附录 E-67）。webhook 只认运维配置的地址，不接受任何用户输入。
    // 未配置时 Alert 仍会往 storage/logs/alert.log 落一行「skipped」——
    // 留着痕才知道是「没配」还是「配了但发不出去」。
    'alert' => [
        'enabled'  => $bool('ALERT_ENABLED', false),
        'webhook'  => (string) $env('ALERT_WEBHOOK', ''),
        'timeout'  => (int) $env('ALERT_TIMEOUT', '3'),
        'cooldown' => (int) $env('ALERT_COOLDOWN', '1800'),
    ],

    // AI 的全部参数在 config/ai.php（模型、价格、六阶段参数），这里只做聚合
    'ai' => require __DIR__ . '/ai.php',

    // 公开馆藏（M5）。TTL 与 PageCache 的失效标签一一对应，改一边就要改另一边。
    'library' => [
        'per_page'   => (int) $env('LIBRARY_PER_PAGE', '12'),
        'cache'      => [
            'home'    => (int) $env('LIBRARY_CACHE_HOME', '60'),
            'works'   => (int) $env('LIBRARY_CACHE_WORKS', '60'),
            'work'    => (int) $env('LIBRARY_CACHE_WORK', '300'),
            // 作者页跟着作品与词条一起变：任一隐藏/发布都会打 works+terms 标签，
            // 而作者页正好挂在这两个标签上，所以 TTL 可以和作品页一样长
            'author'  => (int) $env('LIBRARY_CACHE_AUTHOR', '300'),
            'terms'   => (int) $env('LIBRARY_CACHE_TERMS', '300'),
            'term'    => (int) $env('LIBRARY_CACHE_TERM', '300'),
            'issue'   => (int) $env('LIBRARY_CACHE_ISSUE', '300'),
        ],
        'cache_dir'  => $root . '/storage/cache/pages',
        // 公开读限流：次数 / 窗口秒数
        'rate_limit' => [(int) $env('LIBRARY_RATE_LIMIT', '60'), 60],
        // 「这套结果怎么来的」方法页（v2.4 起在馆内：/library/method）。
        // 官网那一页只讲理念，方法论归馆内 —— 人看着某一页的结论才会问「凭什么」，
        // 与其让他再跳一次站，不如就在馆里答。置空则整站不显示该链接。
        'method_url' => $env('LIBRARY_METHOD_URL', '/library/method'),
    ],

    // 备份：加密快照 + 恢复演练（附录 E-56 / E-65）
    'backup' => [
        'dir'   => $env('BACKUP_DIR', $root . '/storage/backups'),
        'keep'  => (int) $env('BACKUP_KEEP', '7'),
        // 恢复演练用的独立库与独立账号：应用账号没有 DDL 权限（这是对的），
        // 演练要建表，所以给它一套一次性的凭据，演练完不碰生产库。
        'drill' => [
            'host' => $env('DRILL_DB_HOST', '127.0.0.1'),
            'port' => (int) $env('DRILL_DB_PORT', '3307'),
            'name' => $env('DRILL_DB_NAME', 'hqlib_drill'),
            'user' => $env('DRILL_DB_USER', 'hqlib_restore'),
            'pass' => $env('DRILL_DB_PASS', ''),
        ],
    ],

    // 官网站内文章（后台「站内文章」用）。
    //
    // 两个接口的 key 语义不同：列表用公共密钥，详情用文章 cid —— 详见 SourceApi 的注释。
    //
    // ca 默认放在**项目目录内**（storage/certs/cacert.pem），不是 PHP 安装目录：
    // php.ini 的 open_basedir 只允许 server 及其下，指向 D:\webserver\php\... 会让
    // curl 读不到证书、直接失败。把证书复制进来是唯一不改安全配置的解法。
    'source' => [
        'base'      => $env('SOURCE_API_BASE', 'https://api.huangqisf.com/'),
        'key'       => $env('SOURCE_API_KEY', 'QyAPIZKw'),
        'ca'        => (string) $env('SOURCE_API_CA', $root . '/storage/certs/cacert.pem'),
        'cache_dir' => $root . '/storage/cache/source',
    ],

    // 路由 → 权限映射（权限码的契约在 App\Application\Auth\Permission）
    'permissions' => require __DIR__ . '/permissions.php',
];
