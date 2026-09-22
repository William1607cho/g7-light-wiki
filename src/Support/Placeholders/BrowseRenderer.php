<?php

namespace Plugins\G7\Light\Wiki\Support\Placeholders;

use Plugins\G7\Light\Wiki\Support\WikiDocListQuery;
use Plugins\G7\Light\Wiki\Support\WikiHtml\Lists;
use Plugins\G7\Light\Wiki\Support\WikiLabels;
use Plugins\G7\Light\Wiki\Support\WikiMarkupParser;

/**
 * `[[#둘러보기]]` — 왼쪽 "최근 수정" N 개, 오른쪽 "랜덤" N 개의 2단 블록.
 *
 * 왼쪽 단은 `[[#최근수정]]` 과 같은 목록을 쓴다. 같은 개수라면 공급자가 기억해 둔 것을
 * 그대로 받으므로 조회가 늘지 않는다.
 *
 * 2026-09-22 이전에는 왼쪽이 **최근 작성**이었다. 대문에서 보고 싶은 것은 "무엇이 새로
 * 생겼나" 보다 "무엇이 방금 손질됐나" 라는 판단으로 바꿨다(윌리엄 승인). 최근 작성 목록은
 * `[[#최근작성]]` 으로 그대로 쓸 수 있다.
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
        $limit = Limit::orDefault($argument, WikiDocListQuery::TOUR_DEFAULT, WikiDocListQuery::RECENT_MAX);

        return Lists::tour(
            $this->slug,
            $this->source->recent($limit),
            $this->source->randomMany($limit),
            $this->labels->tourRecent(),
            $this->labels->tourRandom(),
            $this->labels->empty(),
        );
    }
}
