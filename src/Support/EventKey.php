<?php

namespace Plugins\G7\Light\Wiki\Support;

/**
 * 연표 키(`[[연표:키|설명]]` 의 키)를 다루는 순수 클래스 (DB·HTTP·설정 접근 없음).
 *
 * ## 키 형식
 *
 * `-?정수(.정수)*` — `1023`, `1023.4`, `-50.12`. 칸(`.` 으로 끊은 조각)마다 정수로 비교하고,
 * 앞 칸이 모두 같으면 **칸 수가 적은 쪽이 앞**이다(`1023` < `1023.1`). 부호는 맨 앞에만 붙는다.
 *
 * ## 왜 정렬 키를 따로 만드는가
 *
 * 사건은 게시판 전체를 키 순으로 늘어놓는다. 정렬을 PHP 로 하면 목록 상한(200건)을 걸기
 * 전에 게시판의 모든 사건을 메모리로 끌어와야 한다. DB 가 `ORDER BY` 로 끝내려면 **문자열
 * 정렬이 곧 칸별 정수 정렬**이 되는 컬럼이 있어야 한다. 그것이 `sort_key` 다.
 *
 * ## 정규화 방식
 *
 * 칸마다 `값 + OFFSET` 을 {@see WIDTH} 자리 0 채움으로 적고 `.` 로 잇는다.
 *
 * - `OFFSET`({@see OFFSET}) 을 더해 음수를 없앤다. 음수를 그대로 적으면 `-` 가 숫자보다
 *   앞서 정렬돼 `-50` 이 `-3` 보다 앞이 되는 식으로 뒤집힌다.
 * - 자리를 고정해야 자릿수가 다른 값(`9` 와 `10`)이 문자열로도 옳게 선다.
 * - 칸 수가 적은 쪽은 긴 쪽의 **접두어**가 되므로 문자열 비교에서 자연히 앞에 온다.
 *
 * ```
 * 1023      → 1000000001023
 * 1023.1    → 1000000001023.1000000000001
 * -50.12    → 0999999999950.1000000000012
 * ```
 *
 * `utf8mb4_bin` 컬럼이라 바이트 비교이고, 쓰는 글자가 숫자와 `.` 뿐이라 로캘의 영향이 없다.
 */
final class EventKey
{
    /** 한 칸이 가질 수 있는 최대 자릿수 */
    public const MAX_DIGITS = 12;

    /** 키가 가질 수 있는 최대 칸 수 — `sort_key` 컬럼(64자)에 맞춘 상한 */
    public const MAX_PARTS = 4;

    /** 정렬 키 한 칸의 자리 수 (OFFSET 을 더한 최댓값이 13자리다) */
    private const WIDTH = 13;

    /** 음수를 없애려고 각 칸에 더하는 값 */
    private const OFFSET = 1000000000000;

    /**
     * 키가 형식에 맞는가.
     *
     * 맞지 않으면 사건으로 등록하지 않고 본문의 표기도 원문 그대로 둔다.
     */
    public static function isValid(string $key): bool
    {
        return self::sortKey($key) !== null;
    }

    /**
     * 키를 정렬용 문자열로 바꿉니다.
     *
     * @return string|null 형식이 틀리면 `null`
     */
    public static function sortKey(string $key): ?string
    {
        $key = trim($key);

        if ($key === '' || ! preg_match('/^-?\d{1,'.self::MAX_DIGITS.'}(\.\d{1,'.self::MAX_DIGITS.'})*$/', $key)) {
            return null;
        }

        $negative = str_starts_with($key, '-');
        $parts = explode('.', $negative ? substr($key, 1) : $key);

        if (count($parts) > self::MAX_PARTS) {
            return null;
        }

        $encoded = [];

        foreach ($parts as $index => $part) {
            // 부호는 맨 앞 칸에만 붙는다 — `-50.12` 는 "-50" 과 "12" 다.
            $value = (int) $part;

            if ($negative && $index === 0) {
                $value = -$value;
            }

            $encoded[] = str_pad((string) ($value + self::OFFSET), self::WIDTH, '0', STR_PAD_LEFT);
        }

        return implode('.', $encoded);
    }
}
