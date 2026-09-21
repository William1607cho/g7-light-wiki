<?php

namespace Plugins\G7\Light\Wiki\Support;

/**
 * 문서 제목 정규화 — 순수 클래스 (DB·HTTP·설정 접근 없음).
 *
 * 같은 문서를 가리키는 표기 차이를 하나로 모으되, **뜻이 달라질 수 있는 변형은 건드리지
 * 않는다**. 정규화 결과는 `light_wiki_docs.title_norm`(utf8mb4_bin) 에 그대로 들어가고
 * 유니크 인덱스의 기준이 되므로, 이 클래스의 출력이 곧 "같은 문서" 의 정의다.
 *
 * ## 순서 (바꾸면 결과가 달라진다)
 *
 *  1. 유니코드 NFC 정규화
 *  2. 전각 ASCII(U+FF01~U+FF5E) → 반각
 *  3. 앞뒤 공백 제거
 *  4. 연속 공백(NBSP·전각 공백 포함) → 공백 1칸
 *  5. 라틴 문자 소문자화
 *
 * ## NFKC 를 쓰지 않는 이유
 *
 * NFKC 는 한글 호환 자모(U+3131~)를 조합용 자모로 바꾸고 원 문자·합자까지 분해한다.
 * 한글 제목에서 눈에 보이는 글자가 바뀌어 버리므로 쓰지 않는다. 전각 ASCII 만 따로
 * 접는 것이 NFKC 대신 2단계를 두는 이유다.
 *
 * ## DB collation 과의 관계
 *
 * 코어 `board_posts.title` 의 `utf8mb4_unicode_ci` 는 악센트·전각/반각·뒤 공백은 물론
 * **대부분의 이모지를 서로 같게** 본다(가중치 미배정). 그래서 위키 유일성은 코어 제목
 * 컬럼이 아니라 이 클래스의 출력 + `utf8mb4_bin` 컬럼으로 판정한다.
 */
final class TitleNormalizer
{
    /** 정규화 제목이 들어갈 수 있는 최대 길이 (코어 `board_posts.title` 과 같다) */
    public const MAX_LENGTH = 200;

    /** 공백으로 접는 문자들 — ASCII 공백류 + NBSP + 유니코드 공백 + 전각 공백 */
    private const SPACE_CLASS = '\x{0009}\x{000A}\x{000B}\x{000C}\x{000D}\x{0020}\x{0085}'
        .'\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}';

    /**
     * 제목을 정규화합니다.
     *
     * @param  string  $title  원문 제목
     * @return string 정규화 제목 (빈 문자열이면 "위키 문서로 볼 수 없는 제목")
     */
    public static function normalize(string $title): string
    {
        $value = self::toNfc($title);
        $value = self::foldFullwidthAscii($value);
        $value = self::trimSpaces($value);
        $value = self::collapseSpaces($value);

        return self::lowercaseLatin($value);
    }

    /**
     * 위키 문서로 등록할 수 있는 제목인지 — 정규화 결과가 비어 있지 않고 길이 안에 드는가.
     *
     * @param  string  $normalized  정규화 제목
     */
    public static function isRegistrable(string $normalized): bool
    {
        return $normalized !== '' && mb_strlen($normalized, 'UTF-8') <= self::MAX_LENGTH;
    }

    /**
     * 유니코드 NFC 정규화. intl 확장이 없으면 원문을 그대로 돌려준다.
     */
    private static function toNfc(string $value): string
    {
        if (! class_exists(\Normalizer::class)) {
            return $value;
        }

        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);

        return is_string($normalized) ? $normalized : $value;
    }

    /**
     * 전각 ASCII(U+FF01 `！` ~ U+FF5E `～`)를 반각으로 접는다. 오프셋은 정확히 0xFEE0 이다.
     */
    private static function foldFullwidthAscii(string $value): string
    {
        $folded = preg_replace_callback(
            '/[\x{FF01}-\x{FF5E}]/u',
            static fn (array $m): string => chr(mb_ord($m[0], 'UTF-8') - 0xFEE0),
            $value
        );

        return is_string($folded) ? $folded : $value;
    }

    /**
     * 앞뒤 공백 제거 (ASCII trim 이 모르는 NBSP·전각 공백까지).
     */
    private static function trimSpaces(string $value): string
    {
        $trimmed = preg_replace('/^['.self::SPACE_CLASS.']+|['.self::SPACE_CLASS.']+$/u', '', $value);

        return is_string($trimmed) ? $trimmed : $value;
    }

    /**
     * 연속 공백을 공백 1칸으로.
     */
    private static function collapseSpaces(string $value): string
    {
        $collapsed = preg_replace('/['.self::SPACE_CLASS.']+/u', ' ', $value);

        return is_string($collapsed) ? $collapsed : $value;
    }

    /**
     * 라틴 문자만 소문자화한다 (U+0041~U+024F — ASCII·라틴-1 보충·라틴 확장 A/B).
     *
     * `mb_strtolower` 를 문자열 전체에 걸면 그리스·키릴 문자까지 함께 바뀐다. 그것은
     * "라틴 문자 소문자화" 가 아니므로 대상 구간만 골라 적용한다. 한글·한자·이모지는
     * 대소문자 개념이 없어 어느 쪽이든 영향이 없다.
     */
    private static function lowercaseLatin(string $value): string
    {
        $lowered = preg_replace_callback(
            '/[\x{0041}-\x{024F}]+/u',
            static fn (array $m): string => mb_strtolower($m[0], 'UTF-8'),
            $value
        );

        return is_string($lowered) ? $lowered : $value;
    }
}
