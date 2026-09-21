<?php

namespace Plugins\G7\Light\Wiki\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Light\Wiki\Support\FrontPlaceholderRenderer;
use Plugins\G7\Light\Wiki\Support\WikiDocQuery;
use Plugins\G7\Light\Wiki\Support\WikiMarkupParser;

class FrontPlaceholderRendererTest extends TestCase
{
    /** 마지막으로 요청된 최근수정 개수 */
    private int $askedLimit = 0;

    private function renderer(bool $canRead = true, ?int $randomPostId = 12): FrontPlaceholderRenderer
    {
        return new FrontPlaceholderRenderer(
            'test-wiki',
            $canRead,
            function (int $limit): array {
                $this->askedLimit = $limit;

                return [
                    ['post_id' => 12, 'title' => '나중 문서'],
                    ['post_id' => 11, 'title' => '먼저 문서'],
                ];
            },
            static fn (): array => [
                ['id' => 11, 'title' => '가나다', 'title_norm' => '가나다'],
                ['id' => 12, 'title' => 'Apple', 'title_norm' => 'apple'],
            ],
            static fn (): ?int => $randomPostId,
            ['random' => '랜덤 문서', 'other' => '기타', 'empty' => '아직 문서가 없습니다.'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function token(string $name, ?string $argument = null): array
    {
        return [
            'kind' => WikiMarkupParser::KIND_PLACEHOLDER,
            'raw' => '[[#'.$name.']]',
            'offset' => 0,
            'length' => 0,
            'target' => '',
            'label' => null,
            'name' => $name,
            'argument' => $argument,
        ];
    }

    public function test_최근수정은_목록_링크를_만든다(): void
    {
        $html = $this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_RECENT));

        $this->assertStringContainsString('<ul class="g7lw-recent">', $html);
        $this->assertStringContainsString('href="/board/test-wiki/12"', $html);
        $this->assertStringContainsString('href="/board/test-wiki/11"', $html);
        // 목록 순서는 공급자가 준 순서 그대로다.
        $this->assertTrue(strpos($html, '/12') < strpos($html, '/11'));
    }

    public function test_최근수정_개수는_기본_10_최대_50_이다(): void
    {
        $this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_RECENT));
        $this->assertSame(WikiDocQuery::RECENT_DEFAULT, $this->askedLimit);

        $this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_RECENT, '20'));
        $this->assertSame(20, $this->askedLimit);

        $this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_RECENT, '999'));
        $this->assertSame(WikiDocQuery::RECENT_MAX, $this->askedLimit);

        $this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_RECENT, 'abc'));
        $this->assertSame(WikiDocQuery::RECENT_DEFAULT, $this->askedLimit);
    }

    public function test_랜덤은_치환_시점에_고른_문서로_가는_일반_링크다(): void
    {
        $html = $this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_RANDOM));

        // 플러그인 주소로 보내면 브라우저 전체 이동에 토큰이 실리지 않아 비회원으로 보인다.
        $this->assertStringNotContainsString('/api/plugins/', $html);
        $this->assertStringContainsString('href="/board/test-wiki/12"', $html);
        $this->assertStringContainsString('g7lw-random', $html);
        $this->assertStringContainsString('랜덤 문서', $html);
    }

    public function test_고를_문서가_없으면_링크_대신_안내_글자다(): void
    {
        $html = $this->renderer(randomPostId: null)->render($this->token(WikiMarkupParser::PLACEHOLDER_RANDOM));

        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('아직 문서가 없습니다.', $html);
    }

    public function test_색인은_묶음별_제목과_링크를_만든다(): void
    {
        $html = $this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_INDEX));

        $this->assertStringContainsString('class="g7lw-index"', $html);
        $this->assertStringContainsString('>ㄱ<', $html);
        $this->assertStringContainsString('>A<', $html);
        $this->assertStringContainsString('href="/board/test-wiki/11"', $html);
    }

    public function test_읽기_권한이_없으면_빈_문자열이다(): void
    {
        $renderer = $this->renderer(canRead: false);

        $this->assertSame('', $renderer->render($this->token(WikiMarkupParser::PLACEHOLDER_RECENT)));
        $this->assertSame('', $renderer->render($this->token(WikiMarkupParser::PLACEHOLDER_RANDOM)));
        $this->assertSame('', $renderer->render($this->token(WikiMarkupParser::PLACEHOLDER_INDEX)));
    }

    public function test_자리표시가_아닌_토큰은_건드리지_않는다(): void
    {
        $token = $this->token(WikiMarkupParser::PLACEHOLDER_RECENT);
        $token['kind'] = WikiMarkupParser::KIND_LINK;

        $this->assertNull($this->renderer()->render($token));
    }
}
