<?php

namespace Plugins\G7\Light\Wiki\Support\Placeholders;

use Plugins\G7\Light\Wiki\Support\WikiHtml\Lists;
use Plugins\G7\Light\Wiki\Support\WikiIndexBuilder;
use Plugins\G7\Light\Wiki\Support\WikiLabels;
use Plugins\G7\Light\Wiki\Support\WikiMarkupParser;

/**
 * `[[#색인]]` · `[[#색인|N]]` — 가나다 색인.
 *
 * 묶는 일은 {@see WikiIndexBuilder} 가, 그리는 일은 {@see Lists::index()} 가 하고,
 * 여기서는 목록과 **최대 열 수**를 넘기기만 한다.
 */
final class IndexRenderer implements Renderer
{
    /** 인자가 없거나 쓸 수 없을 때의 최대 열 수. */
    private const COLUMNS_DEFAULT = 3;

    /** `[[#색인|N]]` 이 받아들이는 최대값. */
    private const COLUMNS_MAX = 4;

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
            self::columns($argument),
        );
    }

    /**
     * `|N` 을 최대 열 수로 바꾼다 — 다른 자리표시와 같은 {@see Limit} 규칙을 쓰고,
     * 1~4 밖이면 오류 없이 기본값으로 돌린다.
     *
     * {@see Limit::optional()} 은 최대값에서 **자르므로**, 자르는 자리를 한 칸 위에 두어
     * "너무 큰 수" 와 "받아들이는 최대값" 을 가른다. 그래야 `|9` 가 4 열이 아니라
     * 기본값 3 열이 된다(개수를 받는 `[[#최근수정|999]]` 와 달리, 열 수는 잘라서 주면
     * 사용자가 적은 뜻과 멀어진다).
     */
    private static function columns(mixed $argument): int
    {
        $requested = Limit::optional($argument, self::COLUMNS_MAX + 1);

        return $requested === null || $requested > self::COLUMNS_MAX
            ? self::COLUMNS_DEFAULT
            : $requested;
    }
}
