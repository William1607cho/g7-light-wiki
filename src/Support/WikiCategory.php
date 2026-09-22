<?php

namespace Plugins\G7\Light\Wiki\Support;

/**
 * 분류 문서의 이름 규칙 — 순수 클래스 (DB·HTTP·설정 접근 없음).
 *
 * 분류는 **따로 만드는 무엇이 아니라 문서 하나**다. `[[분류:인물]]` 이 가리키는 것은 제목이
 * `분류:인물` 인 위키 문서이고, 그 문서가 없으면 다른 링크와 똑같이 빨간 링크가 된다.
 * 분류를 별도 표로 두면 "문서이면서 분류인 것"(하위 분류)을 다루기 어렵고, 권한·비밀글
 * 규칙도 따로 만들어야 한다.
 *
 * 접두어는 본문 표기(`[[분류:…]]`)와 문서 제목(`분류:…`)에서 **같은 글자**다. 그래야 분류
 * 문서를 여는 링크와 그 문서의 제목이 맞물린다.
 */
final class WikiCategory
{
    /** 분류 문서 제목의 접두어 */
    public const PREFIX = '분류:';

    /**
     * 분류 이름으로 분류 문서의 제목을 만듭니다 (`인물` → `분류:인물`).
     */
    public static function title(string $name): string
    {
        return self::PREFIX.$name;
    }

    /**
     * 이 제목이 분류 문서의 것인가.
     *
     * 정규화 제목으로 판정한다 — 전각 콜론(`：`)으로 적은 제목도 `TitleNormalizer` 가
     * 반각으로 접어 주므로 같은 분류 문서가 된다.
     */
    public static function isCategoryTitle(string $normalizedTitle): bool
    {
        return str_starts_with($normalizedTitle, self::normalizedPrefix())
            && self::nameOf($normalizedTitle) !== '';
    }

    /**
     * 분류 문서의 정규화 제목에서 분류 이름(정규화)을 꺼냅니다.
     *
     * 분류 문서가 아니면 빈 문자열이다.
     */
    public static function nameOf(string $normalizedTitle): string
    {
        $prefix = self::normalizedPrefix();

        if (! str_starts_with($normalizedTitle, $prefix)) {
            return '';
        }

        return trim(substr($normalizedTitle, strlen($prefix)));
    }

    /**
     * 접두어의 정규화형.
     *
     * 접두어 자체도 `TitleNormalizer` 를 거친다 — 제목은 정규화된 값으로 들어오므로,
     * 비교 대상인 접두어도 같은 규칙을 통과한 글자여야 한다.
     */
    private static function normalizedPrefix(): string
    {
        return TitleNormalizer::normalize(self::PREFIX);
    }
}
