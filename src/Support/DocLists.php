<?php

namespace Plugins\G7\Light\Wiki\Support;

use Plugins\G7\Light\Wiki\Support\Doc\LinkGraph;
use Plugins\G7\Light\Wiki\Support\Doc\SecretScope;
use Plugins\G7\Light\Wiki\Support\Placeholders\DocGraphSource;
use Plugins\G7\Light\Wiki\Support\Placeholders\DocListSource;

/**
 * 자리표시가 쓸 목록을 조회 계층에서 가져오는 곳 — 요청 하나, 문서 하나에 한 개 만든다.
 *
 * 전에는 이 일이 미들웨어 안의 클로저 여섯 개와 `&$memo` 참조 네 개로 되어 있었다.
 * 무엇을 기억하고 무엇을 기억하지 않는지가 클로저 사이에 흩어져 있어, 조회 횟수를
 * 읽으려면 클로저를 하나씩 따라가야 했다.
 *
 * ## 같은 목록을 두 번 묻지 않는다 — 랜덤만 빼고
 *
 * 같은 자리표시를 한 문서에 두 번 쓰면 조회도 두 번 돈다. 결과가 정해져 있는 것
 * (최근수정·최근작성·색인·연표)은 개수·이름별로 한 번만 조회한다. 색인은 최대 2000행이라
 * 두 번 도는 비용이 특히 크다.
 *
 * **랜덤은 일부러 기억하지 않는다** — 한 문서에 랜덤을 두 번 쓰면 서로 다른 문서가
 * 나오는 편이 자연스럽다.
 *
 * 분류 소속은 이미 한 번에 받아 둔 것에서 꺼낸다(추가 조회 없음).
 *
 * 외톨이·필요한 문서는 링크 그래프({@see LinkGraph})를 한 번 만들어 둘이 함께 쓴다. 이 둘만
 * **보는 사람 기준**이다 — 비밀글 판정({@see SecretScope})도 처음 쓸 때 한 번만 한다.
 */
final class DocLists implements DocGraphSource, DocListSource
{
    /** @var array<int, list<array{post_id: int, title: string}>> */
    private array $recentMemo = [];

    /** @var array<int, list<array{post_id: int, title: string}>> */
    private array $createdMemo = [];

    /** @var list<array{id: int, title: string, title_norm: string}>|null */
    private ?array $indexMemo = null;

    /** @var array<string, array{items: list<array<string, mixed>>, more: int}> */
    private array $timelineMemo = [];

    private ?LinkGraph $graphMemo = null;

    /**
     * @param  list<int>  $exclude  후보에서 뺄 글 ID (대문 글 + 지금 그리는 그 문서 자신)
     * @param  array<string, array{items: list<array{post_id: int, title: string}>, more: int}>  $members
     *                정규화 분류 이름 => 소속 목록 (미리 한 번에 받아 둔 것)
     * @param  SecretScope  $scope  이 요청자가 읽을 수 있는 비밀글 (외톨이·필요한 문서만 쓴다)
     */
    public function __construct(
        private readonly int $boardId,
        private readonly array $exclude,
        private readonly array $members,
        private readonly SecretScope $scope,
    ) {}

    public function recent(int $limit): array
    {
        return $this->recentMemo[$limit] ??= WikiDocListQuery::recent($this->boardId, $this->exclude, $limit);
    }

    public function created(int $limit): array
    {
        return $this->createdMemo[$limit] ??= WikiDocListQuery::recentCreated($this->boardId, $this->exclude, $limit);
    }

    public function index(): array
    {
        return $this->indexMemo ??= WikiDocListQuery::forIndex($this->boardId, $this->exclude);
    }

    public function randomOne(): ?int
    {
        return WikiDocListQuery::randomPostId($this->boardId, $this->exclude);
    }

    public function randomMany(int $limit): array
    {
        return WikiDocListQuery::randomDocs($this->boardId, $this->exclude, $limit);
    }

    public function membersOf(string $name): array
    {
        return $this->members[TitleNormalizer::normalize($name)] ?? ['items' => [], 'more' => 0];
    }

    public function timeline(?string $docName): array
    {
        return $this->timelineMemo[$docName ?? ''] ??= WikiRefQuery::timelineFor($this->boardId, $docName);
    }

    public function orphans(): array
    {
        return WikiLinkGraphQuery::orphans(
            $this->boardId,
            $this->scope,
            $this->exclude,
            $this->graph()->linkedIds(),
            WikiRefQuery::LIST_LIMIT,
        );
    }

    public function wanted(): array
    {
        $graph = $this->graph();
        $found = WikiLinkGraphQuery::wanted($this->boardId, $this->scope, $graph->redNames(), WikiRefQuery::LIST_LIMIT);

        return [
            'items' => array_map(static fn (array $row): array => [
                'target' => $graph->targetOf($row['first_ref']),
                'count' => $row['count'],
            ], $found['items']),
            'more' => $found['more'],
        ];
    }

    private function graph(): LinkGraph
    {
        return $this->graphMemo ??= LinkGraph::build($this->boardId, $this->scope);
    }
}
