<?php

namespace Plugins\G7\Light\Wiki\Support\Placeholders;

use Plugins\G7\Light\Wiki\Support\WikiDocQuery;
use Plugins\G7\Light\Wiki\Support\WikiHtml;
use Plugins\G7\Light\Wiki\Support\WikiLabels;
use Plugins\G7\Light\Wiki\Support\WikiMarkupParser;

/**
 * `[[#랜덤]]` · `[[#랜덤|N]]` — N 이 2 이상이면 서로 다른 문서 N 개의 목록, 그 밖에는 **링크 하나**.
 *
 * 인자를 적지 않은 `[[#랜덤]]` 은 1단계와 같은 결과여야 한다(하위 호환). `|1` 도 같게 둔다 —
 * "1개짜리 목록" 과 "링크 하나" 중 눈에 익은 쪽을 고른다.
 *
 * 대상은 **치환 시점에** 고른다. 새로고침하면 다시 뽑힌다.
 */
final class RandomRenderer implements Renderer
{
    public function __construct(
        private readonly string $slug,
        private readonly DocListSource $source,
        private readonly WikiLabels $labels,
    ) {}

    public function names(): array
    {
        return [WikiMarkupParser::PLACEHOLDER_RANDOM];
    }

    public function render(string $name, mixed $argument): ?string
    {
        $count = Limit::optional($argument, WikiDocQuery::RANDOM_MAX);

        if ($count !== null && $count >= 2) {
            return WikiHtml::randomList($this->slug, $this->source->randomMany($count), $this->labels->empty());
        }

        $postId = $this->source->randomOne();

        if (! is_int($postId) || $postId < 1) {
            return WikiHtml::randomEmpty($this->labels->empty());
        }

        return WikiHtml::randomLink($this->slug, $postId, $this->labels->random());
    }
}
