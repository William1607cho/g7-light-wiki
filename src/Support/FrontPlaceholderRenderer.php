<?php

namespace Plugins\G7\Light\Wiki\Support;

/**
 * 대문 글의 자리표시(`[[#최근수정]]`·`[[#랜덤]]`·`[[#색인]]`)를 HTML 로 바꾸는 클래스.
 *
 * 데이터는 **호출부가 넣어 준다**(`$recent`·`$index` 클로저). 그래서 이 클래스 자체는
 * DB 를 모르고, 단위 시험에서 가짜 목록만 넣어 치환 결과를 볼 수 있다.
 *
 * 자리표시는 **대문 글에서만** 치환된다. 다른 글에서는 `[[#…]]` 가 원문 그대로 남는다
 * (호출부가 이 클래스를 아예 부르지 않는다).
 *
 * 요청자에게 그 게시판 읽기 권한이 없으면 자리표시를 **빈 문자열**로 바꾼다 — 목록을
 * 통째로 지우는 쪽이 "권한 없음" 문구로 존재를 알리는 것보다 낫다.
 */
final class FrontPlaceholderRenderer
{
    /**
     * @param  string  $slug  게시판 슬러그
     * @param  int  $boardId  게시판 ID
     * @param  bool  $canRead  요청자에게 이 게시판 글 읽기 권한이 있는가
     * @param  \Closure(int): list<array{post_id: int, title: string}>  $recent  최근 수정 목록 공급자
     * @param  \Closure(): list<array{id: int, title: string, title_norm: string}>  $index  색인 목록 공급자
     * @param  array{random: string, other: string, empty: string}  $labels  언어 파일 문구
     */
    public function __construct(
        private readonly string $slug,
        private readonly int $boardId,
        private readonly bool $canRead,
        private readonly \Closure $recent,
        private readonly \Closure $index,
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

        return match ($token['name'] ?? '') {
            WikiMarkupParser::PLACEHOLDER_RECENT => WikiHtml::recentList(
                $this->slug,
                ($this->recent)(self::recentLimit($token['argument'] ?? null)),
                $this->labels['empty'] ?? '',
            ),
            WikiMarkupParser::PLACEHOLDER_RANDOM => WikiHtml::randomLink(
                $this->boardId,
                $this->labels['random'] ?? '',
            ),
            WikiMarkupParser::PLACEHOLDER_INDEX => WikiHtml::indexList(
                $this->slug,
                WikiIndexBuilder::build(($this->index)()),
                $this->labels['other'] ?? WikiIndexBuilder::OTHER,
                $this->labels['empty'] ?? '',
            ),
            default => null,
        };
    }

    /**
     * `[[#최근수정|N]]` 의 N — 기본 10, 최대 50. 숫자가 아니면 기본값.
     */
    public static function recentLimit(mixed $argument): int
    {
        if (! is_string($argument) || ! ctype_digit(trim($argument))) {
            return WikiDocQuery::RECENT_DEFAULT;
        }

        $limit = (int) trim($argument);

        if ($limit < 1) {
            return WikiDocQuery::RECENT_DEFAULT;
        }

        return min($limit, WikiDocQuery::RECENT_MAX);
    }
}
