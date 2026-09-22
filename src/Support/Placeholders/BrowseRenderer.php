<?php

namespace Plugins\G7\Light\Wiki\Support\Placeholders;

use Plugins\G7\Light\Wiki\Support\WikiDocQuery;
use Plugins\G7\Light\Wiki\Support\WikiHtml;
use Plugins\G7\Light\Wiki\Support\WikiLabels;
use Plugins\G7\Light\Wiki\Support\WikiMarkupParser;

/**
 * `[[#둘러보기]]` — 왼쪽 "최근 작성" N 개, 오른쪽 "랜덤" N 개의 2단 블록.
 *
 * 왼쪽 단은 `[[#최근작성]]` 과 같은 목록을 쓴다. 같은 개수라면 공급자가 기억해 둔 것을
 * 그대로 받으므로 조회가 늘지 않는다.
 */
final class BrowseRenderer implements Renderer
{
    public function __construct(
        private readonly string $slug,
        private readonly DocListSource $source,
        private readonly WikiLabels $labels,
    ) {}

    public function names(): array
    {
        return [WikiMarkupParser::PLACEHOLDER_TOUR];
    }

    public function render(string $name, mixed $argument): ?string
    {
        $limit = Limit::orDefault($argument, WikiDocQuery::TOUR_DEFAULT, WikiDocQuery::RECENT_MAX);

        return WikiHtml::tour(
            $this->slug,
            $this->source->created($limit),
            $this->source->randomMany($limit),
            $this->labels->tourCreated(),
            $this->labels->tourRandom(),
            $this->labels->empty(),
        );
    }
}
