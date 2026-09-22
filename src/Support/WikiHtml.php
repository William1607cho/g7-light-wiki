<?php

namespace Plugins\G7\Light\Wiki\Support;

/**
 * 본문에 끼워 넣을 HTML 조각을 만드는 순수 클래스 (DB·HTTP·설정 접근 없음).
 *
 * 여기서 만드는 태그·속성은 **방문자 화면(DOMPurify)과 봇 SSR 정제기 양쪽을 통과하는
 * 것들만** 쓴다: `a`·`span`·`ul`·`ol`·`li`·`div`·`h3` 와 `href`·`class`·`target`·`rel`.
 * `form`·`input`·`button` 은 두 정제기가 모두 지우므로 쓰지 않는다.
 *
 * 색은 템플릿 빌드 CSS 에 실제로 들어 있는 유틸리티 클래스로만 준다
 * (`text-red-600`, `dark:text-red-400`). 다크 모드는 `.dark` 조상 클래스 방식이다.
 */
final class WikiHtml
{
    /** 있는 문서 링크 */
    public const CLASS_LINK = 'g7lw-link';

    /** 없는 문서 표시 (빨간 링크·빨간 글자 공통) */
    public const CLASS_NEW = 'g7lw-link-new text-red-600 dark:text-red-400';

    /**
     * 자리표시 머리글(색인 묶음·둘러보기 단)의 강조.
     *
     * 템플릿 빌드 CSS 에 `g7lw-…` 전용 스타일이 없어 class 만으로는 본문 글자와 크기가
     * 같아 보인다(2026-09-21 실브라우저 관찰). 그래서 배치와 마찬가지로 **인라인 style
     * 로만** 준다. `style` 속성은 봇 SSR 정제기와 방문자 화면 DOMPurify 양쪽이 남긴다.
     *
     * 색은 **지정하지 않는다** — 상속을 받아야 다크 모드에서 깨지지 않는다.
     */
    private const LABEL_STYLE = 'font-weight:700;font-size:1.125rem;margin:1rem 0 .25rem';

    /**
     * 있는 문서로 가는 링크.
     */
    public static function link(string $url, string $label): string
    {
        return '<a class="'.self::CLASS_LINK.'" href="'.self::e($url).'">'.self::e($label).'</a>';
    }

    /**
     * 없는 문서 — 글쓰기 권한이 있는 요청자에게는 작성 화면으로 가는 빨간 링크.
     */
    public static function newLink(string $url, string $label): string
    {
        return '<a class="'.self::CLASS_LINK.' '.self::CLASS_NEW.'" href="'.self::e($url).'">'
            .self::e($label).'</a>';
    }

    /**
     * 없는 문서 — 글쓰기 권한이 없는 요청자에게는 링크 없이 빨간 글자만.
     */
    public static function newText(string $label): string
    {
        return '<span class="'.self::CLASS_NEW.'">'.self::e($label).'</span>';
    }

    /**
     * 랜덤 문서로 가는 링크.
     *
     * 대상은 **치환 시점에** 고른다. 플러그인 주소로 보내 서버가 302 로 고르게 하면,
     * 브라우저 전체 이동에는 Bearer 토큰이 실리지 않아 로그인한 사람도 비회원으로 보인다
     * (비공개 위키에서 401 로 떨어진다). 치환은 토큰이 실린 글 상세 응답 안에서 일어나므로
     * 요청자 기준으로 고를 수 있다.
     *
     * 그래서 결과물은 그냥 문서 링크다 — 새로고침할 때마다 대상이 다시 뽑힌다.
     */
    public static function randomLink(string $slug, int $postId, string $label): string
    {
        return '<a class="g7lw-random '.self::CLASS_LINK.'" href="'
            .self::e(WikiUrl::post($slug, $postId)).'">'.self::e($label).'</a>';
    }

    /**
     * 고를 문서가 없을 때 — 링크 대신 안내 글자.
     */
    public static function randomEmpty(string $label): string
    {
        return '<span class="g7lw-random g7lw-empty">'.self::e($label).'</span>';
    }

    /**
     * 최근 수정 목록.
     *
     * @param  list<array{post_id: int, title: string}>  $items
     */
    public static function recentList(string $slug, array $items, string $emptyLabel): string
    {
        return self::docList($slug, $items, $emptyLabel, 'g7lw-recent');
    }

    /**
     * 최근 작성 목록 — 만드는 방식은 최근 수정과 같고 감싸개 class 만 다르다.
     *
     * @param  list<array{post_id: int, title: string}>  $items
     */
    public static function createdList(string $slug, array $items, string $emptyLabel): string
    {
        return self::docList($slug, $items, $emptyLabel, 'g7lw-created');
    }

    /**
     * 랜덤 문서 여러 건 — `[[#랜덤|N]]` 의 N 이 2 이상일 때.
     *
     * 낱낱의 링크는 단일 랜덤 링크와 같은 class(`g7lw-random g7lw-link`)를 쓴다.
     * 감싸개만 `g7lw-random-list` 로 달라진다.
     *
     * @param  list<array{post_id: int, title: string}>  $items
     */
    public static function randomList(string $slug, array $items, string $emptyLabel): string
    {
        if ($items === []) {
            return '<p class="g7lw-random-list g7lw-empty">'.self::e($emptyLabel).'</p>';
        }

        $html = '<ul class="g7lw-random-list">';

        foreach ($items as $item) {
            $html .= '<li>'.self::randomLink($slug, (int) $item['post_id'], (string) $item['title']).'</li>';
        }

        return $html.'</ul>';
    }

    /**
     * 둘러보기 — 왼쪽 "최근 작성", 오른쪽 "랜덤" 2단 블록.
     *
     * 배치는 **인라인 style 로만** 준다. 템플릿 빌드 CSS 에 이 플러그인 전용 class 가 없어
     * `g7lw-…` 만으로는 한 줄로 서지 않기 때문이다. `style` 속성은 봇 SSR 정제기
     * (`App\Seo\HtmlSanitizer`)와 방문자 화면 DOMPurify 양쪽이 남긴다(2026-09-21 실측).
     *
     * 좁은 화면에서는 `flex-wrap:wrap` + 단의 `flex-basis:16rem` 이 자동으로 1단으로 접는다.
     *
     * @param  list<array{post_id: int, title: string}>  $created  왼쪽 단 항목
     * @param  list<array{post_id: int, title: string}>  $random  오른쪽 단 항목
     */
    public static function tour(
        string $slug,
        array $created,
        array $random,
        string $createdLabel,
        string $randomLabel,
        string $emptyLabel
    ): string {
        return '<div class="g7lw-tour" style="display:flex;flex-wrap:wrap;gap:1.5rem">'
            .self::tourColumn($createdLabel, self::createdList($slug, $created, $emptyLabel))
            .self::tourColumn($randomLabel, self::randomList($slug, $random, $emptyLabel))
            .'</div>';
    }

    /**
     * 둘러보기의 한 단.
     */
    private static function tourColumn(string $label, string $body): string
    {
        return '<div class="g7lw-tour-col" style="flex:1 1 16rem;min-width:0">'
            .'<h3 class="g7lw-tour-label" style="'.self::LABEL_STYLE.'">'.self::e($label).'</h3>'
            .$body
            .'</div>';
    }

    /**
     * 문서 링크 목록의 공통 틀.
     *
     * @param  list<array{post_id: int, title: string}>  $items
     */
    private static function docList(string $slug, array $items, string $emptyLabel, string $class): string
    {
        if ($items === []) {
            return '<p class="'.$class.' g7lw-empty">'.self::e($emptyLabel).'</p>';
        }

        $html = '<ul class="'.$class.'">';

        foreach ($items as $item) {
            $html .= '<li>'.self::link(WikiUrl::post($slug, (int) $item['post_id']), (string) $item['title']).'</li>';
        }

        return $html.'</ul>';
    }

    /**
     * 가나다 색인.
     *
     * @param  list<array{label: string, items: list<array{id: int, title: string, title_norm: string}>}>  $groups
     */
    public static function indexList(string $slug, array $groups, string $otherLabel, string $emptyLabel): string
    {
        if ($groups === []) {
            return '<p class="g7lw-index g7lw-empty">'.self::e($emptyLabel).'</p>';
        }

        $html = '<div class="g7lw-index">';

        foreach ($groups as $group) {
            $label = $group['label'] === WikiIndexBuilder::OTHER ? $otherLabel : $group['label'];

            $html .= '<div class="g7lw-index-group"><h3 class="g7lw-index-label" style="'.self::LABEL_STYLE.'">'
                .self::e($label).'</h3><ul>';

            foreach ($group['items'] as $item) {
                $html .= '<li>'.self::link(WikiUrl::post($slug, (int) $item['id']), (string) $item['title']).'</li>';
            }

            $html .= '</ul></div>';
        }

        return $html.'</div>';
    }

    /**
     * HTML 이스케이프 — 속성·텍스트 공통.
     */
    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
