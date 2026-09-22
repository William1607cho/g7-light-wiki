<?php

namespace Plugins\G7\Light\Wiki\Support\Placeholders;

use Plugins\G7\Light\Wiki\Support\WikiDocQuery;
use Plugins\G7\Light\Wiki\Support\WikiHtml;
use Plugins\G7\Light\Wiki\Support\WikiLabels;
use Plugins\G7\Light\Wiki\Support\WikiMarkupParser;

/**
 * `[[#최근수정]]` · `[[#최근작성]]` — 문서 링크 목록.
 *
 * 둘을 한 렌더러가 맡는다. 만드는 모양이 같고 감싸개 class 와 정렬 기준만 다르다
 * (수정은 색인 표의 `edited_at`, 작성은 코어 글 표의 `created_at`).
 */
final class RecentRenderer implements Renderer
{
    public function __construct(
        private readonly string $slug,
        private readonly DocListSource $source,
        private readonly WikiLabels $labels,
    ) {}

    public function names(): array
    {
        return [
            WikiMarkupParser::PLACEHOLDER_RECENT,
            WikiMarkupParser::PLACEHOLDER_RECENT_CREATED,
        ];
    }

    public function render(string $name, mixed $argument): ?string
    {
        if ($name === WikiMarkupParser::PLACEHOLDER_RECENT_CREATED) {
            return WikiHtml::createdList(
                $this->slug,
                $this->source->created(
                    Limit::orDefault($argument, WikiDocQuery::CREATED_DEFAULT, WikiDocQuery::RECENT_MAX)
                ),
                $this->labels->empty(),
            );
        }

        return WikiHtml::recentList(
            $this->slug,
            $this->source->recent(
                Limit::orDefault($argument, WikiDocQuery::RECENT_DEFAULT, WikiDocQuery::RECENT_MAX)
            ),
            $this->labels->empty(),
        );
    }
}
