<?php

namespace Plugins\G7\Light\Wiki\Support;

/**
 * 본문 표기 `[[…]]` 를 토큰으로 끊어 내는 순수 클래스 (DB·HTTP·설정 접근 없음).
 *
 * **문자열 하나만** 본다. "어디에 있는 문자열인지"(`<code>` 안인지, 대문 글인지)는
 * {@see HtmlLinkRewriter} 와 호출부가 정한다.
 *
 * ## 표기
 *
 * | 표기 | 종류 | 처리 |
 * |---|---|---|
 * | `[[문서명]]`, `[[문서명\|표시 글자]]` | `link` | 문서 링크로 바꾼다 |
 * | `[[#최근수정]]`, `[[#최근작성]]`, `[[#랜덤]]`, `[[#색인]]`, `[[#둘러보기]]` (각각 `\|N` 가능) | `placeholder` | 위키 게시판의 HTML 모드 문서에서 치환한다 |
 * | `[[분류:…]]`, `[[연표:…]]` | `reserved` | 원문 그대로 둔다 (다음 단계 예약) |
 *
 * 1.5단계부터 자리표시는 **대문 글 전용이 아니다** — 같은 위키 게시판의 모든 HTML 모드
 * 문서에서 치환된다. 이 클래스는 어차피 "어느 글인지" 를 모르고 문자열만 본다.
 *
 * 그 밖의 `[[…]]`(빈 이름, 대괄호가 더 든 것, 닫히지 않은 것)은 **토큰이 아니다** —
 * 원문 그대로 남는다. 여러 줄·여러 노드에 걸친 표기는 다루지 않는다.
 */
final class WikiMarkupParser
{
    /** 문서 링크 */
    public const KIND_LINK = 'link';

    /** 대문 자리표시 */
    public const KIND_PLACEHOLDER = 'placeholder';

    /** 예약 표기 — 1단계에서는 원문 유지 */
    public const KIND_RESERVED = 'reserved';

    /** 자리표시 이름 (`#` 뒤) */
    public const PLACEHOLDER_RECENT = '최근수정';

    public const PLACEHOLDER_RECENT_CREATED = '최근작성';

    public const PLACEHOLDER_RANDOM = '랜덤';

    public const PLACEHOLDER_INDEX = '색인';

    public const PLACEHOLDER_TOUR = '둘러보기';

    /** 예약 접두어 */
    private const RESERVED_PREFIXES = ['분류:', '연표:'];

    /** 알려진 자리표시 이름 */
    private const PLACEHOLDERS = [
        self::PLACEHOLDER_RECENT,
        self::PLACEHOLDER_RECENT_CREATED,
        self::PLACEHOLDER_RANDOM,
        self::PLACEHOLDER_INDEX,
        self::PLACEHOLDER_TOUR,
    ];

    /**
     * 문자열에서 표기 토큰을 순서대로 찾습니다.
     *
     * @param  string  $text  대상 문자열 (HTML 이 아니라 **텍스트 노드 내용**)
     * @return list<array{kind: string, raw: string, offset: int, length: int, target: string, label: ?string, name: string, argument: ?string}>
     */
    public static function tokenize(string $text): array
    {
        if (! str_contains($text, '[[')) {
            return [];
        }

        $tokens = [];
        $cursor = 0;
        $length = strlen($text);

        while ($cursor < $length) {
            $open = strpos($text, '[[', $cursor);

            if ($open === false) {
                break;
            }

            $close = strpos($text, ']]', $open + 2);

            if ($close === false) {
                break;
            }

            $body = substr($text, $open + 2, $close - $open - 2);

            // 대괄호가 더 들어 있으면 표기로 보지 않는다 — 중첩·오타를 조용히 삼키지 않는다.
            if (str_contains($body, '[') || str_contains($body, ']')) {
                $cursor = $open + 2;

                continue;
            }

            $token = self::classify($body, $open, $close - $open + 2, substr($text, $open, $close - $open + 2));

            if ($token !== null) {
                $tokens[] = $token;
            }

            $cursor = $close + 2;
        }

        return $tokens;
    }

    /**
     * `[[` 와 `]]` 사이 내용을 종류별로 가릅니다.
     *
     * @return array{kind: string, raw: string, offset: int, length: int, target: string, label: ?string, name: string, argument: ?string}|null
     */
    private static function classify(string $body, int $offset, int $length, string $raw): ?array
    {
        [$head, $tail] = self::splitPipe($body);

        $head = trim($head);

        if ($head === '') {
            return null;
        }

        if (str_starts_with($head, '#')) {
            $name = trim(substr($head, 1));

            if (! in_array($name, self::PLACEHOLDERS, true)) {
                return null;
            }

            return self::token(self::KIND_PLACEHOLDER, $raw, $offset, $length, '', null, $name, $tail === null ? null : trim($tail));
        }

        foreach (self::RESERVED_PREFIXES as $prefix) {
            if (str_starts_with($head, $prefix)) {
                return self::token(self::KIND_RESERVED, $raw, $offset, $length, $head, $tail === null ? null : trim($tail), '', null);
            }
        }

        $label = $tail === null ? null : trim($tail);

        return self::token(self::KIND_LINK, $raw, $offset, $length, $head, $label === '' ? null : $label, '', null);
    }

    /**
     * 첫 `|` 로 한 번만 가릅니다. `|` 가 없으면 뒤쪽은 null 이다.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function splitPipe(string $body): array
    {
        $pipe = strpos($body, '|');

        if ($pipe === false) {
            return [$body, null];
        }

        return [substr($body, 0, $pipe), substr($body, $pipe + 1)];
    }

    /**
     * @return array{kind: string, raw: string, offset: int, length: int, target: string, label: ?string, name: string, argument: ?string}
     */
    private static function token(
        string $kind,
        string $raw,
        int $offset,
        int $length,
        string $target,
        ?string $label,
        string $name,
        ?string $argument
    ): array {
        return [
            'kind' => $kind,
            'raw' => $raw,
            'offset' => $offset,
            'length' => $length,
            'target' => $target,
            'label' => $label,
            'name' => $name,
            'argument' => $argument,
        ];
    }
}
