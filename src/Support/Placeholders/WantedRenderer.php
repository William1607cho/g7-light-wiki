<?php

namespace Plugins\G7\Light\Wiki\Support\Placeholders;

use Plugins\G7\Light\Wiki\Support\WikiHtml\LinkLists;
use Plugins\G7\Light\Wiki\Support\WikiLabels;
use Plugins\G7\Light\Wiki\Support\WikiMarkupParser;

/**
 * `[[#필요한문서]]` — 링크는 걸렸는데 아직 없는 제목, 링크한 문서가 많은 순서.
 *
 * 항목은 본문의 빨간 링크와 같다 — 쓰기 권한이 있으면 작성 화면으로 가는 링크, 없으면 빨간 글자.
 * 그래서 게시판 ID 와 쓰기 권한을 받는다. `|` 뒤 인자는 무시한다.
 */
final class WantedRenderer implements Renderer
{
    public function __construct(
        private readonly int $boardId,
        private readonly bool $canWrite,
        private readonly DocGraphSource $source,
        private readonly WikiLabels $labels,
    ) {}

    public function names(): array
    {
        return [WikiMarkupParser::PLACEHOLDER_WANTED];
    }

    public function render(string $name, mixed $argument): ?string
    {
        $found = $this->source->wanted();

        return LinkLists::wanted(
            $this->boardId,
            $this->canWrite,
            $found['items'],
            $this->labels->more($found['more']),
            $this->labels->wantedEmpty(),
        );
    }
}
