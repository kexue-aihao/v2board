<?php

namespace Tests\Unit;

use App\Models\Notice;
use App\Services\NoticeContentSanitizer;
use PHPUnit\Framework\TestCase;

class NoticeContentSanitizerTest extends TestCase
{
    public function testRemovesExecutableMarkupAndUnsafeAttributesWhileKeepingFormatting(): void
    {
        $html = '<p>Notice <strong>bold</strong></p>'
            . '<img src="https://example.test/image.png" onerror="alert(1)" alt="image">'
            . '<script>alert(2)</script>'
            . '<a href="java&#x09;script:alert(3)">unsafe</a>';

        $safe = NoticeContentSanitizer::sanitize($html);

        if (!class_exists(\DOMDocument::class)) {
            $this->assertStringNotContainsString('<img', $safe);
            $this->assertStringContainsString('&lt;script&gt;', $safe);
            return;
        }

        $this->assertStringContainsString('<p>Notice <strong>bold</strong></p>', $safe);
        $this->assertStringContainsString('<img src="https://example.test/image.png"', $safe);
        $this->assertStringNotContainsString('onerror', strtolower($safe));
        $this->assertStringNotContainsString('<script', strtolower($safe));
        $this->assertStringNotContainsString('alert(2)', $safe);
        $this->assertStringNotContainsString('javascript:', strtolower($safe));
        $this->assertStringContainsString('<a>unsafe</a>', $safe);
    }

    public function testNoticeModelSanitizesLegacyContentWhenRead(): void
    {
        $notice = new Notice();
        $notice->setRawAttributes([
            'content' => '<svg onload="alert(1)"><script>alert(2)</script></svg><p>Safe</p>'
        ], true);

        $content = $notice->content;

        if (!class_exists(\DOMDocument::class)) {
            $this->assertStringContainsString('&lt;svg', $content);
            $this->assertStringNotContainsString('<svg', $content);
            return;
        }

        $this->assertStringNotContainsString('<svg', strtolower($content));
        $this->assertStringNotContainsString('<script', strtolower($content));
        $this->assertStringNotContainsString('onload', strtolower($content));
        $this->assertStringContainsString('<p>Safe</p>', $content);
    }

    public function testNewNoticeContentIsSanitizedBeforePersistence(): void
    {
        $notice = new Notice();
        $notice->content = '<p>Safe</p><img src=x onerror="alert(1)">';

        $stored = $notice->getAttributes()['content'];

        if (!class_exists(\DOMDocument::class)) {
            $this->assertStringNotContainsString('<img', $stored);
            $this->assertStringContainsString('&lt;img', $stored);
            return;
        }

        $this->assertStringContainsString('<p>Safe</p>', $stored);
        $this->assertStringNotContainsString('onerror', strtolower($stored));
    }
}
