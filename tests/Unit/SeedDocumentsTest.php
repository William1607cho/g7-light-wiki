<?php

namespace Plugins\G7\Light\Wiki\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Light\Wiki\Support\Setup\SeedDocuments;

/**
 * 시드 문서 3건 — 제목·순서·본문.
 */
class SeedDocumentsTest extends TestCase
{
    public function test_세_건을_정해진_순서로_만든다(): void
    {
        $keys = array_column(SeedDocuments::all(), 'key');

        $this->assertSame([SeedDocuments::INDEX, SeedDocuments::SYNTAX, SeedDocuments::FRONT], $keys);
    }

    public function test_제목(): void
    {
        $titles = array_column(SeedDocuments::all(), 'title', 'key');

        $this->assertSame('모든 문서', $titles[SeedDocuments::INDEX]);
        $this->assertSame('위키 문법 도움말', $titles[SeedDocuments::SYNTAX]);
        $this->assertSame('대문', $titles[SeedDocuments::FRONT]);
    }

    public function test_모두_HTML_모드다(): void
    {
        foreach (SeedDocuments::all() as $seed) {
            $this->assertSame('html', $seed['content_mode']);
        }
    }

    public function test_대문은_인사말_둘러보기_두_문서_링크(): void
    {
        $front = SeedDocuments::body(SeedDocuments::FRONT);

        $this->assertStringContainsString('<p>[[#둘러보기]]</p>', $front);
        $this->assertStringContainsString('[[모든 문서]]', $front);
        $this->assertStringContainsString('[[위키 문법 도움말]]', $front);
        // 인사말 한 줄 + 둘러보기 + 링크 줄
        $this->assertSame(3, substr_count($front, '<p>'));
    }

    public function test_대문_링크는_다른_시드의_제목과_글자가_같다(): void
    {
        $front = SeedDocuments::body(SeedDocuments::FRONT);

        $this->assertStringContainsString('[['.SeedDocuments::TITLES[SeedDocuments::INDEX].']]', $front);
        $this->assertStringContainsString('[['.SeedDocuments::TITLES[SeedDocuments::SYNTAX].']]', $front);
    }

    public function test_모든_문서는_색인_한_줄(): void
    {
        $this->assertSame('<p>[[#색인]]</p>', SeedDocuments::body(SeedDocuments::INDEX));
    }

    public function test_문법_문서는_절_제목_일곱_개를_갖는다(): void
    {
        preg_match_all('#<h2>(.*?)</h2>#u', SeedDocuments::body(SeedDocuments::SYNTAX), $m);

        $this->assertSame([
            '문서 링크',
            '분류와 별칭',
            '연표',
            '자리표시',
            '문서 아래에 자동으로 붙는 것',
            '표기를 글자 그대로 보여 주려면',
            '표기가 되지 않는 경우',
        ], $m[1]);
    }

    public function test_문법_문서의_예시_표기는_모두_코드_서식_안에_있다(): void
    {
        $syntax = SeedDocuments::body(SeedDocuments::SYNTAX);

        // 코드 서식 밖의 `[[` 가 하나라도 있으면 그 표기가 치환되고 분류·별칭·연표·역링크로 등록된다.
        $outside = preg_replace('#<code>.*?</code>#us', '', $syntax);

        $this->assertGreaterThan(0, substr_count($syntax, '[['));
        $this->assertSame(0, substr_count($outside, '[['));
    }

    public function test_문법_문서에는_달러_기호가_없다(): void
    {
        // 번역 토큰 모양은 코드 서식 안에서도 방문자·봇 화면이 번역으로 바꾼다 — 어떤 형태로도 넣지 않는다.
        $this->assertStringNotContainsString('$', SeedDocuments::body(SeedDocuments::SYNTAX));
    }

    public function test_문법_문서는_정제기가_허용하는_태그만_쓴다(): void
    {
        preg_match_all('#</?([a-z0-9]+)#', SeedDocuments::body(SeedDocuments::SYNTAX), $m);
        $tags = array_values(array_unique($m[1]));
        sort($tags);

        $this->assertSame(['code', 'h2', 'li', 'p', 'ul'], $tags);
    }

    public function test_검색창_폼과_번역_토큰이_없다(): void
    {
        foreach (SeedDocuments::all() as $seed) {
            $this->assertStringNotContainsString('<form', $seed['content']);
            $this->assertStringNotContainsString('<input', $seed['content']);
            $this->assertStringNotContainsString('$t:', $seed['content']);
        }
    }

    public function test_모르는_시드는_예외(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SeedDocuments::body('nope');
    }
}
