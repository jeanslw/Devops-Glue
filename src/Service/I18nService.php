<?php

namespace App\Service;

use Symfony\Component\Translation\Translator;
use Symfony\Component\Translation\Loader\ArrayLoader;

/**
 * 国际化服务
 * 封装 Symfony Translator，提供便捷的翻译方法
 */
class I18nService
{
    private Translator $translator;
    private string $defaultLocale;

    public function __construct(string $langDir, string $defaultLocale = 'zh_CN')
    {
        $this->defaultLocale = $defaultLocale;
        $this->translator    = new Translator($defaultLocale);
        $this->translator->addLoader('array', new ArrayLoader());

        $this->loadResources($langDir);
    }

    /**
     * 加载语言文件
     */
    private function loadResources(string $langDir): void
    {
        $locales = ['zh_CN', 'en'];
        foreach ($locales as $locale) {
            $dir = $langDir . '/' . $locale;
            if (!is_dir($dir)) {
                continue;
            }

            $files = glob($dir . '/*.php');
            if ($files === false) {
                $files = [];
            }
            foreach ($files as $file) {
                $domain = pathinfo($file, PATHINFO_FILENAME);
                $messages = require $file;
                if (is_array($messages)) {
                    $this->translator->addResource('array', $messages, $locale, $domain);
                }
            }
        }
    }

    /**
     * 翻译单个消息
     *
     * 占位符统一按 `{key}` 归一后再替换。原因：没有 intl 翻译器时 Symfony 的 MessageFormatter
     * 退化为 `strtr($message, $params)`，即把「参数键」当普通子串全局替换——传裸键
     * `['repo' => 'x/y']` 时文案里的 `{repo}` 会被留成 `{x/y}`，还会误伤同名单词
     * （`registry_repository` → `registry_x/ysitory`）。归一后 `repo` 与 `{repo}` 两种写法
     * 都能得到正确文案，且不再误伤单词。
     *
     * @param string $id       翻译键
     * @param array<string,mixed> $params   替换参数（键可写 `repo` 或 `{repo}`）
     * @param string|null $locale 指定语言（null=默认）
     */
    public function trans(string $id, array $params = [], ?string $locale = null): string
    {
        $normalized = [];
        foreach ($params as $key => $value) {
            $key = (string) $key;
            $placeholder = str_starts_with($key, '{') ? $key : '{' . $key . '}';
            $normalized[$placeholder] = is_scalar($value) ? (string) $value : '';
        }

        return $this->translator->trans($id, $normalized, 'messages', $locale ?? $this->defaultLocale);
    }

    /**
     * 获取指定语言的所有翻译（供前端 API 使用）
     *
     * @return array<string,string>
     */
    public function getAll(string $locale): array
    {
        $catalogue = $this->translator->getCatalogue($locale);
        return $catalogue->all('messages');
    }

    /**
     * 获取所有可用语言
     *
     * @return list<string>
     */
    public function getAvailableLocales(): array
    {
        return ['zh_CN', 'en'];
    }

    public function getDefaultLocale(): string
    {
        return $this->defaultLocale;
    }

    /**
     * 从请求中检测语言
     * 优先级：query param ?lang= > Accept-Language header > 默认语言
     */
    public function detectLocale(\Psr\Http\Message\ServerRequestInterface $request): string
    {

        $queryLang = $request->getQueryParams()['lang'] ?? '';
        if (in_array($queryLang, ['zh_CN', 'en'])) {
            return $queryLang;
        }


        $acceptLang = $request->getHeaderLine('Accept-Language');
        if ($acceptLang) {
            if (preg_match('/^([a-z]{2})/i', trim($acceptLang), $m)) {
                $lang = strtolower($m[1]);

                if ($lang === 'zh') {
                    return 'zh_CN';
                }
                if ($lang === 'en') {
                    return 'en';
                }
            }
        }

        return $this->defaultLocale;
    }
}
