<?php

namespace Plugins\G7\Light\Wiki\Support;

/**
 * 문서 목록을 가나다 색인 묶음으로 나누는 순수 클래스 (DB·HTTP·설정 접근 없음).
 *
 * ## 묶는 규칙
 *
 * - 정규화 제목(`title_norm`)의 **첫 글자**로 묶는다.
 * - 한글 음절은 초성으로 묶고, **쌍자음은 기본 자음에 합친다**(ㄲ→ㄱ, ㄸ→ㄷ, ㅃ→ㅂ, ㅆ→ㅅ, ㅉ→ㅈ).
 *   낱자 자모(ㄱ~ㅎ, U+3131~U+314E)로 시작하는 제목도 같은 자리에 들어간다.
 * - 라틴 문자는 A~Z (정규화 제목은 이미 소문자라 대문자 라벨로 보여 준다).
 * - 숫자는 0~9.
 * - 그 밖(한자·기호·이모지 등)은 마지막 "기타" 묶음.
 *
 * 묶음 순서는 초성 → A~Z → 0~9 → 기타. 묶음 안에서는 `title_norm` 의 **코드포인트 순**
 * (UTF-8 바이트 비교 = 코드포인트 비교)이다. 비어 있는 묶음은 결과에 넣지 않는다.
 */
final class WikiIndexBuilder
{
    /** 기타 묶음의 라벨 자리 — 호출부가 언어 파일 문구로 바꾼다 */
    public const OTHER = '#';

    /** 한글 초성 19자 (유니코드 음절 분해 순서) */
    private const CHOSEONG = [
        'ㄱ', 'ㄲ', 'ㄴ', 'ㄷ', 'ㄸ', 'ㄹ', 'ㅁ', 'ㅂ', 'ㅃ', 'ㅅ',
        'ㅆ', 'ㅇ', 'ㅈ', 'ㅉ', 'ㅊ', 'ㅋ', 'ㅌ', 'ㅍ', 'ㅎ',
    ];

    /** 쌍자음 → 기본 자음 */
    private const DOUBLE_TO_BASE = [
        'ㄲ' => 'ㄱ', 'ㄸ' => 'ㄷ', 'ㅃ' => 'ㅂ', 'ㅆ' => 'ㅅ', 'ㅉ' => 'ㅈ',
    ];

    /** 묶음 순서 — 초성 14 + A~Z + 0~9 + 기타 */
    private const BASE_CHOSEONG = [
        'ㄱ', 'ㄴ', 'ㄷ', 'ㄹ', 'ㅁ', 'ㅂ', 'ㅅ', 'ㅇ', 'ㅈ', 'ㅊ', 'ㅋ', 'ㅌ', 'ㅍ', 'ㅎ',
    ];

    /**
     * 문서 목록을 색인 묶음으로 나눕니다.
     *
     * @param  list<array{id: int, title: string, title_norm: string}>  $docs
     * @return list<array{label: string, items: list<array{id: int, title: string, title_norm: string}>}>
     */
    public static function build(array $docs): array
    {
        $buckets = [];

        foreach ($docs as $doc) {
            $buckets[self::bucketOf((string) ($doc['title_norm'] ?? ''))][] = $doc;
        }

        $result = [];

        foreach (self::labelOrder() as $label) {
            if (! isset($buckets[$label])) {
                continue;
            }

            $items = $buckets[$label];
            usort($items, static fn (array $a, array $b): int => strcmp(
                (string) ($a['title_norm'] ?? ''),
                (string) ($b['title_norm'] ?? '')
            ));

            $result[] = ['label' => $label, 'items' => array_values($items)];
        }

        return $result;
    }

    /**
     * 묶음 라벨의 정렬 순서.
     *
     * @return list<string>
     */
    public static function labelOrder(): array
    {
        return array_merge(
            self::BASE_CHOSEONG,
            range('A', 'Z'),
            array_map('strval', range(0, 9)),
            [self::OTHER],
        );
    }

    /**
     * 정규화 제목이 들어갈 묶음 라벨.
     */
    public static function bucketOf(string $normalized): string
    {
        if ($normalized === '') {
            return self::OTHER;
        }

        $first = mb_substr($normalized, 0, 1, 'UTF-8');
        $code = mb_ord($first, 'UTF-8');

        if ($code === false) {
            return self::OTHER;
        }

        // 한글 음절 → 초성
        if ($code >= 0xAC00 && $code <= 0xD7A3) {
            $choseong = self::CHOSEONG[intdiv($code - 0xAC00, 588)];

            return self::DOUBLE_TO_BASE[$choseong] ?? $choseong;
        }

        // 낱자 자모 ㄱ~ㅎ (호환 자모 영역의 자음 구간)
        if ($code >= 0x3131 && $code <= 0x314E) {
            $base = self::DOUBLE_TO_BASE[$first] ?? $first;

            return in_array($base, self::BASE_CHOSEONG, true) ? $base : self::OTHER;
        }

        if ($code >= 0x0061 && $code <= 0x007A) {
            return strtoupper($first);
        }

        if ($code >= 0x0041 && $code <= 0x005A) {
            return $first;
        }

        if ($code >= 0x0030 && $code <= 0x0039) {
            return $first;
        }

        return self::OTHER;
    }
}
