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

    /** 마지막으로 요청된 최근작성 개수 */
    private int $askedCreatedLimit = 0;

    /** 마지막으로 요청된 랜덤 목록 개수 */
    private int $askedRandomLimit = 0;

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
            function (int $limit): array {
                $this->askedCreatedLimit = $limit;

                return [
                    ['post_id' => 21, 'title' => '새 문서'],
                    ['post_id' => 20, 'title' => '옛 문서'],
                ];
            },
            static fn (): array => [
                ['id' => 11, 'title' => '가나다', 'title_norm' => '가나다'],
                ['id' => 12, 'title' => 'Apple', 'title_norm' => 'apple'],
            ],
            static fn (): ?int => $randomPostId,
            function (int $limit): array {
                $this->askedRandomLimit = $limit;

                return [
                    ['post_id' => 31, 'title' => '랜덤 하나'],
                    ['post_id' => 32, 'title' => '랜덤 둘'],
                ];
            },
            [
                'random' => '랜덤 문서',
                'other' => '기타',
                'empty' => '아직 문서가 없습니다.',
                'tour_created' => '최근 작성 문서',
                'tour_random' => '랜덤 문서',
            ],
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

    public function test_최근작성은_별도_class_의_목록을_만든다(): void
    {
        $html = $this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_RECENT_CREATED));

        $this->assertStringContainsString('<ul class="g7lw-created">', $html);
        $this->assertStringContainsString('href="/board/test-wiki/21"', $html);
        $this->assertStringContainsString('href="/board/test-wiki/20"', $html);
        // 최근수정 공급자는 부르지 않는다.
        $this->assertSame(0, $this->askedLimit);
    }

    public function test_최근작성_개수는_기본_5_최대_50_이다(): void
    {
        $this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_RECENT_CREATED));
        $this->assertSame(WikiDocQuery::CREATED_DEFAULT, $this->askedCreatedLimit);

        $this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_RECENT_CREATED, '3'));
        $this->assertSame(3, $this->askedCreatedLimit);

        $this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_RECENT_CREATED, '999'));
        $this->assertSame(WikiDocQuery::RECENT_MAX, $this->askedCreatedLimit);
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

    public function test_랜덤은_N_을_적지_않으면_단일_링크다(): void
    {
        $html = $this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_RANDOM));

        $this->assertStringNotContainsString('<ul', $html);
        // 여러 건 공급자는 부르지 않는다.
        $this->assertSame(0, $this->askedRandomLimit);
    }

    public function test_랜덤은_N_이_1_이면_단일_링크로_둔다(): void
    {
        $html = $this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_RANDOM, '1'));

        $this->assertStringNotContainsString('<ul', $html);
        $this->assertSame(0, $this->askedRandomLimit);
    }

    public function test_랜덤은_N_이_2_이상이면_목록이다(): void
    {
        $html = $this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_RANDOM, '4'));

        $this->assertStringContainsString('<ul class="g7lw-random-list">', $html);
        $this->assertStringContainsString('href="/board/test-wiki/31"', $html);
        $this->assertStringContainsString('href="/board/test-wiki/32"', $html);
        // 낱낱의 링크는 단일 랜덤 링크와 같은 class 를 쓴다.
        $this->assertStringContainsString('class="g7lw-random g7lw-link"', $html);
        $this->assertSame(4, $this->askedRandomLimit);
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

    public function test_둘러보기는_2단_블록이다(): void
    {
        $html = $this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_TOUR, '3'));

        // 바깥 블록의 배치는 인라인 style 로만 준다.
        $this->assertStringContainsString('style="display:flex;flex-wrap:wrap;gap:1.5rem"', $html);
        // 각 단은 좁은 화면에서 접힌다.
        $this->assertSame(2, substr_count($html, 'style="flex:1 1 16rem;min-width:0"'));
        // 머리글 2개.
        $this->assertSame(2, substr_count($html, '<h3 class="g7lw-tour-label">'));
        $this->assertStringContainsString('최근 작성 문서', $html);
        // 왼쪽은 최근 작성, 오른쪽은 랜덤.
        $this->assertTrue(strpos($html, 'g7lw-created') < strpos($html, 'g7lw-random-list'));
        $this->assertSame(3, $this->askedCreatedLimit);
        $this->assertSame(3, $this->askedRandomLimit);
    }

    public function test_둘러보기_개수는_기본_5_다(): void
    {
        $this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_TOUR));

        $this->assertSame(WikiDocQuery::TOUR_DEFAULT, $this->askedCreatedLimit);
        $this->assertSame(WikiDocQuery::TOUR_DEFAULT, $this->askedRandomLimit);
    }

    public function test_읽기_권한이_없으면_빈_문자열이다(): void
    {
        $renderer = $this->renderer(canRead: false);

        $this->assertSame('', $renderer->render($this->token(WikiMarkupParser::PLACEHOLDER_RECENT)));
        $this->assertSame('', $renderer->render($this->token(WikiMarkupParser::PLACEHOLDER_RECENT_CREATED)));
        $this->assertSame('', $renderer->render($this->token(WikiMarkupParser::PLACEHOLDER_RANDOM)));
        $this->assertSame('', $renderer->render($this->token(WikiMarkupParser::PLACEHOLDER_RANDOM, '4')));
        $this->assertSame('', $renderer->render($this->token(WikiMarkupParser::PLACEHOLDER_INDEX)));
        $this->assertSame('', $renderer->render($this->token(WikiMarkupParser::PLACEHOLDER_TOUR)));

        // 권한이 없으면 공급자를 아예 부르지 않는다.
        $this->assertSame(0, $this->askedLimit);
        $this->assertSame(0, $this->askedCreatedLimit);
        $this->assertSame(0, $this->askedRandomLimit);
    }

    public function test_제목은_HTML_이스케이프된다(): void
    {
        $renderer = new FrontPlaceholderRenderer(
            'test-wiki',
            true,
            static fn (int $limit): array => [],
            static fn (int $limit): array => [['post_id' => 7, 'title' => '<b>&"위험"</b>']],
            static fn (): array => [],
            static fn (): ?int => null,
            static fn (int $limit): array => [['post_id' => 8, 'title' => '<i>&\'x\'</i>']],
            [
                'random' => '랜덤 문서',
                'other' => '기타',
                'empty' => '아직 문서가 없습니다.',
                'tour_created' => '최근 작성 문서',
                'tour_random' => '랜덤 문서',
            ],
        );

        $created = $renderer->render($this->token(WikiMarkupParser::PLACEHOLDER_RECENT_CREATED));
        $this->assertStringNotContainsString('<b>', $created);
        $this->assertStringContainsString('&lt;b&gt;', $created);
        $this->assertStringContainsString('&amp;', $created);
        $this->assertStringContainsString('&quot;', $created);

        $random = $renderer->render($this->token(WikiMarkupParser::PLACEHOLDER_RANDOM, '2'));
        $this->assertStringNotContainsString('<i>', $random);
        $this->assertStringContainsString('&lt;i&gt;', $random);
        $this->assertStringContainsString('&#039;', $random);
    }

    public function test_자리표시가_아닌_토큰은_건드리지_않는다(): void
    {
        $token = $this->token(WikiMarkupParser::PLACEHOLDER_RECENT);
        $token['kind'] = WikiMarkupParser::KIND_LINK;

        $this->assertNull($this->renderer()->render($token));
    }
}
