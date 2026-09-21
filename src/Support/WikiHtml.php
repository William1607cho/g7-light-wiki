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
     * `target="_self"` 를 명시해 SPA 가 아니라 **브라우저 전체 이동**으로 가게 한다.
     * 이 템플릿은 본문 앵커 클릭을 가로채지 않으므로 서버 302 를 그대로 따라간다.
     */
    public static function randomLink(int $boardId, string $label): string
    {
        return '<a class="g7lw-random" href="'.self::e(WikiUrl::random($boardId)).'"'
            .' target="_self" rel="nofollow">'.self::e($label).'</a>';
    }

    /**
     * 최근 수정 목록.
     *
     * @param  list<array{post_id: int, title: string}>  $items
     */
    public static function recentList(string $slug, array $items, string $emptyLabel): string
    {
        if ($items === []) {
            return '<p class="g7lw-recent g7lw-empty">'.self::e($emptyLabel).'</p>';
        }

        $html = '<ul class="g7lw-recent">';

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

            $html .= '<div class="g7lw-index-group"><h3 class="g7lw-index-label">'.self::e($label).'</h3><ul>';

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
