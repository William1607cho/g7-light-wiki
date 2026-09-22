<?php

namespace Plugins\G7\Light\Wiki\Support\WikiHtml;

use Plugins\G7\Light\Wiki\Support\WikiHtml;
use Plugins\G7\Light\Wiki\Support\WikiUrl;

/**
 * 문서 뒤에 **자동으로 붙는 영역**의 HTML — 순수 클래스 (DB·HTTP·설정 접근 없음).
 *
 * {@see WikiHtml} 이 300줄을 넘겨 나눈 것 중 하나다. 낱개 요소와 이스케이프는 `WikiHtml` 에,
 * 자리표시가 그리는 목록은 {@see Lists} 에 있다.
 *
 * 무엇을 어떤 순서로 넣을지는 {@see \Plugins\G7\Light\Wiki\Support\DocFooterBuilder} 가 정하고,
 * 여기서는 덩이 하나의 모양만 만든다.
 */
final class Footer
{
    /**
     * 자동으로 붙는 영역을 하나로 묶는 감싸개.
     *
     * 분류 줄·다른 이름·역링크·분류 소속 목록은 사람이 적은 본문이 아니라 **플러그인이
     * 붙인 것**이다. 한 껍데기로 묶어 두면 비활성화했을 때 무엇이 사라지는지 분명하고,
     * 스킨에서 손대고 싶을 때도 잡을 자리가 하나다.
     *
     * @param  list<string>  $blocks  빈 문자열은 알아서 빠진다
     */
    public static function wrap(array $blocks): string
    {
        $blocks = array_values(array_filter(
            $blocks,
            static fn (string $block): bool => trim($block) !== ''
        ));

        if ($blocks === []) {
            return '';
        }

        return '<div class="g7lw-footer" style="margin-top:2em">'.implode('', $blocks).'</div>';
    }

    /**
     * 분류 줄 — `분류: <a>인물</a> · <a>세력</a>`.
     *
     * 낱낱의 항목은 이미 만들어진 링크 HTML 이다(있는 문서면 파란 링크, 없으면 빨간 링크나
     * 빨간 글자). 무엇을 넣을지는 호출부가 정하고, 여기서는 줄의 모양만 만든다.
     *
     * @param  list<string>  $linkHtml  항목 HTML (이미 이스케이프됨)
     */
    public static function categoryLine(string $label, array $linkHtml): string
    {
        if ($linkHtml === []) {
            return '';
        }

        return '<p class="g7lw-categories"><span class="g7lw-categories-label">'
            .WikiHtml::e($label).': </span>'.implode(' · ', $linkHtml).'</p>';
    }

    /**
     * "다른 이름" 줄 — 별칭 나열. 글자만 넣는다(별칭은 링크가 아니다).
     *
     * @param  list<string>  $names
     */
    public static function aliasLine(string $label, array $names): string
    {
        if ($names === []) {
            return '';
        }

        $escaped = array_map(
            static fn (string $name): string => '<span class="g7lw-alias">'.WikiHtml::e($name).'</span>',
            $names,
        );

        return '<p class="g7lw-aliases"><span class="g7lw-aliases-label">'
            .WikiHtml::e($label).': </span>'.implode(' · ', $escaped).'</p>';
    }

    /**
     * 머리글 + 문서 목록으로 된 자동 영역 한 덩이 (역링크·분류 소속).
     *
     * 항목이 없으면 **머리글째 빈 문자열**이다 — "0건이면 머리글째 생략" 이 규칙이다.
     *
     * @param  list<array{post_id: int, title: string}>  $items
     * @param  int  $more  상한을 넘어 자른 건수 (0이면 표시하지 않는다)
     */
    public static function section(
        string $slug,
        string $label,
        array $items,
        int $more,
        string $class,
        string $moreLabel
    ): string {
        if ($items === []) {
            return '';
        }

        $html = '<div class="'.$class.'"><h3 class="'.$class.'-label" style="'.WikiHtml::LABEL_STYLE.'">'
            .WikiHtml::e($label).'</h3><ul>';

        foreach ($items as $item) {
            $html .= '<li>'.WikiHtml::link(WikiUrl::post($slug, (int) $item['post_id']), (string) $item['title']).'</li>';
        }

        $html .= '</ul>';

        if ($more > 0) {
            $html .= '<p class="'.$class.'-more">'.WikiHtml::e($moreLabel).'</p>';
        }

        return $html.'</div>';
    }
}
