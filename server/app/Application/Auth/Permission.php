<?php

declare(strict_types=1);

namespace App\Application\Auth;

/**
 * 权限矩阵的**契约**。
 *
 * 为什么矩阵在代码里写死一份、数据库里又存一份：
 *   · 数据库那份是运行时真正生效的（改一行 `hq_role_permission` 立刻生效，不用发布）；
 *   · 代码这份是可被单测与代码评审的**意图声明**；
 *   · `bin/check-permissions.php` 负责比对两者，谁被谁悄悄改了立刻能看见。
 * 只有一份的坏处是「谁在什么时候拿掉了审校的发布权」事后无从查证；
 * 两份而不比对，坏处是它们必然漂移。两份 + 自动核对才是可控状态。
 *
 * 角色能力设计的取舍（不是随便分的）：
 *   · editor 有 analysis.run 没有 analysis.publish —— 能跑机器分析，但「对外可见」这一步要人负责；
 *   · reviewer 没有 work.write / analysis.run —— 审校的职责是看和改，不是导入和重跑；
 *     reviewer 有 audit.read 而 editor 没有 —— 审校要能追溯是谁改的；
 *   · viewer 只有 work.read —— 连 audit.read 都不给，只读就是只读。
 */
final class Permission
{
    // 作品与原文
    public const WORK_READ = 'work.read';
    public const WORK_WRITE = 'work.write';
    public const WORK_DELETE = 'work.delete';

    // 分析
    public const ANALYSIS_RUN = 'analysis.run';
    public const ANALYSIS_EDIT = 'analysis.edit';
    public const ANALYSIS_PUBLISH = 'analysis.publish';

    // 索引词
    public const TERM_MERGE = 'term.merge';

    /**
     * 隐藏 / 恢复内容。
     *
     * 与 analysis.publish 分开是有意的：
     *   · publish 是「这份内容可以见人了」——对外承诺，要审校签字；
     *   · visibility.toggle 是「先别让人看见」——止血动作，越快越好。
     * 如果合成一个码，编辑发现一篇引文错了也只能等人来发布权限，止损会拖到第二天。
     * 反过来，只有隐藏权、没有发布权，也构不成越权放行的风险。
     */
    public const VISIBILITY_TOGGLE = 'visibility.toggle';

    // 系统
    public const SYSTEM_USER = 'system.user';
    public const SYSTEM_SETTING = 'system.setting';
    public const AUDIT_READ = 'audit.read';

    /** @var list<string> 全部权限码，顺序与 hq_permission.id 一致 */
    public const ALL = [
        self::WORK_READ,
        self::WORK_WRITE,
        self::WORK_DELETE,
        self::ANALYSIS_RUN,
        self::ANALYSIS_EDIT,
        self::ANALYSIS_PUBLISH,
        self::TERM_MERGE,
        self::SYSTEM_USER,
        self::SYSTEM_SETTING,
        self::AUDIT_READ,
        self::VISIBILITY_TOGGLE,
    ];

    /** 权限码 → 界面上的中文说法（与 hq_permission.name 保持同一套话术） */
    public const LABELS = [
        self::WORK_READ       => '查看作品与原文',
        self::WORK_WRITE      => '新建/编辑/导入作品',
        self::WORK_DELETE     => '删除作品（软删）',
        self::ANALYSIS_RUN    => '触发分析/重分析',
        self::ANALYSIS_EDIT   => '编辑四件产出',
        self::ANALYSIS_PUBLISH => '发布/下线',
        self::TERM_MERGE      => '索引词归并',
        self::SYSTEM_USER     => '用户与角色',
        self::SYSTEM_SETTING  => '系统设置',
        self::AUDIT_READ      => '审计日志',
        self::VISIBILITY_TOGGLE => '隐藏/恢复内容',
    ];

    /** @var array<string, string> 角色码 → 中文名 */
    public const ROLE_LABELS = [
        'admin'    => '管理员',
        'editor'   => '编辑',
        'reviewer' => '审校',
        'viewer'   => '只读',
    ];

    /** @var array<string, list<string>> 角色码 → 权限码。契约正本，数据库须与之一致 */
    public const MATRIX = [
        'admin' => [
            self::WORK_READ, self::WORK_WRITE, self::WORK_DELETE,
            self::ANALYSIS_RUN, self::ANALYSIS_EDIT, self::ANALYSIS_PUBLISH,
            self::TERM_MERGE, self::SYSTEM_USER, self::SYSTEM_SETTING, self::AUDIT_READ,
            self::VISIBILITY_TOGGLE,
        ],
        'editor' => [
            self::WORK_READ, self::WORK_WRITE,
            self::ANALYSIS_RUN, self::ANALYSIS_EDIT, self::TERM_MERGE,
            // 编辑能跑分析、能止血（隐藏），但「对外可见」那一步仍要审校签字
            self::VISIBILITY_TOGGLE,
        ],
        'reviewer' => [
            self::WORK_READ, self::ANALYSIS_EDIT, self::ANALYSIS_PUBLISH, self::AUDIT_READ,
            // 审校的日常就是「这篇先别放出去」
            self::VISIBILITY_TOGGLE,
        ],
        'viewer' => [
            self::WORK_READ,
        ],
    ];

    /**
     * 一个权限码是否在权限列表里。
     *
     * @param array<int, mixed> $perms
     */
    public static function can(array $perms, string $code): bool
    {
        if ($code === '') {
            return true; // 空要求 = 只要求登录
        }

        return in_array($code, $perms, true);
    }

    /**
     * @param array<int, mixed> $perms
     * @param list<string>      $codes
     */
    public static function canAny(array $perms, array $codes): bool
    {
        foreach ($codes as $code) {
            if (self::can($perms, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 数据库矩阵与契约的差异，空数组表示一致。
     *
     * @param array<string, list<string>> $dbMatrix 角色码 → 权限码
     * @return list<string> 人类可读的差异行
     */
    public static function diff(array $dbMatrix): array
    {
        $issues = [];

        foreach (self::MATRIX as $role => $expected) {
            if (!array_key_exists($role, $dbMatrix)) {
                $issues[] = sprintf('数据库缺少角色 %s', $role);
                continue;
            }

            $actual   = array_values(array_unique($dbMatrix[$role]));
            $missing  = array_diff($expected, $actual);
            $extra    = array_diff($actual, $expected);

            foreach ($missing as $code) {
                $issues[] = sprintf('%s 在数据库里缺少 %s', $role, $code);
            }
            foreach ($extra as $code) {
                $issues[] = sprintf('%s 在数据库里多出 %s', $role, $code);
            }
        }

        foreach (array_keys($dbMatrix) as $role) {
            if (!array_key_exists($role, self::MATRIX)) {
                $issues[] = sprintf('数据库里有契约未定义的角色 %s', $role);
            }
        }

        return $issues;
    }
}
