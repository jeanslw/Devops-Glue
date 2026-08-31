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

            foreach (glob($dir . '/*.php') as $file) {
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
     * @param string $id       翻译键
     * @param array  $params   替换参数
     * @param string|null $locale 指定语言（null=默认）
     */
    public function trans(string $id, array $params = [], ?string $locale = null): string
    {
        return $this->translator->trans($id, $params, 'messages', $locale ?? $this->defaultLocale);
    }

    /**
     * 获取指定语言的所有翻译（供前端 API 使用）
     */
    public function getAll(string $locale): array
    {
        $catalogue = $this->translator->getCatalogue($locale);
        return $catalogue->all('messages');
    }

    /**
     * 获取所有可用语言
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
        // 1. URL 参数 ?lang=en
        $queryLang = $request->getQueryParams()['lang'] ?? '';
        if (in_array($queryLang, ['zh_CN', 'en'])) {
            return $queryLang;
        }

        // 2. Accept-Language 头
        $acceptLang = $request->getHeaderLine('Accept-Language');
        if ($acceptLang) {
            // 简单解析：取第一个语言
            if (preg_match('/^([a-z]{2})/i', trim($acceptLang), $m)) {
                $lang = strtolower($m[1]);
                // 'zh' 映射到 zh_CN
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
