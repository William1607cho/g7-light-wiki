<?php

namespace Plugins\G7\Light\Wiki\Support;

/**
 * 문서 본문 뒤에 자동으로 붙는 영역을 조립하는 순수 클래스 (DB·HTTP·설정 접근 없음).
 *
 * 조회는 호출부({@see \Plugins\G7\Light\Wiki\Http\Middleware\RenderWikiLinksExtension})가
 * 하고, 여기서는 **이미 받아 온 것을 HTML 로 엮기만** 한다. 그래야 조회 횟수가 한곳에서
 * 보이고, 이 클래스는 가짜 목록만으로 시험할 수 있다.
 *
 * ## 붙는 순서 (위에서부터)
 *
 * 1. **이 분류에 속한 문서** — 이 문서가 분류 문서(`분류:…`)일 때만.
 * 2. **다른 이름** — 이 문서의 별칭.
 * 3. **이 문서를 가리키는 문서** — 역링크. 0건이면 머리글째 빠진다.
 * 4. **분류** — 이 문서가 속한 분류 줄.
 *
 * 분류 줄이 맨 아래인 것은 종이 백과사전의 관례를 따른 것이고, 명령서가 정한 순서
 * ("역링크는 분류 줄 위", "별칭은 역링크 위")와도 같다.
 */
final class DocFooterBuilder
{
    /**
     * 자동 영역 HTML 을 만듭니다. 붙일 것이 하나도 없으면 빈 문자열이다.
     *
     * @param  array{
     *     category_members?: array{items: list<array{post_id: int, title: string}>, more: int}|null,
     *     aliases?: list<string>,
     *     backlinks?: array{items: list<array{post_id: int, title: string}>, more: int}|null,
     *     categories?: list<string>,
     * }  $parts
     * @param  WikiLabels  $labels  화면 문구 (전에는 문구 배열이었다)
     */
    public static function build(string $slug, array $parts, WikiLabels $labels): string
    {
        $blocks = [];

        $members = $parts['category_members'] ?? null;

        if (is_array($members)) {
            $blocks[] = WikiHtml::docSection(
                $slug,
                $labels->categoryMembers(),
                $members['items'],
                $members['more'],
                'g7lw-category-members',
                $labels->more($members['more']),
            );
        }

        $aliases = $parts['aliases'] ?? [];

        if ($aliases !== []) {
            $blocks[] = WikiHtml::aliasLine($labels->aliases(), $aliases);
        }

        $backlinks = $parts['backlinks'] ?? null;

        if (is_array($backlinks)) {
            $blocks[] = WikiHtml::docSection(
                $slug,
                $labels->backlinks(),
                $backlinks['items'],
                $backlinks['more'],
                'g7lw-backlinks',
                $labels->more($backlinks['more']),
            );
        }

        $categories = $parts['categories'] ?? [];

        if ($categories !== []) {
            $blocks[] = WikiHtml::categoryLine($labels->categories(), $categories);
        }

        return WikiHtml::footer($blocks);
    }
}
