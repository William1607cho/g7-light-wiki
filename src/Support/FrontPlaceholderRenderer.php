<?php

namespace Plugins\G7\Light\Wiki\Support;

/**
 * 자리표시(`[[#최근수정]]`·`[[#최근작성]]`·`[[#랜덤]]`·`[[#색인]]`·`[[#둘러보기]]`)를 HTML 로
 * 바꾸는 클래스.
 *
 * 데이터는 **호출부가 넣어 준다**(`$recent`·`$created`·`$index`·`$random` 클로저). 그래서 이
 * 클래스 자체는 DB 를 모르고, 단위 시험에서 가짜 목록만 넣어 치환 결과를 볼 수 있다.
 *
 * ## 어느 글에서 치환되는가 (1.5단계에서 넓어졌다)
 *
 * 1단계에서는 **대문 글에서만** 치환했다. 1.5단계부터는 같은 위키 게시판의 **HTML 모드
 * 문서라면 어디서든** 치환한다. 이 클래스는 "어느 글인지" 를 모르고, 호출부
 * ({@see \Plugins\G7\Light\Wiki\Http\Middleware\RenderWikiLinksExtension})가 후보에서 뺄
 * 글 ID 목록(대문 글 + 지금 그리는 그 문서)을 공급자 클로저에 미리 묶어 넘긴다.
 *
 * 클래스 이름의 `Front` 는 1단계에 붙은 이름이 남은 것이다 — 파일을 지웠다 만들면
 * `plugin:update` 가 옛 파일을 남길 수 있어 이름은 그대로 두었다.
 *
 * 요청자에게 그 게시판 읽기 권한이 없으면 자리표시를 **빈 문자열**로 바꾼다 — 목록을
 * 통째로 지우는 쪽이 "권한 없음" 문구로 존재를 알리는 것보다 낫다.
 */
final class FrontPlaceholderRenderer
{
    /**
     * @param  string  $slug  게시판 슬러그
     * @param  bool  $canRead  요청자에게 이 게시판 글 읽기 권한이 있는가
     * @param  \Closure(int): list<array{post_id: int, title: string}>  $recent  최근 수정 목록 공급자
     * @param  \Closure(int): list<array{post_id: int, title: string}>  $created  최근 작성 목록 공급자
     * @param  \Closure(): list<array{id: int, title: string, title_norm: string}>  $index  색인 목록 공급자
     * @param  \Closure(): ?int  $random  랜덤 문서 1건 공급자 (없으면 null)
     * @param  \Closure(int): list<array{post_id: int, title: string}>  $randomMany  랜덤 문서 N 건 공급자
     * @param  array{random: string, other: string, empty: string, tour_created: string, tour_random: string}  $labels  언어 파일 문구
     */
    public function __construct(
        private readonly string $slug,
        private readonly bool $canRead,
        private readonly \Closure $recent,
        private readonly \Closure $created,
        private readonly \Closure $index,
        private readonly \Closure $random,
        private readonly \Closure $randomMany,
        private readonly array $labels,
    ) {}

    /**
     * 자리표시 토큰 하나를 HTML 로 바꿉니다.
     *
     * @param  array<string, mixed>  $token  {@see WikiMarkupParser::tokenize()} 의 토큰
     * @return string|null 대체 HTML. `null` 이면 원문을 그대로 둔다.
     */
    public function render(array $token): ?string
    {
        if (($token['kind'] ?? null) !== WikiMarkupParser::KIND_PLACEHOLDER) {
            return null;
        }

        if (! $this->canRead) {
            return '';
        }

        $argument = $token['argument'] ?? null;
        $empty = $this->labels['empty'] ?? '';

        return match ($token['name'] ?? '') {
            WikiMarkupParser::PLACEHOLDER_RECENT => WikiHtml::recentList(
                $this->slug,
                ($this->recent)(self::limit($argument, WikiDocQuery::RECENT_DEFAULT, WikiDocQuery::RECENT_MAX)),
                $empty,
            ),
            WikiMarkupParser::PLACEHOLDER_RECENT_CREATED => WikiHtml::createdList(
                $this->slug,
                ($this->created)(self::limit($argument, WikiDocQuery::CREATED_DEFAULT, WikiDocQuery::RECENT_MAX)),
                $empty,
            ),
            WikiMarkupParser::PLACEHOLDER_RANDOM => $this->randomHtml($argument, $empty),
            WikiMarkupParser::PLACEHOLDER_INDEX => WikiHtml::indexList(
                $this->slug,
                WikiIndexBuilder::build(($this->index)()),
                $this->labels['other'] ?? WikiIndexBuilder::OTHER,
                $empty,
            ),
            WikiMarkupParser::PLACEHOLDER_TOUR => $this->tourHtml($argument, $empty),
            default => null,
        };
    }

    /**
     * 랜덤 — N 이 2 이상이면 서로 다른 문서 N 개의 목록, 그 밖에는 **단일 링크**.
     *
     * N 을 적지 않은 `[[#랜덤]]` 은 1단계와 같은 결과여야 한다(하위 호환). `|1` 도 같게 둔다 —
     * "1개짜리 목록" 과 "링크 하나" 중 눈에 익은 쪽을 고른다.
     */
    private function randomHtml(mixed $argument, string $empty): string
    {
        $count = self::optionalCount($argument, WikiDocQuery::RANDOM_MAX);

        if ($count !== null && $count >= 2) {
            return WikiHtml::randomList($this->slug, ($this->randomMany)($count), $empty);
        }

        $postId = ($this->random)();

        if (! is_int($postId) || $postId < 1) {
            return WikiHtml::randomEmpty($empty);
        }

        return WikiHtml::randomLink($this->slug, $postId, $this->labels['random'] ?? '');
    }

    /**
     * 둘러보기 — 왼쪽 최근 작성 N 개, 오른쪽 랜덤 N 개.
     */
    private function tourHtml(mixed $argument, string $empty): string
    {
        $limit = self::limit($argument, WikiDocQuery::TOUR_DEFAULT, WikiDocQuery::RECENT_MAX);

        return WikiHtml::tour(
            $this->slug,
            ($this->created)($limit),
            ($this->randomMany)($limit),
            $this->labels['tour_created'] ?? '',
            $this->labels['tour_random'] ?? '',
            $empty,
        );
    }

    /**
     * `[[#최근수정|N]]` 의 N — 기본 10, 최대 50. 숫자가 아니면 기본값.
     *
     * 1단계 공개 API 를 그대로 남긴다(다른 호출부·단위 시험이 쓴다).
     */
    public static function recentLimit(mixed $argument): int
    {
        return self::limit($argument, WikiDocQuery::RECENT_DEFAULT, WikiDocQuery::RECENT_MAX);
    }

    /**
     * 자리표시 인자 N 을 개수로 바꾼다 — 숫자가 아니거나 1 미만이면 기본값, 최대값에서 자른다.
     */
    public static function limit(mixed $argument, int $default, int $max): int
    {
        $count = self::optionalCount($argument, $max);

        return $count ?? $default;
    }

    /**
     * 인자가 "쓸 수 있는 숫자" 일 때만 개수를 돌려준다. 그 밖에는 null.
     *
     * `null` 과 기본값을 구분해야 하는 자리(`[[#랜덤]]` 의 하위 호환)가 있어 따로 둔다.
     */
    private static function optionalCount(mixed $argument, int $max): ?int
    {
        if (! is_string($argument)) {
            return null;
        }

        $trimmed = trim($argument);

        if ($trimmed === '' || ! ctype_digit($trimmed)) {
            return null;
        }

        $count = (int) $trimmed;

        if ($count < 1) {
            return null;
        }

        return min($count, $max);
    }
}
