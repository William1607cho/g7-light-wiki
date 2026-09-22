<?php

namespace Plugins\G7\Light\Wiki\Support\Placeholders;

use Plugins\G7\Light\Wiki\Support\WikiHtml\Lists;
use Plugins\G7\Light\Wiki\Support\WikiIndexBuilder;
use Plugins\G7\Light\Wiki\Support\WikiLabels;
use Plugins\G7\Light\Wiki\Support\WikiMarkupParser;

/**
 * `[[#색인]]` — 가나다 색인.
 *
 * 묶는 일은 {@see WikiIndexBuilder} 가 하고, 여기서는 목록을 받아 넘기기만 한다.
 */
final class IndexRenderer implements Renderer
{
    public function __construct(
        private readonly string $slug,
        private readonly DocListSource $source,
        private readonly WikiLabels $labels,
    ) {}

    public function names(): array
    {
        return [WikiMarkupParser::PLACEHOLDER_INDEX];
    }

    public function render(string $name, mixed $argument): ?string
    {
        return Lists::index(
            $this->slug,
            WikiIndexBuilder::build($this->source->index()),
            $this->labels->other(),
            $this->labels->empty(),
        );
    }
}
