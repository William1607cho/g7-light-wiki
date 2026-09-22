<?php

namespace Tests\Unit;

use Plugins\G7\Light\Wiki\Support\EventKey;
use PHPUnit\Framework\TestCase;

/**
 * 연표 키 정렬 규칙 — 명령서 T절이 요구한 "단위 테스트로 증명" 에 해당한다.
 *
 * 핵심 주장은 하나다: **`sort_key` 를 문자열로 정렬하면 칸별 정수 비교와 같은 순서가 나온다.**
 */
class EventKeyTest extends TestCase
{
    public function test_형식에_맞는_키만_받는다(): void
    {
        foreach (['0', '1023', '1023.4', '-50.12', '-1', '999999999999', '1.2.3.4'] as $key) {
            $this->assertTrue(EventKey::isValid($key), "받아야 할 키: {$key}");
        }

        foreach ([
            '',            // 빔
            'abc',         // 숫자가 아님
            '1023.',       // 칸이 비었다
            '.5',          // 앞 칸이 비었다
            '1.-2',        // 부호는 맨 앞에만
            '1..2',        // 빈 칸
            '1e3',         // 지수 표기
            '1 023',       // 공백
            '1,023',       // 자릿점
            '1234567890123',   // 13자리 — 자릿수 상한 초과
            '1.2.3.4.5',       // 5칸 — 칸 수 상한 초과
        ] as $key) {
            $this->assertFalse(EventKey::isValid($key), "막아야 할 키: {$key}");
        }
    }

    /**
     * 칸별 정수 비교 순서대로 늘어놓은 목록이, 정렬 키의 **문자열 정렬**로도 같은 순서가 되는가.
     *
     * 음수, 칸 수가 다른 키, 자릿수가 다른 키를 모두 섞는다.
     */
    public function test_정렬_키의_문자열_정렬이_칸별_정수_비교와_같다(): void
    {
        // 사람이 보기에 옳은 순서 (작은 것부터)
        $ordered = [
            '-1000',
            '-50',
            '-50.12',
            '-3',
            '-1',
            '0',
            '0.1',
            '1',
            '9',
            '10',
            '1023',
            '1023.1',
            '1023.4',
            '1023.4.1',
            '1024',
            '999999999999',
        ];

        $keys = array_map(
            static fn (string $key): string => (string) EventKey::sortKey($key),
            $ordered,
        );

        $sorted = $keys;
        sort($sorted, SORT_STRING);

        $this->assertSame($keys, $sorted, '정렬 키를 문자열로 정렬한 결과가 의도한 순서와 달랐다');
    }

    public function test_칸_수가_적은_쪽이_앞이다(): void
    {
        $short = (string) EventKey::sortKey('1023');
        $long = (string) EventKey::sortKey('1023.1');

        $this->assertLessThan(0, strcmp($short, $long));
        // 짧은 쪽이 긴 쪽의 접두어라 자연히 앞에 선다.
        $this->assertStringStartsWith($short, $long);
    }

    public function test_형식이_틀리면_정렬_키가_없다(): void
    {
        $this->assertNull(EventKey::sortKey('abc'));
        $this->assertNull(EventKey::sortKey('1.2.3.4.5'));
    }

    public function test_앞뒤_공백은_허용하고_앞자리_0은_값으로_본다(): void
    {
        $this->assertSame(EventKey::sortKey('1023'), EventKey::sortKey('  1023  '));
        $this->assertSame(EventKey::sortKey('1023'), EventKey::sortKey('01023'));
    }
}
