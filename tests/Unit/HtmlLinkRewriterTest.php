<?php

namespace Plugins\G7\Light\Wiki\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Light\Wiki\Support\HtmlLinkRewriter;
use Plugins\G7\Light\Wiki\Support\WikiMarkupParser;

class HtmlLinkRewriterTest extends TestCase
{
    /**
     * 시험용 판정기 — 문서 링크는 모두 `<a class="t">`, 그 밖은 원문 유지.
     */
    private function resolver(): \Closure
    {
        return static function (array $token): ?string {
            if ($token['kind'] !== WikiMarkupParser::KIND_LINK) {
                return null;
            }

            return '<a class="t" href="/x">'.htmlspecialchars($token['label'] ?? $token['target']).'</a>';
        };
    }

    public function test_표기가_없으면_입력_바이트가_그대로다(): void
    {
        $html = '<p>바꿀 것이 없는 본문 <img src="/a.png"> <br></p>';
        $rewriter = new HtmlLinkRewriter($html);

        $this->assertFalse($rewriter->hasMarkup());
        $this->assertSame($html, $rewriter->rewrite($this->resolver()));
    }

    public function test_텍스트_노드의_표기를_링크로_바꾼다(): void
    {
        $out = (new HtmlLinkRewriter('<p>보기: [[설치 방법]]</p>'))->rewrite($this->resolver());

        $this->assertStringContainsString('<a class="t" href="/x">설치 방법</a>', $out);
        $this->assertStringNotContainsString('[[설치 방법]]', $out);
    }

    public function test_code_안의_표기는_건드리지_않는다(): void
    {
        $out = (new HtmlLinkRewriter('<p><code>[[설치 방법]]</code></p>'))->rewrite($this->resolver());

        $this->assertStringContainsString('[[설치 방법]]', $out);
        $this->assertStringNotContainsString('class="t"', $out);
    }

    public function test_pre_안의_표기는_건드리지_않는다(): void
    {
        $out = (new HtmlLinkRewriter('<pre>[[설치 방법]]</pre>'))->rewrite($this->resolver());

        $this->assertStringContainsString('[[설치 방법]]', $out);
    }

    public function test_a_안의_표기는_건드리지_않는다(): void
    {
        $out = (new HtmlLinkRewriter('<p><a href="/z">[[설치 방법]]</a></p>'))->rewrite($this->resolver());

        $this->assertStringContainsString('[[설치 방법]]', $out);
        $this->assertStringNotContainsString('class="t"', $out);
    }

    public function test_예약_표기는_원문_그대로_남는다(): void
    {
        $out = (new HtmlLinkRewriter('<p>[[분류:인물]] [[연표:1.0|x]]</p>'))->rewrite($this->resolver());

        $this->assertStringContainsString('[[분류:인물]]', $out);
        $this->assertStringContainsString('[[연표:1.0|x]]', $out);
    }

    public function test_한_문단에_있는_표기와_코드를_함께_다룬다(): void
    {
        $out = (new HtmlLinkRewriter('<p>[[가]] 와 <code>[[나]]</code> 와 [[다]]</p>'))
            ->rewrite($this->resolver());

        $this->assertSame(2, substr_count($out, 'class="t"'));
        $this->assertStringContainsString('<code>[[나]]</code>', $out);
    }

    public function test_링크_대상을_중복_없이_등장_순서로_모은다(): void
    {
        $rewriter = new HtmlLinkRewriter('<p>[[가]] [[나]] [[가]] <code>[[다]]</code> [[분류:라]]</p>');

        $this->assertSame(['가', '나', '다'], array_slice($rewriter->linkTargets(), 0, 3));
        $this->assertNotContains('분류:라', $rewriter->linkTargets());
    }

    public function test_여러_노드에_걸친_표기는_표기가_아니다(): void
    {
        $out = (new HtmlLinkRewriter('<p>[[문서<b>명</b>]]</p>'))->rewrite($this->resolver());

        $this->assertStringNotContainsString('class="t"', $out);
    }

    public function test_판정기가_null_이면_원문을_그대로_둔다(): void
    {
        $html = '<p>[[설치 방법]]</p>';
        $out = (new HtmlLinkRewriter($html))->rewrite(static fn (array $token): ?string => null);

        $this->assertSame($html, $out);
    }
}
