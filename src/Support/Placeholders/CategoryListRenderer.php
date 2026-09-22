<?php

namespace Plugins\G7\Light\Wiki\Support\Placeholders;

use Plugins\G7\Light\Wiki\Support\WikiHtml;
use Plugins\G7\Light\Wiki\Support\WikiLabels;
use Plugins\G7\Light\Wiki\Support\WikiMarkupParser;

/**
 * `[[#분류|이름]]` — 그 분류에 속한 문서 목록.
 *
 * 이름이 없는 `[[#분류]]` 는 **원문 그대로** 둔다(`null`). 어느 분류인지 알 수 없는데
 * 빈 목록을 그리면 "속한 문서가 없는 분류" 로 잘못 읽힌다.
 *
 * 속한 문서가 0건이면 빈 목록 안내를 그린다 — 이 자리표시는 사람이 "여기에 목록을
 * 놓아라" 고 적은 것이라 아무것도 안 나오면 적은 것이 사라진 것처럼 보인다.
 */
final class CategoryListRenderer implements Renderer
{
    public function __construct(
        private readonly string $slug,
        private readonly DocListSource $source,
        private readonly WikiLabels $labels,
    ) {}

    public function names(): array
    {
        return [WikiMarkupParser::PLACEHOLDER_CATEGORY];
    }

    public function render(string $name, mixed $argument): ?string
    {
        if (! is_string($argument) || trim($argument) === '') {
            return null;
        }

        $found = $this->source->membersOf(trim($argument));

        return WikiHtml::docSection(
            $this->slug,
            $this->labels->categoryMembers(),
            $found['items'],
            $found['more'],
            'g7lw-category-members',
            $this->labels->more($found['more']),
        ) ?: WikiHtml::emptyNotice('g7lw-category-members', $this->labels->docEmpty());
    }
}
