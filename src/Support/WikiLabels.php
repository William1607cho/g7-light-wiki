<?php

namespace Plugins\G7\Light\Wiki\Support;

/**
 * 화면에 나가는 문구를 담는 작은 객체.
 *
 * 전에는 미들웨어가 `labels()` 와 `docLabels()` 두 메서드로 **문구 배열 두 개**를 만들어
 * 자리표시 렌더러와 자동 영역 조립기에 따로 넘겼다. 그래서
 *  - 렌더러 생성자 인자가 그 둘 때문에 늘어났고,
 *  - `docLabels()` 는 한 요청에서 두 번 만들어졌고,
 *  - 받는 쪽마다 `$labels['more'] ?? ''` 같은 방어 코드가 붙었고,
 *  - "외 N건" 을 만드는 같은 코드가 두 곳에 있었다.
 *
 * 둘을 하나로 묶고, 꺼내는 자리에 이름을 붙였다. 키와 문구는 **그대로다**.
 *
 * ## 언어 파일은 여기서만 읽는다
 *
 * {@see fromLang()} 만 `__()` 를 부른다. 문구를 받아 쓰는 클래스들은 Laravel 을 모르는
 * 순수 클래스로 남고, 단위 시험은 {@see of()} 로 문구를 직접 넣는다.
 */
final class WikiLabels
{
    /** 문구 키의 앞머리 */
    private const NS = 'g7-light-wiki::messages.';

    /** 담는 문구의 키 — 언어 파일의 키와 같다 */
    private const KEYS = [
        'front.random',
        'front.other',
        'front.empty',
        'front.tour_recent',
        'front.tour_random',
        'front.orphan_empty',
        'front.wanted_empty',
        'doc.categories',
        'doc.category_members',
        'doc.aliases',
        'doc.backlinks',
        'doc.more',
        'doc.empty',
        'doc.timeline_empty',
    ];

    /**
     * @param  array<string, string>  $values
     */
    private function __construct(private readonly array $values) {}

    /**
     * 언어 파일에서 읽어 만든다 — Laravel 이 떠 있는 자리에서만 부른다.
     */
    public static function fromLang(): self
    {
        $values = [];

        foreach (self::KEYS as $key) {
            $values[$key] = (string) __(self::NS.$key);
        }

        return new self($values);
    }

    /**
     * 문구를 직접 넣어 만든다 — 단위 시험용.
     *
     * @param  array<string, string>  $values  키는 {@see KEYS} 와 같다
     */
    public static function of(array $values): self
    {
        return new self($values);
    }

    // ── 자리표시 문구 ────────────────────────────────────────────────────────

    /** 랜덤 링크의 글자 */
    public function random(): string
    {
        return $this->get('front.random');
    }

    /** 색인의 "기타" 묶음 이름 */
    public function other(): string
    {
        return $this->get('front.other');
    }

    /** 보여 줄 문서가 없을 때 */
    public function empty(): string
    {
        return $this->get('front.empty');
    }

    /** 둘러보기 왼쪽 단 머리글 (최근 수정) */
    public function tourRecent(): string
    {
        return $this->get('front.tour_recent');
    }

    /** 둘러보기 오른쪽 단 머리글 */
    public function tourRandom(): string
    {
        return $this->get('front.tour_random');
    }

    /** 외톨이 문서가 없을 때 */
    public function orphanEmpty(): string
    {
        return $this->get('front.orphan_empty');
    }

    /** 필요한 문서가 없을 때 */
    public function wantedEmpty(): string
    {
        return $this->get('front.wanted_empty');
    }

    // ── 자동 영역 문구 ──────────────────────────────────────────────────────

    /** 분류 줄 머리 */
    public function categories(): string
    {
        return $this->get('doc.categories');
    }

    /** "이 분류에 속한 문서" 머리글 */
    public function categoryMembers(): string
    {
        return $this->get('doc.category_members');
    }

    /** "다른 이름" 줄 머리 */
    public function aliases(): string
    {
        return $this->get('doc.aliases');
    }

    /** "이 문서를 가리키는 문서" 머리글 */
    public function backlinks(): string
    {
        return $this->get('doc.backlinks');
    }

    /** 자동 영역 목록이 빌 때 */
    public function docEmpty(): string
    {
        return $this->get('doc.empty');
    }

    /** 연표에 사건이 없을 때 */
    public function timelineEmpty(): string
    {
        return $this->get('doc.timeline_empty');
    }

    /**
     * "외 N건" — 자른 것이 없으면 **빈 문자열**이다.
     */
    public function more(int $count): string
    {
        if ($count < 1) {
            return '';
        }

        return str_replace(':count', (string) $count, $this->get('doc.more'));
    }

    private function get(string $key): string
    {
        return $this->values[$key] ?? '';
    }
}
