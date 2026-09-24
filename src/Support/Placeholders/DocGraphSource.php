<?php

namespace Plugins\G7\Light\Wiki\Support\Placeholders;

/**
 * 문서 사이 링크·분류로 만드는 목록의 공급자 — `[[#외톨이]]`·`[[#필요한문서]]`·`[[#분류색인]]`.
 *
 * {@see DocListSource} 와 따로 둔다. 거기에 메서드를 더하면 그 인터페이스를 구현한 단위 시험의
 * 가짜 공급자까지 함께 고쳐야 한다. 실제 구현({@see \Plugins\G7\Light\Wiki\Support\DocLists})은
 * 둘 다 구현한다.
 *
 * 외톨이·필요한 문서는 **보는 사람 기준**이다 — 비밀글도 그 사람이 읽을 수 있으면 센다.
 */
interface DocGraphSource
{
    /**
     * 외톨이 문서 — 다른 문서가 링크하지 않는 문서(대문·분류 문서·지금 문서 제외).
     *
     * @return array{items: list<array{post_id: int, title: string}>, more: int}
     */
    public function orphans(): array;

    /**
     * 필요한 문서 — 빨간 링크가 되는 이름과 그 이름을 링크한 문서 수.
     *
     * @return array{items: list<array{target: string, count: int}>, more: int}
     */
    public function wanted(): array;

    /**
     * 분류 색인 — 분류 이름과 그 분류 문서(없으면 null). 이것은 보는 사람 기준이 **아니다**
     * (기존 규칙 — 비밀글 전부 제외).
     *
     * @return list<array{title: string, title_norm: string, post_id: int|null}>
     */
    public function categoryIndex(): array;
}
