<?php

namespace Plugins\G7\Light\Wiki\Support\Placeholders;

use Plugins\G7\Light\Wiki\Support\WikiHtml\LinkLists;
use Plugins\G7\Light\Wiki\Support\WikiHtml\Lists;
use Plugins\G7\Light\Wiki\Support\WikiIndexBuilder;
use Plugins\G7\Light\Wiki\Support\WikiLabels;
use Plugins\G7\Light\Wiki\Support\WikiMarkupParser;

/**
 * `[[#분류색인]]` · `[[#분류색인|N]]` — 모든 분류를 첫 글자로 묶은 목록.
 *
 * 모양·정렬·묶음·열 수는 `[[#색인]]` 과 같다 — 묶는 일은 {@see WikiIndexBuilder}, 그리는 일은
 * {@see Lists::index()}, 열 수는 {@see IndexRenderer::columns()} 를 그대로 쓴다. 다른 것은 바깥
 * class 와 항목(분류 문서가 없으면 빨간 링크)뿐이다. 그래서 게시판 ID 와 쓰기 권한을 받는다.
 */
final class CategoryIndexRenderer implements Renderer
{
    public function __construct(
        private readonly string $slug,
        private readonly int $boardId,
        private readonly bool $canWrite,
        private readonly DocGraphSource $source,
        private readonly WikiLabels $labels,
    ) {}

    public function names(): array
    {
        return [WikiMarkupParser::PLACEHOLDER_CATEGORY_INDEX];
    }

    public function render(string $name, mixed $argument): ?string
    {
        return Lists::index(
            $this->slug,
            WikiIndexBuilder::build($this->source->categoryIndex()),
            $this->labels->other(),
            $this->labels->categoryIndexEmpty(),
            IndexRenderer::columns($argument),
            fn (array $item): string => LinkLists::category($this->slug, $this->boardId, $this->canWrite, $item),
            LinkLists::CATEGORY_INDEX_CLASS,
        );
    }
}
