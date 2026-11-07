<?php
/* 词表自检：key 重复（PHP 会静默覆盖）、alias 撞 canonical、alias 互相重复、越界。 */

// 路径写成常量：安全审计只允许常量路径的 require，变量拼接一律算动态包含。
const TERMS_FILE   = __DIR__ . '/../resources/taxonomy/sf_terms.php';
const RETIRED_FILE = __DIR__ . '/../resources/taxonomy/retired_terms.php';

$raw = file_get_contents(TERMS_FILE);

// 1) 用正则数原始出现次数，绕开 PHP 数组去重
preg_match_all("/^\s{8}'([^']+)'\s*=>/mu", $raw, $m);
$keys = $m[1];
$dup  = array_diff_assoc($keys, array_unique($keys));
echo '规范词原始出现 ' . count($keys) . ' 次，去重后 ' . count(array_unique($keys)) . " 个\n";
echo $dup ? '!! canonical 重复：' . implode(' / ', array_unique($dup)) . "\n" : "canonical 无重复\n";

$t     = require TERMS_FILE;
$total = 0;
$aliasOwner = [];
$bad   = 0;

foreach ($t as $cat => $words) {
    echo str_pad($cat, 9) . ': ' . count($words) . " 条\n";
    $total += count($words);
    foreach ($words as $canon => $aliases) {
        $len = mb_strlen($canon);
        if ($len < 2 || $len > 8) {
            echo "  !! 长度越界 {$canon} ({$len})\n";
            $bad++;
        }
        foreach ($aliases as $a) {
            if (isset($aliasOwner[$a])) {
                echo "  !! alias 重复：{$a}（{$aliasOwner[$a]} / {$canon}）\n";
                $bad++;
            }
            $aliasOwner[$a] = $canon;
        }
    }
}

// 2) alias 撞 canonical
foreach ($t as $cat => $words) {
    foreach ($words as $canon => $aliases) {
        foreach ($aliases as $a) {
            if (isset($t['setting'][$a]) || isset($t['world'][$a]) || isset($t['image'][$a])) {
                echo "  !! alias 「{$a}」与规范词重名（属于 {$canon}）\n";
                $bad++;
            }
        }
    }
}

echo "---\n合计 {$total} 条 · alias " . count($aliasOwner) . " 个 · 问题 {$bad}\n";

// 3) 退役词与词表的冲突
//    退役表的作用是「拒绝」，如果它拦住了词表里的规范词，那条规范词就永远进不了库；
//    如果它只是某条别名，写在那里也是冗余（归并先发生，轮不到退役检查）。
$retired     = is_file(RETIRED_FILE) ? require RETIRED_FILE : [];
$canonSet    = [];
foreach ($t as $words) {
    foreach (array_keys($words) as $canon) {
        $canonSet[(string) $canon] = true;
    }
}

$clash  = [];
$redund = [];
foreach ((array) $retired as $word) {
    $word = trim((string) $word);
    if ($word === '') {
        continue;
    }
    if (isset($canonSet[$word])) {
        $clash[] = $word;
    } elseif (isset($aliasOwner[$word])) {
        $redund[] = $word . '（是「' . $aliasOwner[$word] . '」的别名）';
    }
}

echo '退役词 ' . count((array) $retired) . " 条\n";
echo $clash ? '!! 退役词与规范词冲突：' . implode(' / ', $clash) . "\n" : "退役词未挡住任何规范词\n";
echo $redund ? '·· 可删的冗余退役词：' . implode(' / ', $redund) . "\n" : "无冗余退役词\n";
exit($bad === 0 && $clash === [] ? 0 : 1);
