<?php

declare(strict_types=1);

namespace App\Application\Analysis;

use App\Support\Config;
use RuntimeException;

/**
 * 提示词库：从 resources/prompts/{version}/ 读磁盘文件，不入库、不拼字符串。
 *
 * 提示词是**代码资产**（§6.5），必须能 diff、能 Review、能按版本回滚。
 * hq_prompt_template 表用于存「运行时热更新过的版本」，与文件版并存：
 * 文件是默认值，表里有同名同版本的 active 记录时优先用表里的。
 */
final class PromptLibrary
{
    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(
        private readonly Config $config,
        private readonly ?PromptTemplateRepository $templates = null,
    ) {
    }

    public function version(): string
    {
        return $this->config->string('ai.prompt_version', 'v1');
    }

    /**
     * 六阶段共用的 system 前缀。
     *
     * ⚠️ 这个字符串必须逐字节稳定：前缀缓存命中价是未命中的 1/50，
     * 一旦在这里混进阶段差异（比如把本阶段任务写进 system），单篇成本直接翻倍（§7.1.1⑤）。
     */
    public function base(): string
    {
        return $this->read('_base');
    }

    /** 阶段指令（放在 messages 尾部，不进 system） */
    public function stage(string $stageCode): string
    {
        $file = (string) $this->config->get('ai.stage.' . $stageCode . '.prompt', '');
        if ($file === '') {
            throw new RuntimeException(sprintf('未定义阶段提示词：%s', $stageCode));
        }

        $fromDb = $this->templates?->active($stageCode, $this->version());
        $text   = $fromDb['user_template'] ?? $this->read($file);

        // 通行词表是 S1 的上下文：模型从这几百个词里选，而不是每篇自己发明。
        // 占位符留在文件里，是为了让提示词保持可 diff —— 把 186 个词直接抄进
        // 提示词，改一次表就要改一次提示词，早晚会两边不同步。
        if (str_contains($text, '{{TAXONOMY}}')) {
            $text = str_replace('{{TAXONOMY}}', Taxonomy::promptList(), $text);
        }

        // 反向清单：明确告诉模型哪些说法不要再提。正向词表只能约束「该叫什么」，
        // 管不住「什么不该收」—— 后者才是词库变成通用意象词典的原因。
        if (str_contains($text, '{{RETIRED}}')) {
            $text = str_replace('{{RETIRED}}', Taxonomy::retiredPromptList(), $text);
        }

        // DeepSeek JSON Output 的硬要求：prompt 里必须出现 "json" 字样并给示例（§6.5③）
        if (stripos($text, 'json') === false) {
            throw new RuntimeException(sprintf('阶段提示词缺少 "json" 字样，JSON Output 会失败：%s', $stageCode));
        }

        return $text;
    }

    /** 所有提示词文件的整体指纹，用于排查「提示词变了但 input_hash 没变」这类事故 */
    public function fingerprint(): string
    {
        $parts = [$this->base()];
        foreach (array_keys((array) $this->config->get('ai.stage', [])) as $code) {
            $parts[] = $this->stage((string) $code);
        }

        return substr(hash('sha256', implode("\x00", $parts)), 0, 16);
    }

    private function read(string $name): string
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        $root = $this->config->string('app.root') . '/resources/prompts/' . $this->version();
        $path = $root . '/' . $name . '.md';

        if (!is_file($path)) {
            throw new RuntimeException('提示词文件不存在：' . $path);
        }

        $text = (string) file_get_contents($path);
        if (trim($text) === '') {
            throw new RuntimeException('提示词文件为空：' . $path);
        }

        return $this->cache[$name] = $text;
    }
}
