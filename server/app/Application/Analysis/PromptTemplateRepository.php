<?php

declare(strict_types=1);

namespace App\Application\Analysis;

use PDO;

/**
 * 提示词版本库（hq_prompt_template）。
 *
 * 表是**可选覆盖层**：文件里的提示词是默认值，表里存在 status='active' 的同名同版本记录时优先。
 * 这样「线上热修一句提示词」不必发版，但又不会因为表为空而跑不起来。
 */
final class PromptTemplateRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{system_prompt:string, user_template:string, params_json:?string}|null */
    public function active(string $code, string $version): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT system_prompt, user_template, params_json
               FROM hq_prompt_template
              WHERE code = :code AND version = :ver AND status = \'active\'
              LIMIT 1'
        );
        $stmt->execute([':code' => $code, ':ver' => $version]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * 让文件版提示词入库（幂等）：已存在则不动，status 保持原样。
     *
     * 用途：`bin/console.php prompt:sync` —— 让后台能看到「当前生效的提示词全文」，
     * 编辑后 status 改为 active 即生效，不必改文件。
     */
    public function upsertFromFile(string $code, string $version, string $system, string $user): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status FROM hq_prompt_template WHERE code = :code AND version = :ver LIMIT 1'
        );
        $stmt->execute([':code' => $code, ':ver' => $version]);
        $row = $stmt->fetch();

        if (is_array($row)) {
            return (string) $row['status'];
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_prompt_template (code, version, system_prompt, user_template, schema_json, params_json, status, created_at)
             VALUES (:code, :ver, :sys, :user, NULL, NULL, \'draft\', NOW())'
        );
        $stmt->execute([':code' => $code, ':ver' => $version, ':sys' => $system, ':user' => $user]);

        return 'draft';
    }
}
