<?php

namespace Plugins\G7\Light\Wiki\Support\Placeholders;

/**
 * 자리표시 인자 `|N` 을 개수로 바꾸는 규칙 — 여러 렌더러가 같은 규칙을 쓴다.
 */
final class Limit
{
    /**
     * `[[#최근수정|N]]` 의 N — 숫자가 아니거나 1 미만이면 기본값, 최대값에서 자른다.
     */
    public static function orDefault(mixed $argument, int $default, int $max): int
    {
        return self::optional($argument, $max) ?? $default;
    }

    /**
     * 인자가 "쓸 수 있는 숫자" 일 때만 개수를 돌려준다. 그 밖에는 null.
     *
     * `null` 과 기본값을 구분해야 하는 자리가 있다 — `[[#랜덤]]` 은 인자가 없으면
     * 목록이 아니라 **링크 하나**여야 한다(1단계와 같은 결과).
     */
    public static function optional(mixed $argument, int $max): ?int
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
