<?php

namespace Plugins\G7\Light\Wiki\Support\WikiHtml;

use Plugins\G7\Light\Wiki\Support\WikiHtml;
use Plugins\G7\Light\Wiki\Support\WikiIndexBuilder;
use Plugins\G7\Light\Wiki\Support\WikiUrl;

/**
 * 자리표시가 그리는 **목록** HTML — 순수 클래스 (DB·HTTP·설정 접근 없음).
 *
 * {@see WikiHtml} 이 300줄을 넘겨 나눈 것 중 하나다. 낱개 요소와 이스케이프는 `WikiHtml` 에,
 * 문서 뒤 자동 영역은 {@see Footer} 에 있다.
 */
final class Lists
{
    /**
     * 최근 수정 목록.
     *
     * @param  list<array{post_id: int, title: string}>  $items
     */
    public static function recent(string $slug, array $items, string $emptyLabel): string
    {
        return self::docs($slug, $items, $emptyLabel, 'g7lw-recent');
    }

    /**
     * 최근 작성 목록 — 만드는 방식은 최근 수정과 같고 감싸개 class 만 다르다.
     *
     * @param  list<array{post_id: int, title: string}>  $items
     */
    public static function created(string $slug, array $items, string $emptyLabel): string
    {
        return self::docs($slug, $items, $emptyLabel, 'g7lw-created');
    }

    /**
     * 랜덤 문서 여러 건 — `[[#랜덤|N]]` 의 N 이 2 이상일 때.
     *
     * 낱낱의 링크는 단일 랜덤 링크와 같은 class(`g7lw-random g7lw-link`)를 쓴다.
     * 감싸개만 `g7lw-random-list` 로 달라진다.
     *
     * @param  list<array{post_id: int, title: string}>  $items
     */
    public static function random(string $slug, array $items, string $emptyLabel): string
    {
        if ($items === []) {
            return WikiHtml::emptyNotice('g7lw-random-list', $emptyLabel);
        }

        $html = '<ul class="g7lw-random-list">';

        foreach ($items as $item) {
            $html .= '<li>'.WikiHtml::randomLink($slug, (int) $item['post_id'], (string) $item['title']).'</li>';
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
            .self::tourColumn($createdLabel, self::created($slug, $created, $emptyLabel))
            .self::tourColumn($randomLabel, self::random($slug, $random, $emptyLabel))
            .'</div>';
    }

    /**
     * 가나다 색인.
     *
     * @param  list<array{label: string, items: list<array{id: int, title: string, title_norm: string}>}>  $groups
     */
    public static function index(string $slug, array $groups, string $otherLabel, string $emptyLabel): string
    {
        if ($groups === []) {
            return WikiHtml::emptyNotice('g7lw-index', $emptyLabel);
        }

        $html = '<div class="g7lw-index">';

        foreach ($groups as $group) {
            $label = $group['label'] === WikiIndexBuilder::OTHER ? $otherLabel : $group['label'];

            $html .= '<div class="g7lw-index-group"><h3 class="g7lw-index-label" style="'.WikiHtml::LABEL_STYLE.'">'
                .WikiHtml::e($label).'</h3><ul>';

            foreach ($group['items'] as $item) {
                $html .= '<li>'.WikiHtml::link(WikiUrl::post($slug, (int) $item['id']), (string) $item['title']).'</li>';
            }

            $html .= '</ul></div>';
        }

        return $html.'</div>';
    }

    /**
     * 연표 목록 — `키 — 설명 (출처 문서 링크)`.
     *
     * 설명과 키는 글자이고 출처만 링크다. 사건은 "어느 문서에 적혀 있었는가" 가 중요해서
     * 출처를 함께 보인다.
     *
     * @param  list<array{post_id: int, title: string, target: string, label: string}>  $items
     */
    public static function timeline(
        string $slug,
        array $items,
        int $more,
        string $emptyLabel,
        string $moreLabel
    ): string {
        if ($items === []) {
            return WikiHtml::emptyNotice('g7lw-timeline', $emptyLabel);
        }

        $html = '<ul class="g7lw-timeline">';

        foreach ($items as $item) {
            $html .= '<li><span class="g7lw-event-key">'.WikiHtml::e((string) $item['target']).'</span>'
                .' — <span class="g7lw-event-label">'.WikiHtml::e((string) $item['label']).'</span>'
                .' ('.WikiHtml::link(WikiUrl::post($slug, (int) $item['post_id']), (string) $item['title']).')</li>';
        }

        $html .= '</ul>';

        if ($more > 0) {
            $html .= '<p class="g7lw-timeline-more">'.WikiHtml::e($moreLabel).'</p>';
        }

        return $html;
    }

    /**
     * 둘러보기의 한 단.
     */
    private static function tourColumn(string $label, string $body): string
    {
        return '<div class="g7lw-tour-col" style="flex:1 1 16rem;min-width:0">'
            .'<h3 class="g7lw-tour-label" style="'.WikiHtml::LABEL_STYLE.'">'.WikiHtml::e($label).'</h3>'
            .$body
            .'</div>';
    }

    /**
     * 문서 링크 목록의 공통 틀.
     *
     * @param  list<array{post_id: int, title: string}>  $items
     */
    private static function docs(string $slug, array $items, string $emptyLabel, string $class): string
    {
        if ($items === []) {
            return WikiHtml::emptyNotice($class, $emptyLabel);
        }

        $html = '<ul class="'.$class.'">';

        foreach ($items as $item) {
            $html .= '<li>'.WikiHtml::link(WikiUrl::post($slug, (int) $item['post_id']), (string) $item['title']).'</li>';
        }

        return $html.'</ul>';
    }
}
