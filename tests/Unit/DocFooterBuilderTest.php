<?php

namespace Tests\Unit;

use Plugins\G7\Light\Wiki\Support\DocFooterBuilder;
use Plugins\G7\Light\Wiki\Support\WikiLabels;
use PHPUnit\Framework\TestCase;

/**
 * 문서 뒤 자동 영역 조립 — 순서, 빈 것 생략, 이스케이프.
 */
class DocFooterBuilderTest extends TestCase
{
    /**
     * 문구는 `WikiLabels` 로 넘어온다 — 전에는 배열 두 개였다.
     * 언어 파일을 읽지 않으므로 이 시험은 여전히 Laravel 없이 돈다.
     */
    private function labels(): WikiLabels
    {
        return WikiLabels::of([
            'doc.categories' => '분류',
            'doc.category_members' => '이 분류에 속한 문서',
            'doc.aliases' => '다른 이름',
            'doc.backlinks' => '이 문서를 가리키는 문서',
            'doc.more' => '외 :count건',
            'doc.empty' => '아직 문서가 없습니다.',
        ]);
    }

    public function test_붙일_것이_없으면_빈_문자열이다(): void
    {
        $this->assertSame('', DocFooterBuilder::build('test-wiki', [], $this->labels()));
        $this->assertSame('', DocFooterBuilder::build('test-wiki', [
            'aliases' => [],
            'categories' => [],
            'backlinks' => ['items' => [], 'more' => 0],
            'category_members' => null,
        ], $this->labels()));
    }

    public function test_역링크가_0건이면_머리글째_빠진다(): void
    {
        $html = DocFooterBuilder::build('test-wiki', [
            'backlinks' => ['items' => [], 'more' => 0],
            'categories' => ['<a href="/x">인물</a>'],
        ], $this->labels());

        $this->assertStringNotContainsString('이 문서를 가리키는 문서', $html);
        $this->assertStringContainsString('인물', $html);
    }

    public function test_순서는_소속목록_별칭_역링크_분류_다(): void
    {
        $html = DocFooterBuilder::build('test-wiki', [
            'category_members' => ['items' => [['post_id' => 1, 'title' => '가']], 'more' => 0],
            'aliases' => ['다른이름'],
            'backlinks' => ['items' => [['post_id' => 2, 'title' => '나']], 'more' => 0],
            'categories' => ['<a href="/x">인물</a>'],
        ], $this->labels());

        $positions = [
            '이 분류에 속한 문서' => strpos($html, '이 분류에 속한 문서'),
            '다른 이름' => strpos($html, '다른 이름'),
            '이 문서를 가리키는 문서' => strpos($html, '이 문서를 가리키는 문서'),
            '분류:' => strpos($html, '분류: '),
        ];

        $values = array_values($positions);
        $sorted = $values;
        sort($sorted);

        $this->assertSame($sorted, $values, '자동 영역의 순서가 어긋났다');
    }

    public function test_한_껍데기로_묶인다(): void
    {
        $html = DocFooterBuilder::build('test-wiki', [
            'aliases' => ['가명'],
            'categories' => ['<a href="/x">인물</a>'],
        ], $this->labels());

        $this->assertSame(1, substr_count($html, 'class="g7lw-footer"'));
    }

    public function test_별칭은_이스케이프된다(): void
    {
        $html = DocFooterBuilder::build('test-wiki', [
            'aliases' => ['<b>&"위험"</b>'],
        ], $this->labels());

        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
        $this->assertStringContainsString('&amp;', $html);
    }

    public function test_문서_제목도_이스케이프된다(): void
    {
        $html = DocFooterBuilder::build('test-wiki', [
            'backlinks' => ['items' => [['post_id' => 3, 'title' => '<i>&\'x\'</i>']], 'more' => 0],
        ], $this->labels());

        $this->assertStringNotContainsString('<i>', $html);
        $this->assertStringContainsString('&lt;i&gt;', $html);
    }

    public function test_넘친_건수는_외_N건으로_나온다(): void
    {
        $html = DocFooterBuilder::build('test-wiki', [
            'backlinks' => ['items' => [['post_id' => 3, 'title' => '가']], 'more' => 7],
        ], $this->labels());

        $this->assertStringContainsString('외 7건', $html);
    }

    public function test_넘치지_않으면_외_N건이_없다(): void
    {
        $html = DocFooterBuilder::build('test-wiki', [
            'backlinks' => ['items' => [['post_id' => 3, 'title' => '가']], 'more' => 0],
        ], $this->labels());

        $this->assertStringNotContainsString('외 ', $html);
    }
}
