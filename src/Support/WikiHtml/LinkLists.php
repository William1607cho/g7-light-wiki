<?php

namespace Plugins\G7\Light\Wiki\Support\WikiHtml;

use Plugins\G7\Light\Wiki\Support\WikiHtml;
use Plugins\G7\Light\Wiki\Support\WikiUrl;

/**
 * 외톨이 문서·필요한 문서 목록의 HTML.
 *
 * 머리글은 두지 않는다 — 이 자리표시를 담은 문서의 제목과 앞 문장이 머리글 몫을 한다.
 * 0건이면 빈 목록 안내(`<p class="… g7lw-empty">`)다. 사람이 "여기에 목록을 놓아라" 고 적은
 * 자리라, 아무것도 안 나오면 적은 것이 사라진 것처럼 보인다.
 *
 * 필요한 문서의 항목은 **본문의 빨간 링크와 같은 모양**이다 — 쓰기 권한이 있으면 작성 화면으로
 * 가는 링크, 없으면 빨간 글자({@see \Plugins\G7\Light\Wiki\Support\Doc\LinkResolver}).
 */
final class LinkLists
{
    public const ORPHAN_CLASS = 'g7lw-orphan-list';

    public const WANTED_CLASS = 'g7lw-wanted-list';

    /**
     * @param  list<array{post_id: int, title: string}>  $items
     */
    public static function orphans(string $slug, array $items, string $moreLabel, string $emptyLabel): string
    {
        return self::wrap(
            self::ORPHAN_CLASS,
            array_map(
                static fn (array $item): string => WikiHtml::link(WikiUrl::post($slug, (int) $item['post_id']), (string) $item['title']),
                $items,
            ),
            $moreLabel,
            $emptyLabel,
        );
    }

    /**
     * @param  list<array{target: string, count: int}>  $items
     * @param  bool  $canWrite  작성 화면 링크로 줄지(아니면 빨간 글자)
     */
    public static function wanted(int $boardId, bool $canWrite, array $items, string $moreLabel, string $emptyLabel): string
    {
        return self::wrap(
            self::WANTED_CLASS,
            array_map(
                static fn (array $item): string => ($canWrite
                    ? WikiHtml::newLink(WikiUrl::newDoc($boardId, (string) $item['target']), (string) $item['target'])
                    : WikiHtml::newText((string) $item['target']))
                    .' ('.(int) $item['count'].')',
                $items,
            ),
            $moreLabel,
            $emptyLabel,
        );
    }

    /**
     * @param  list<string>  $itemHtml  항목 하나씩의 HTML
     * @param  string  $moreLabel  "외 N건" (넘치지 않았으면 빈 문자열)
     */
    private static function wrap(string $class, array $itemHtml, string $moreLabel, string $emptyLabel): string
    {
        if ($itemHtml === []) {
            return WikiHtml::emptyNotice($class, $emptyLabel);
        }

        $html = '<div class="'.$class.'"><ul>';

        foreach ($itemHtml as $item) {
            $html .= '<li>'.$item.'</li>';
        }

        $html .= '</ul>';

        if ($moreLabel !== '') {
            $html .= '<p class="'.$class.'-more">'.WikiHtml::e($moreLabel).'</p>';
        }

        return $html.'</div>';
    }
}
