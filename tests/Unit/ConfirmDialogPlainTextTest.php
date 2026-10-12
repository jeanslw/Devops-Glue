<?php

declare(strict_types=1);

namespace App\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 守卫不变量：通用确认弹窗（templates/admin.html 的 #confirm-modal）是**纯文本通道**。
 *
 * 回归背景（v2.9.0）：「集成服务」启用确认弹窗（该面板当时挂在数据管理页）把三步提示拼成
 * `<div style="..."><div>① …</div>…</div>` 传进 confirmDialog 的 note，而 note 由
 * public/assets/core/toast.js 用 textContent 渲染（弹窗文案从不经 HTML 解析），
 * 于是弹窗里显示的是原样输出的 `<div ...>` 标签（乱码），而不是三行提示。
 *
 * 三条不变量（破任一条即复现同一类故障）：
 *   1. 弹窗文本节点只经 textContent 渲染 —— toast.js 里不得出现 innerHTML；
 *   2. 多行文案只能靠 \n，故正文选择器需要 white-space:pre-line 才会断行；
 *   3. 调用方传给 confirmDialog 的 message / note 不得含 HTML 标签（含经局部变量中转的写法）。
 */
class ConfirmDialogPlainTextTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** 弹窗文本节点（admin.html）。 */
    private const TEXT_NODES = ['confirm-title', 'confirm-message', 'confirm-note'];

    /** 需要 pre-line 的正文选择器：message 走 .modal-hint，note 走 .modal-note。 */
    private const PRE_LINE_SELECTORS = ['.modal-hint', '.modal-note'];

    /** confirmDialog 的纯文本字段。 */
    private const TEXT_FIELDS = ['message', 'note'];

    /** 变量中转解析的最大查看长度：赋值语句漏写分号时不至于把半个文件吞进来。 */
    private const ASSIGNMENT_WINDOW = 400;

    public function testToastRendersDialogTextWithoutHtml(): void
    {
        $js = $this->read(self::ROOT . '/public/assets/core/toast.js');

        $this->assertStringNotContainsString('innerHTML', $js, 'confirm 弹窗文案是纯文本通道，不得引入 innerHTML');
        foreach (self::TEXT_NODES as $id) {
            $this->assertStringContainsString($id, $js, 'toast.js 应负责渲染 #' . $id);
        }
    }

    public function testDialogBodyKeepsPreLineForNewlines(): void
    {
        $css = $this->read(self::ROOT . '/public/assets/admin.css');

        foreach (self::PRE_LINE_SELECTORS as $selector) {
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($selector, '/') . '\s*\{[^}]*white-space\s*:\s*pre-line/',
                $css,
                $selector . ' 需要 white-space:pre-line：弹窗纯文本里的 \n 靠它断行'
            );
        }
    }

    public function testDialogTextArgumentsCarryNoHtml(): void
    {
        $violations = [];
        foreach ($this->assetJsFiles() as $file) {
            $js = $this->read($file);
            foreach (self::TEXT_FIELDS as $field) {
                preg_match_all('/\b' . $field . '\s*:\s*([^,\n]+)/', $js, $matches);
                foreach ($matches[1] as $expr) {
                    $source = $this->resolveExpression($js, trim($expr));
                    if ($this->containsHtmlTag($source)) {
                        $violations[] = $this->relativePath($file) . ' → ' . $field . ': ' . trim($source);
                    }
                }
            }
        }

        $this->assertSame([], $violations, 'confirmDialog 的 message/note 只接受纯文本：'
            . '弹窗用 textContent 渲染，HTML 会原样显示成标签（多行请用 \\n + white-space:pre-line）');
    }

    /**
     * 表达式本身；若它是裸变量名，再附上该变量的赋值语句文本 ——
     * 覆盖「先把 HTML 拼进局部变量、再把变量传进 note」这类写法（本次回归即此形状）。
     */
    private function resolveExpression(string $js, string $expr): string
    {
        if (preg_match('/^[A-Za-z_$][\w$]*$/', $expr) !== 1) {
            return $expr;
        }

        $pattern = '/\b(?:const|let|var)\s+' . preg_quote($expr, '/')
            . '\s*=\s*([\s\S]{0,' . self::ASSIGNMENT_WINDOW . '}?)(?:;|$)/';

        return preg_match($pattern, $js, $m) === 1 ? $expr . ' ' . $m[1] : $expr;
    }

    /** 是否含 HTML 标签字面量（<div>、</p>、<br/> 等）。 */
    private function containsHtmlTag(string $source): bool
    {
        return preg_match('/<[a-zA-Z\/!][^<>\n]*>/', $source) === 1;
    }

    /**
     * 参与本约定的前端脚本：后台自身资产目录，排除 swagger-ui 第三方打包产物。
     *
     * @return list<string>
     */
    private function assetJsFiles(): array
    {
        $files = [];
        foreach ([
            self::ROOT . '/public/assets/*.js',
            self::ROOT . '/public/assets/modules/*.js',
            self::ROOT . '/public/assets/core/*.js',
        ] as $pattern) {
            $matched = glob($pattern);
            if ($matched === false) {
                continue;
            }
            foreach ($matched as $file) {
                if (!str_contains($file, 'swagger-ui')) {
                    $files[] = $file;
                }
            }
        }

        $this->assertNotEmpty($files, '应扫描到后台自身的前端脚本');
        sort($files);

        return $files;
    }

    private function relativePath(string $path): string
    {
        return str_replace([self::ROOT . '/', '\\'], ['', '/'], $path);
    }

    private function read(string $path): string
    {
        $content = file_get_contents($path);
        $this->assertIsString($content, '无法读取 ' . $path);

        return $content;
    }
}
