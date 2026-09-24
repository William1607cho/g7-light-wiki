<?php

namespace Plugins\G7\Light\Wiki\Support\Placeholders;

use Plugins\G7\Light\Wiki\Support\WikiLabels;
use Plugins\G7\Light\Wiki\Support\WikiMarkupParser;

/**
 * 자리표시 디스패처 — 이름으로 렌더러를 찾아 부르기만 한다.
 *
 * 전에는 `FrontPlaceholderRenderer` 하나가 일곱 자리표시의 판정·계산·HTML 조립을 모두
 * 쥐고 있었고, 생성자 인자가 11개(클로저 6 + 문구 배열 2 + …)였다. 지금은 기능마다
 * 렌더러가 따로 있고 이 클래스는 **고르는 일만** 한다.
 *
 * ## 판정 순서 (바뀌지 않았다)
 *
 * 1. 자리표시 토큰이 아니면 `null` — 원문을 그대로 둔다.
 * 2. 요청자에게 그 게시판 읽기 권한이 없으면 **빈 문자열** — 목록을 통째로 지우는 쪽이
 *    "권한 없음" 문구로 존재를 알리는 것보다 낫다. 이름을 모르는 자리표시도 이때는 지워진다.
 * 3. 맡은 렌더러가 없으면 `null` — 원문을 그대로 둔다.
 */
final class PlaceholderRenderer
{
    /** @var array<string, Renderer> 자리표시 이름 → 렌더러 */
    private array $byName = [];

    public function __construct(private readonly bool $canRead, Renderer ...$renderers)
    {
        foreach ($renderers as $renderer) {
            foreach ($renderer->names() as $name) {
                $this->byName[$name] = $renderer;
            }
        }
    }

    /**
     * 이 문서에서 쓸 디스패처를 만든다 — 기능별 렌더러를 전부 등록한다.
     *
     * 외톨이·필요한 문서는 공급자가 {@see DocGraphSource} 도 구현할 때만 등록한다(아니면 그 이름은
     * 맡은 렌더러가 없어 원문 그대로다). 필요한 문서는 본문 빨간 링크와 같은 모양이라 게시판 ID 와
     * 쓰기 권한을 더 받는다 — 뒤에 붙인 선택 인자라 기존 호출은 그대로 선다.
     */
    public static function forDoc(
        string $slug,
        bool $canRead,
        DocListSource $source,
        WikiLabels $labels,
        int $boardId = 0,
        bool $canWrite = false,
    ): self {
        $renderers = [
            new RecentRenderer($slug, $source, $labels),
            new RandomRenderer($slug, $source, $labels),
            new IndexRenderer($slug, $source, $labels),
            new BrowseRenderer($slug, $source, $labels),
            new CategoryListRenderer($slug, $source, $labels),
            new TimelineRenderer($slug, $source, $labels),
        ];

        if ($source instanceof DocGraphSource) {
            $renderers[] = new OrphanRenderer($slug, $source, $labels);
            $renderers[] = new WantedRenderer($boardId, $canWrite, $source, $labels);
        }

        return new self($canRead, ...$renderers);
    }

    /**
     * 자리표시 토큰 하나를 HTML 로 바꿉니다.
     *
     * @param  array<string, mixed>  $token  {@see WikiMarkupParser::tokenize()} 의 토큰
     * @return string|null 대체 HTML. `null` 이면 원문을 그대로 둔다.
     */
    public function render(array $token): ?string
    {
        if (($token['kind'] ?? null) !== WikiMarkupParser::KIND_PLACEHOLDER) {
            return null;
        }

        if (! $this->canRead) {
            return '';
        }

        $name = (string) ($token['name'] ?? '');

        return ($this->byName[$name] ?? null)?->render($name, $token['argument'] ?? null);
    }
}
