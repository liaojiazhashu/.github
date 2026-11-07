<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Auth\Permission;
use PHPUnit\Framework\TestCase;

/**
 * 权限矩阵是「谁能做什么」的唯一声明，它错了不会报错、只会静默放权或静默拦人，
 * 所以这里逐格断言，而不是抽查两个代表。
 */
final class PermissionTest extends TestCase
{
    public function testMatrixCoversEveryRoleAndOnlyKnownCodes(): void
    {
        $this->assertSame(
            ['admin', 'editor', 'reviewer', 'viewer'],
            array_keys(Permission::MATRIX),
            '角色集合必须与 hq_role 的四行一致'
        );

        foreach (Permission::MATRIX as $role => $codes) {
            $this->assertSame($codes, array_values(array_unique($codes)), $role . ' 的权限不应有重复');
            foreach ($codes as $code) {
                $this->assertContains($code, Permission::ALL, $role . ' 引用了未定义的权限码 ' . $code);
            }
        }
    }

    public function testEveryCodeHasLabelAndEveryRoleHasLabel(): void
    {
        foreach (Permission::ALL as $code) {
            $this->assertArrayHasKey($code, Permission::LABELS, $code . ' 缺少中文说明');
            $this->assertNotSame('', Permission::LABELS[$code]);
        }

        foreach (array_keys(Permission::MATRIX) as $role) {
            $this->assertArrayHasKey($role, Permission::ROLE_LABELS, $role . ' 缺少中文名');
        }
    }

    public function testAdminHasEveryPermission(): void
    {
        $this->assertSame(Permission::ALL, Permission::MATRIX['admin']);
    }

    public function testViewerCanOnlyReadWorks(): void
    {
        $this->assertSame([Permission::WORK_READ], Permission::MATRIX['viewer']);
    }

    /**
     * 编辑与审校的分界线是这个系统最容易被改错的格：
     * 编辑能跑机器分析但不能决定"对外可见"，审校能发布但不能导入和重跑。
     */
    public function testEditorAndReviewerSplitOnPublishAndRun(): void
    {
        $editor   = Permission::MATRIX['editor'];
        $reviewer = Permission::MATRIX['reviewer'];

        $this->assertContains(Permission::ANALYSIS_RUN, $editor);
        $this->assertNotContains(Permission::ANALYSIS_PUBLISH, $editor);
        $this->assertNotContains(Permission::WORK_DELETE, $editor);

        $this->assertContains(Permission::ANALYSIS_PUBLISH, $reviewer);
        $this->assertContains(Permission::ANALYSIS_EDIT, $reviewer);
        $this->assertContains(Permission::AUDIT_READ, $reviewer);
        $this->assertNotContains(Permission::ANALYSIS_RUN, $reviewer);
        $this->assertNotContains(Permission::WORK_WRITE, $reviewer);
        $this->assertNotContains(Permission::TERM_MERGE, $reviewer);
    }

    public function testCanTreatsEmptyRequirementAsLoginOnly(): void
    {
        $this->assertTrue(Permission::can([], ''));
        $this->assertTrue(Permission::can(['work.read'], ''));
    }

    public function testCanIsExactNotPrefix(): void
    {
        $perms = ['work.read'];

        $this->assertTrue(Permission::can($perms, 'work.read'));
        $this->assertFalse(Permission::can($perms, 'work.write'));
        // 'work' 是 'work.read' 的前缀，但不能因为"看起来包含"就放行
        $this->assertFalse(Permission::can($perms, 'work'));
    }

    public function testCanAny(): void
    {
        $perms = ['analysis.edit'];

        $this->assertTrue(Permission::canAny($perms, ['analysis.publish', 'analysis.edit']));
        $this->assertFalse(Permission::canAny($perms, ['analysis.publish', 'term.merge']));
        $this->assertFalse(Permission::canAny([], []));
    }

    public function testDiffReportsNothingWhenDatabaseMatchesContract(): void
    {
        $this->assertSame([], Permission::diff(Permission::MATRIX));
    }

    public function testDiffReportsMissingAndExtra(): void
    {
        $db = Permission::MATRIX;
        // 数据库里少了审校的发布权，多了编辑的删除权
        $db['reviewer'] = array_values(array_diff($db['reviewer'], [Permission::ANALYSIS_PUBLISH]));
        $db['editor'][] = Permission::WORK_DELETE;

        $issues = Permission::diff($db);

        $this->assertContains('reviewer 在数据库里缺少 analysis.publish', $issues);
        $this->assertContains('editor 在数据库里多出 work.delete', $issues);
    }

    public function testDiffReportsMissingAndUnknownRole(): void
    {
        $db = Permission::MATRIX;
        unset($db['viewer']);
        $db['guest'] = ['work.read'];

        $issues = Permission::diff($db);

        $this->assertContains('数据库缺少角色 viewer', $issues);
        $this->assertContains('数据库里有契约未定义的角色 guest', $issues);
    }
}
