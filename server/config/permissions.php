<?php

declare(strict_types=1);

/**
 * 路由 → 所需权限的映射（M4 权限矩阵的落地表）。
 *
 * 设计要点：
 *   1. **默认拒绝**。中间件只认这张表：路由名不在表里 → 403。
 *      这样「新加了一个动作忘了配权限」会立刻暴露，而不是悄悄对所有登录用户开放。
 *   2. **null 表示"登录即可"**，用于概览页、详情页这类无敏感动作的读取。
 *      不写 null 而漏掉整条，就是第 1 条说的那个坑。
 *   3. 权限码的契约在 App\Application\Auth\Permission::MATRIX，两者由
 *      bin/check-permissions.php 一起核对。
 */
return [
    // 公开页面（不走 PermissionMiddleware，登记是为了让"每条路由都有名字且有归属"这条不变式成立）
    'home'                   => null,
    'healthz'                => null,
    'admin.slash'            => null,
    'admin.login'            => null,
    'admin.login.post'       => null,

    // 公开馆藏（M5）：只读、无登录、无会话，因此全部 null
    'library.slash'          => null,
    'library.home'           => null,
    'library.works'          => null,
    'library.work'           => null,
    'library.work.author'    => null,
    'library.authors'        => null,
    'library.author'         => null,
    'library.terms'          => null,
    'library.term'           => null,
    'library.search'         => null,
    'library.issue'          => null,
    'library.sitemap'        => null,
    'library.method'         => null,
    /* 练笔统计：同样是公开只读页。登记在这里不是为了「给权限」，而是为了让
       bin/check-permissions.php 能证明这条路由确实被想过 —— 未登记的路由
       会整条 403，而 403 在页面上看起来像「这一页还没做完」。 */
    'library.practice'       => null,

    // 登录后即可访问
    'admin.dashboard'        => null,
    'admin.logout'           => null,

    // 作品
    'admin.works.index'      => 'work.read',
    'admin.works.show'       => 'work.read',
    'admin.works.versions'   => 'work.read',
    'admin.works.version.post' => 'work.write',
    'admin.works.diff'       => 'work.read',
    'admin.works.create'     => 'work.write',
    'admin.works.store'      => 'work.write',
    'admin.works.import'     => 'work.write',
    'admin.works.import.post' => 'work.write',
    'admin.works.delete'     => 'work.delete',

    // 分析
    'admin.works.analyze'    => 'analysis.run',
    'admin.analyses.show'    => 'work.read',
    'admin.analyses.rerun'   => 'analysis.run',
    'admin.analyses.publish' => 'analysis.publish',
    'admin.analyses.unpublish' => 'analysis.publish',

    // 四件产出编辑
    'admin.terms.update'     => 'analysis.edit',
    'admin.terms.delete'     => 'analysis.edit',
    'admin.terms.merge'      => 'term.merge',
    'admin.terms.unmerge'    => 'term.merge',
    'admin.suggestions.update' => 'analysis.edit',
    'admin.revisions.update' => 'analysis.edit',

    // 人工增删改四件产出：全部归 analysis.edit。
    // 不新开权限码的理由 —— 这是同一个人的同一件事（改这次拆解的结果），
    // 把它拆成「能改」「能加」「能删」三个码，实际使用时永远要三个都开。
    // 删除类动作靠 data-confirm 兜，不靠权限码兜。
    'admin.card.update'         => 'analysis.edit',
    'admin.summary.update'      => 'analysis.edit',
    'admin.beats.create'        => 'analysis.edit',
    'admin.beats.update'        => 'analysis.edit',
    'admin.beats.delete'        => 'analysis.edit',
    'admin.foreshadows.create'  => 'analysis.edit',
    'admin.foreshadows.update'  => 'analysis.edit',
    'admin.foreshadows.delete'  => 'analysis.edit',
    'admin.suggestions.create'  => 'analysis.edit',
    'admin.suggestions.delete'  => 'analysis.edit',
    'admin.paths.create'        => 'analysis.edit',
    'admin.paths.update'        => 'analysis.edit',
    'admin.paths.delete'        => 'analysis.edit',
    'admin.revisions.create'    => 'analysis.edit',
    'admin.revisions.delete'    => 'analysis.edit',
    'admin.reader.update'       => 'analysis.edit',
    'admin.terms.create'        => 'analysis.edit',
    'admin.snapshots.restore'   => 'analysis.edit',

    // 批量操作（1300 篇的主操作面）
    //   入队 / 取消 / 重试 = 花钱与停机，归 analysis.run；
    //   隐藏 / 恢复 = 内容对外与否，归 visibility.toggle。
    // 这两件事刻意分开：会跑分析的人不一定有权限把内容藏起来。
    //
    // ⚠️ 这条路由**承载两类语义**：一条路由只能映射一个权限码，所以这里登记的是
    //    入队类（analysis.run）；隐藏类的四个动作（hide / restore / hide-deck /
    //    restore-deck）在 WorkController::bulk 入口处按 $action **二次鉴权**
    //    visibility.toggle（不通过 → 403，与 PermissionMiddleware 同一套表现）。
    //    中间件只读这张表，看不出这层语义 —— 改这里或改控制器都要一起想。
    //    也因此，bin/check-permissions.php 只能校验「映射的权限码存在」，
    //    校验不了「这条路由其实需要两个码」。
    'admin.works.bulk'       => 'analysis.run',
    // 一键拆解：看范围与派活 = analysis.run（花钱的事）；「上架本批」= analysis.publish
    // （对外承诺）。两个键分开，是因为会跑分析的人不一定有权把结果推到读者面前。
    'admin.oneclick'         => 'analysis.run',
    'admin.oneclick.launch'  => 'analysis.run',
    'admin.oneclick.cancel'  => 'analysis.run',
    'admin.oneclick.publish' => 'analysis.publish',
    'admin.queue'            => 'analysis.run',
    // 实况只看不点，但它暴露的是「今天花了多少钱」—— 与调度台同一档，不额外放宽
    'admin.progress'         => 'analysis.run',
    'admin.progress.data'    => 'analysis.run',
    'admin.queue.enqueue'    => 'analysis.run',
    'admin.queue.pause'      => 'analysis.run',
    'admin.queue.cancel_queued' => 'analysis.run',
    'admin.queue.cancel_job' => 'analysis.run',
    'admin.queue.retry_job'  => 'analysis.run',
    'admin.queue.dismiss_job' => 'analysis.run',

    // 词条治理：看用 term.merge（本来就属内容编辑），藏用 visibility.toggle
    'admin.terms.index'      => 'term.merge',
    'admin.terms.show'       => 'term.merge',
    // 百科内容（起源与发展 / 世界科幻中的它）直接上公开页，与归并同属内容编辑档
    'admin.terms.encyclopedia' => 'term.merge',
    'admin.terms.hide'       => 'visibility.toggle',
    'admin.terms.restore'    => 'visibility.toggle',

    // 站内文章（官网内容源）：读用 work.read，同步要发对外请求、写本地缓存，归 work.write
    // 导出与列表同级：导出的内容就是列表页已经能一页页看到的，没有多给一行信息，
    // 单独提一级只会让「能看不能导」变成运营的麻烦事。
    'admin.source.index'     => 'work.read',
    'admin.source.export'    => 'work.read',
    'admin.source.show'      => 'work.read',
    'admin.source.activity'  => 'work.read',
    'admin.source.sync'      => 'work.write',

    // 隐藏归档：能看的人就是能操作的人，避免「看得见但点不动」
    'admin.hidden.index'     => 'visibility.toggle',
    'admin.hidden.restore'   => 'visibility.toggle',

    // 审计与账号
    'admin.audit.index'      => 'audit.read',
    // 改自己的口令不需要额外权限 —— 登录本身就是前提
    'admin.account.index'    => null,
    'admin.account.password' => null,
];
