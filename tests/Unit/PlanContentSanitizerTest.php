<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Models\ResellerPlan;
use App\Services\PlanContentSanitizer;
use PHPUnit\Framework\TestCase;

class PlanContentSanitizerTest extends TestCase
{
    public function testKeepsSupportedPlanHtmlAndInlinePresentation(): void
    {
        $html = '<div style="padding:20px;color:#fff;background:linear-gradient(145deg,#1a1a1a,#0e0e0e);border-radius:14px">'
            . '<ul style="list-style:none;padding-left:0"><li><span style="display:inline-block">✔</span>每月 100G 流量</li></ul>'
            . '<a href="https://example.test" target="_blank">查看详情</a></div>';

        $safe = PlanContentSanitizer::sanitize($html);

        if (!class_exists(\DOMDocument::class)) {
            $this->assertStringContainsString('&lt;div', $safe);
            return;
        }

        $this->assertStringContainsString('style="padding:20px;color:#fff;background:linear-gradient(145deg,#1a1a1a,#0e0e0e);border-radius:14px"', $safe);
        $this->assertStringContainsString('<ul style="list-style:none;padding-left:0">', $safe);
        $this->assertStringContainsString('rel="noopener noreferrer"', $safe);
    }

    public function testRemovesExecutableMarkupAndUnsafeStyleOrUrls(): void
    {
        $html = '<div onclick="alert(1)" style="color:red;background-image:url(javascript:alert(2));position:fixed">Safe'
            . '<script>alert(3)</script><a href="javascript:alert(4)">link</a></div>';

        $safe = PlanContentSanitizer::sanitize($html);

        if (!class_exists(\DOMDocument::class)) {
            $this->assertStringContainsString('&lt;script', strtolower($safe));
            return;
        }

        $this->assertStringNotContainsString('onclick', strtolower($safe));
        $this->assertStringNotContainsString('<script', strtolower($safe));
        $this->assertStringNotContainsString('javascript:', strtolower($safe));
        $this->assertStringNotContainsString('position:fixed', strtolower($safe));
        $this->assertStringContainsString('color:red', $safe);
    }

    public function testDoesNotAlterJsonFeatureLists(): void
    {
        $json = '[{"support":true,"feature":"100G 流量"}]';

        $this->assertSame($json, PlanContentSanitizer::sanitize($json));
    }

    public function testEscapesNonFeatureJsonBeforeHtmlRendering(): void
    {
        $safe = PlanContentSanitizer::sanitize('["<img src=x onerror=alert(1)>"]');

        if (!class_exists(\DOMDocument::class)) {
            $this->assertStringContainsString('&lt;img', strtolower($safe));
            return;
        }

        $this->assertStringNotContainsString('<img', strtolower($safe));
        $this->assertStringNotContainsString('onerror', strtolower($safe));
    }

    public function testDoesNotLeaveHtmlInsideJsonObjectsExecutable(): void
    {
        $safe = PlanContentSanitizer::sanitize('{"description":"<img src=x onerror=alert(1)>"}');

        if (!class_exists(\DOMDocument::class)) {
            $this->assertStringContainsString('&lt;img', strtolower($safe));
            return;
        }

        $this->assertStringNotContainsString('onerror', strtolower($safe));
    }

    public function testPlanModelsSanitizeContentOnReadAndWrite(): void
    {
        foreach ([new Plan(), new ResellerPlan()] as $model) {
            $model->content = '<p style="color:red">Safe</p><script>alert(1)</script>';

            if (!class_exists(\DOMDocument::class)) {
                $this->assertStringContainsString('&lt;p', strtolower($model->getAttributes()['content']));
                continue;
            }

            $this->assertStringNotContainsString('<script', strtolower($model->getAttributes()['content']));
            $this->assertStringContainsString('<p style="color:red">Safe</p>', $model->content);
        }
    }
}
