<?php

namespace Plugins\G7\Light\Wiki\Support\Placeholders;

use Plugins\G7\Light\Wiki\Support\WikiHtml\LinkLists;
use Plugins\G7\Light\Wiki\Support\WikiLabels;
use Plugins\G7\Light\Wiki\Support\WikiMarkupParser;

/**
 * `[[#외톨이]]` — 다른 문서가 링크하지 않는 문서 목록.
 *
 * 보는 사람이 읽을 수 있는 문서끼리의 링크만 센다({@see DocGraphSource}). `|` 뒤 인자는 무시한다.
 */
final class OrphanRenderer implements Renderer
{
    public function __construct(
        private readonly string $slug,
        private readonly DocGraphSource $source,
        private readonly WikiLabels $labels,
    ) {}

    public function names(): array
    {
        return [WikiMarkupParser::PLACEHOLDER_ORPHAN];
    }

    public function render(string $name, mixed $argument): ?string
    {
        $found = $this->source->orphans();

        return LinkLists::orphans(
            $this->slug,
            $found['items'],
            $this->labels->more($found['more']),
            $this->labels->orphanEmpty(),
        );
    }
}
