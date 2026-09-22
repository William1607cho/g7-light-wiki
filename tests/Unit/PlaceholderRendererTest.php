<?php

namespace Plugins\G7\Light\Wiki\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Light\Wiki\Support\Placeholders\DocListSource;
use Plugins\G7\Light\Wiki\Support\Placeholders\PlaceholderRenderer;
use Plugins\G7\Light\Wiki\Support\WikiDocQuery;
use Plugins\G7\Light\Wiki\Support\WikiLabels;
use Plugins\G7\Light\Wiki\Support\WikiMarkupParser;

/**
 * 자리표시 디스패처와 기능별 렌더러.
 *
 * 전에는 `FrontPlaceholderRendererTest` 가 클로저 여섯 개를 생성자에 밀어 넣어 시험했다.
 * 지금은 목록 공급자가 인터페이스 하나라서 가짜 구현 한 개로 끝난다.
 *
 * 머리글 강조 style 은 `WikiHtml::LABEL_STYLE` 과 같은 값을 쓴다 — 여기 글자로 박아 두면
 * 본문 대비 배율(`em`)로 바뀐 것을 시험이 따라가지 못한다(전 시험이 `1.125rem` 에서 멈춰 있었다).
 */
class PlaceholderRendererTest extends TestCase
{
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
        $source = $this->source();
        $this->renderer($source)->render($this->token(WikiMarkupParser::PLACEHOLDER_RECENT));
        $this->assertSame(WikiDocQuery::RECENT_DEFAULT, $source->askedRecent);

        $this->renderer($source)->render($this->token(WikiMarkupParser::PLACEHOLDER_RECENT, '20'));
        $this->assertSame(20, $source->askedRecent);

        $this->renderer($source)->render($this->token(WikiMarkupParser::PLACEHOLDER_RECENT, '999'));
        $this->assertSame(WikiDocQuery::RECENT_MAX, $source->askedRecent);

        $this->renderer($source)->render($this->token(WikiMarkupParser::PLACEHOLDER_RECENT, 'abc'));
        $this->assertSame(WikiDocQuery::RECENT_DEFAULT, $source->askedRecent);
    }

    public function test_최근작성은_별도_class_의_목록을_만든다(): void
    {
        $source = $this->source();
        $html = $this->renderer($source)->render($this->token(WikiMarkupParser::PLACEHOLDER_RECENT_CREATED));

        $this->assertStringContainsString('<ul class="g7lw-created">', $html);
        $this->assertStringContainsString('href="/board/test-wiki/21"', $html);
        $this->assertStringContainsString('href="/board/test-wiki/20"', $html);
        // 최근수정 공급자는 부르지 않는다.
        $this->assertSame(0, $source->askedRecent);
    }

    public function test_최근작성_개수는_기본_5_최대_50_이다(): void
    {
        $source = $this->source();
        $this->renderer($source)->render($this->token(WikiMarkupParser::PLACEHOLDER_RECENT_CREATED));
        $this->assertSame(WikiDocQuery::CREATED_DEFAULT, $source->askedCreated);

        $this->renderer($source)->render($this->token(WikiMarkupParser::PLACEHOLDER_RECENT_CREATED, '3'));
        $this->assertSame(3, $source->askedCreated);

        $this->renderer($source)->render($this->token(WikiMarkupParser::PLACEHOLDER_RECENT_CREATED, '999'));
        $this->assertSame(WikiDocQuery::RECENT_MAX, $source->askedCreated);
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
        $source = $this->source();
        $html = $this->renderer($source)->render($this->token(WikiMarkupParser::PLACEHOLDER_RANDOM));

        $this->assertStringNotContainsString('<ul', $html);
        // 여러 건 공급자는 부르지 않는다.
        $this->assertSame(0, $source->askedRandom);
    }

    public function test_랜덤은_N_이_1_이면_단일_링크로_둔다(): void
    {
        $source = $this->source();
        $html = $this->renderer($source)->render($this->token(WikiMarkupParser::PLACEHOLDER_RANDOM, '1'));

        $this->assertStringNotContainsString('<ul', $html);
        $this->assertSame(0, $source->askedRandom);
    }

    public function test_랜덤은_N_이_2_이상이면_목록이다(): void
    {
        $source = $this->source();
        $html = $this->renderer($source)->render($this->token(WikiMarkupParser::PLACEHOLDER_RANDOM, '4'));

        $this->assertStringContainsString('<ul class="g7lw-random-list">', $html);
        $this->assertStringContainsString('href="/board/test-wiki/31"', $html);
        $this->assertStringContainsString('href="/board/test-wiki/32"', $html);
        // 낱낱의 링크는 단일 랜덤 링크와 같은 class 를 쓴다.
        $this->assertStringContainsString('class="g7lw-random g7lw-link"', $html);
        $this->assertSame(4, $source->askedRandom);
    }

    public function test_고를_문서가_없으면_링크_대신_안내_글자다(): void
    {
        $source = $this->source();
        $source->randomPostId = null;

        $html = $this->renderer($source)->render($this->token(WikiMarkupParser::PLACEHOLDER_RANDOM));

        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('아직 문서가 없습니다.', $html);
    }

    public function test_색인은_묶음별_제목과_링크를_만든다(): void
    {
        $html = $this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_INDEX));

        $this->assertStringContainsString('class="g7lw-index"', $html);
        // 묶음 머리글도 둘러보기와 같은 강조를 인라인 style 로 받는다.
        $this->assertStringContainsString('<h3 class="g7lw-index-label" style="', $html);
        $this->assertStringContainsString('>ㄱ<', $html);
        $this->assertStringContainsString('>A<', $html);
        $this->assertStringContainsString('href="/board/test-wiki/11"', $html);
    }

    public function test_색인은_기본_3열까지_펼친다(): void
    {
        $html = $this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_INDEX));

        // 배치는 인라인 style 로만 준다. 열너비가 있어 좁은 화면에서는 저절로 줄어든다.
        $this->assertStringContainsString('style="columns:16rem 3;column-gap:1.5rem"', $html);
        // 자음 묶음은 열 경계에서 쪼개지지 않는다 — 묶음 수만큼 있어야 한다.
        $this->assertSame(
            substr_count($html, 'class="g7lw-index-group"'),
            substr_count($html, 'style="break-inside:avoid"')
        );
        $this->assertSame(2, substr_count($html, 'style="break-inside:avoid"'));
    }

    public function test_색인_열_수는_인자로_받고_1_4_밖은_기본값이다(): void
    {
        foreach (['1' => 1, '2' => 2, '3' => 3, '4' => 4] as $argument => $columns) {
            $html = $this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_INDEX, (string) $argument));

            $this->assertStringContainsString('style="columns:16rem '.$columns.';column-gap:1.5rem"', $html);
        }

        // 범위 밖·숫자 아님·인자 없음은 오류를 내지 않고 기본값 3 이다.
        foreach (['5', '9', '999', '0', '-1', 'abc', '', ' ', '2.5', null] as $argument) {
            $html = $this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_INDEX, $argument));

            $this->assertStringContainsString('style="columns:16rem 3;column-gap:1.5rem"', $html);
        }
    }

    public function test_색인이_비어_있으면_다열_배치를_주지_않는다(): void
    {
        $source = $this->source();
        $source->indexItems = [];

        $html = $this->renderer($source)->render($this->token(WikiMarkupParser::PLACEHOLDER_INDEX));

        $this->assertStringNotContainsString('columns:', $html);
        $this->assertStringContainsString('아직 문서가 없습니다.', $html);
    }

    public function test_둘러보기는_2단_블록이다(): void
    {
        $source = $this->source();
        $html = $this->renderer($source)->render($this->token(WikiMarkupParser::PLACEHOLDER_TOUR, '3'));

        // 바깥 블록의 배치는 인라인 style 로만 준다.
        $this->assertStringContainsString('style="display:flex;flex-wrap:wrap;gap:1.5rem"', $html);
        // 각 단은 좁은 화면에서 접힌다.
        $this->assertSame(2, substr_count($html, 'style="flex:1 1 16rem;min-width:0"'));
        // 머리글 2개. 강조도 인라인 style 로만 준다(색은 상속 — 다크 모드).
        $this->assertSame(2, substr_count($html, '<h3 class="g7lw-tour-label" style="'));
        $this->assertStringContainsString('최근 작성 문서', $html);
        // 왼쪽은 최근 작성, 오른쪽은 랜덤.
        $this->assertTrue(strpos($html, 'g7lw-created') < strpos($html, 'g7lw-random-list'));
        $this->assertSame(3, $source->askedCreated);
        $this->assertSame(3, $source->askedRandom);
    }

    public function test_둘러보기_개수는_기본_5_다(): void
    {
        $source = $this->source();
        $this->renderer($source)->render($this->token(WikiMarkupParser::PLACEHOLDER_TOUR));

        $this->assertSame(WikiDocQuery::TOUR_DEFAULT, $source->askedCreated);
        $this->assertSame(WikiDocQuery::TOUR_DEFAULT, $source->askedRandom);
    }

    public function test_분류_목록은_이름이_없으면_원문을_그대로_둔다(): void
    {
        $this->assertNull($this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_CATEGORY)));
        $this->assertNull($this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_CATEGORY, '  ')));
    }

    public function test_분류_목록은_속한_문서를_그린다(): void
    {
        $html = $this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_CATEGORY, '인물'));

        $this->assertStringContainsString('class="g7lw-category-members"', $html);
        $this->assertStringContainsString('href="/board/test-wiki/41"', $html);
        $this->assertStringContainsString('이 분류에 속한 문서', $html);
    }

    public function test_분류_목록이_0건이면_안내_한_줄이다(): void
    {
        $html = $this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_CATEGORY, '없는분류'));

        $this->assertStringContainsString('class="g7lw-category-members g7lw-empty"', $html);
        $this->assertStringNotContainsString('<ul', $html);
    }

    public function test_연표는_키와_설명과_출처를_그린다(): void
    {
        $html = $this->renderer()->render($this->token(WikiMarkupParser::PLACEHOLDER_TIMELINE));

        $this->assertStringContainsString('<ul class="g7lw-timeline">', $html);
        $this->assertStringContainsString('g7lw-event-key', $html);
        $this->assertStringContainsString('href="/board/test-wiki/61"', $html);
    }

    public function test_연표에_사건이_없으면_안내_글자다(): void
    {
        $source = $this->source();
        $source->timelineItems = [];

        $html = $this->renderer($source)->render($this->token(WikiMarkupParser::PLACEHOLDER_TIMELINE));

        $this->assertStringContainsString('아직 사건이 없습니다.', $html);
    }

    public function test_읽기_권한이_없으면_빈_문자열이다(): void
    {
        $source = $this->source();
        $renderer = $this->renderer($source, canRead: false);

        foreach ([
            WikiMarkupParser::PLACEHOLDER_RECENT,
            WikiMarkupParser::PLACEHOLDER_RECENT_CREATED,
            WikiMarkupParser::PLACEHOLDER_RANDOM,
            WikiMarkupParser::PLACEHOLDER_INDEX,
            WikiMarkupParser::PLACEHOLDER_TOUR,
            WikiMarkupParser::PLACEHOLDER_CATEGORY,
            WikiMarkupParser::PLACEHOLDER_TIMELINE,
        ] as $name) {
            $this->assertSame('', $renderer->render($this->token($name)));
        }

        $this->assertSame('', $renderer->render($this->token(WikiMarkupParser::PLACEHOLDER_RANDOM, '4')));

        // 권한이 없으면 공급자를 아예 부르지 않는다.
        $this->assertSame(0, $source->askedRecent);
        $this->assertSame(0, $source->askedCreated);
        $this->assertSame(0, $source->askedRandom);
    }

    public function test_읽기_권한이_없으면_모르는_이름도_지워진다(): void
    {
        // 권한 판정이 이름 조회보다 먼저다 — 전 구현과 같은 순서다.
        $this->assertSame('', $this->renderer(canRead: false)->render($this->token('없는자리표시')));
    }

    public function test_모르는_이름은_원문을_그대로_둔다(): void
    {
        $this->assertNull($this->renderer()->render($this->token('없는자리표시')));
    }

    public function test_제목은_HTML_이스케이프된다(): void
    {
        $source = $this->source();
        $source->createdItems = [['post_id' => 7, 'title' => '<b>&"위험"</b>']];
        $source->randomManyItems = [['post_id' => 8, 'title' => '<i>&\'x\'</i>']];

        $renderer = $this->renderer($source);

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

    // ── 도구 ────────────────────────────────────────────────────────────────

    private function renderer(?FakeDocListSource $source = null, bool $canRead = true): PlaceholderRenderer
    {
        return PlaceholderRenderer::forDoc('test-wiki', $canRead, $source ?? $this->source(), $this->labels());
    }

    private function source(): FakeDocListSource
    {
        return new FakeDocListSource;
    }

    private function labels(): WikiLabels
    {
        return WikiLabels::of([
            'front.random' => '랜덤 문서',
            'front.other' => '기타',
            'front.empty' => '아직 문서가 없습니다.',
            'front.tour_created' => '최근 작성 문서',
            'front.tour_random' => '랜덤 문서',
            'doc.categories' => '분류',
            'doc.category_members' => '이 분류에 속한 문서',
            'doc.aliases' => '다른 이름',
            'doc.backlinks' => '이 문서를 가리키는 문서',
            'doc.more' => '외 :count건',
            'doc.empty' => '아직 문서가 없습니다.',
            'doc.timeline_empty' => '아직 사건이 없습니다.',
        ]);
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
}

/**
 * 가짜 목록 공급자 — 무엇을 몇 개 물었는지 기억한다. DB 를 쓰지 않는다.
 */
class FakeDocListSource implements DocListSource
{
    public int $askedRecent = 0;

    public int $askedCreated = 0;

    public int $askedRandom = 0;

    public ?int $randomPostId = 12;

    /** @var list<array{post_id: int, title: string}> */
    public array $createdItems = [
        ['post_id' => 21, 'title' => '새 문서'],
        ['post_id' => 20, 'title' => '옛 문서'],
    ];

    /** @var list<array{post_id: int, title: string}> */
    public array $randomManyItems = [
        ['post_id' => 31, 'title' => '랜덤 하나'],
        ['post_id' => 32, 'title' => '랜덤 둘'],
    ];

    /** @var list<array{id: int, title: string, title_norm: string}> */
    public array $indexItems = [
        ['id' => 11, 'title' => '가나다', 'title_norm' => '가나다'],
        ['id' => 12, 'title' => 'Apple', 'title_norm' => 'apple'],
    ];

    /** @var list<array{post_id: int, title: string, target: string, label: string}> */
    public array $timelineItems = [
        ['post_id' => 61, 'title' => '홍길동', 'target' => '1023', 'label' => '태어남'],
    ];

    public function recent(int $limit): array
    {
        $this->askedRecent = $limit;

        return [
            ['post_id' => 12, 'title' => '나중 문서'],
            ['post_id' => 11, 'title' => '먼저 문서'],
        ];
    }

    public function created(int $limit): array
    {
        $this->askedCreated = $limit;

        return $this->createdItems;
    }

    public function index(): array
    {
        return $this->indexItems;
    }

    public function randomOne(): ?int
    {
        return $this->randomPostId;
    }

    public function randomMany(int $limit): array
    {
        $this->askedRandom = $limit;

        return $this->randomManyItems;
    }

    public function membersOf(string $name): array
    {
        if ($name !== '인물') {
            return ['items' => [], 'more' => 0];
        }

        return ['items' => [['post_id' => 41, 'title' => '어떤 인물']], 'more' => 0];
    }

    public function timeline(?string $docName): array
    {
        return ['items' => $this->timelineItems, 'more' => 0];
    }
}
