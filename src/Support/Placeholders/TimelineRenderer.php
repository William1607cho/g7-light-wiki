<?php

namespace Plugins\G7\Light\Wiki\Support\Placeholders;

use Plugins\G7\Light\Wiki\Support\WikiHtml;
use Plugins\G7\Light\Wiki\Support\WikiLabels;
use Plugins\G7\Light\Wiki\Support\WikiMarkupParser;

/**
 * `[[#연표]]` · `[[#연표|문서명]]` — 사건 목록을 키 순으로.
 *
 * 인자가 없으면 게시판 전체, 있으면 그 문서와 **그 문서를 가리킨 문서들**의 사건이다.
 * 어느 쪽인지 고르는 일은 조회 계층({@see \Plugins\G7\Light\Wiki\Support\WikiRefQuery::timelineFor()})이 한다.
 */
final class TimelineRenderer implements Renderer
{
    public function __construct(
        private readonly string $slug,
        private readonly DocListSource $source,
        private readonly WikiLabels $labels,
    ) {}

    public function names(): array
    {
        return [WikiMarkupParser::PLACEHOLDER_TIMELINE];
    }

    public function render(string $name, mixed $argument): ?string
    {
        $docName = is_string($argument) && trim($argument) !== '' ? trim($argument) : null;

        $found = $this->source->timeline($docName);

        return WikiHtml::timelineList(
            $this->slug,
            $found['items'],
            $found['more'],
            $this->labels->timelineEmpty(),
            $this->labels->more($found['more']),
        );
    }
}
