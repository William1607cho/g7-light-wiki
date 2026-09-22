<?php

namespace Plugins\G7\Light\Wiki\Support;

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
 */
final class DocLists implements DocListSource
{
    /** @var array<int, list<array{post_id: int, title: string}>> */
    private array $recentMemo = [];

    /** @var array<int, list<array{post_id: int, title: string}>> */
    private array $createdMemo = [];

    /** @var list<array{id: int, title: string, title_norm: string}>|null */
    private ?array $indexMemo = null;

    /** @var array<string, array{items: list<array<string, mixed>>, more: int}> */
    private array $timelineMemo = [];

    /**
     * @param  list<int>  $exclude  후보에서 뺄 글 ID (대문 글 + 지금 그리는 그 문서 자신)
     * @param  array<string, array{items: list<array{post_id: int, title: string}>, more: int}>  $members
     *                정규화 분류 이름 => 소속 목록 (미리 한 번에 받아 둔 것)
     */
    public function __construct(
        private readonly int $boardId,
        private readonly array $exclude,
        private readonly array $members,
    ) {}

    public function recent(int $limit): array
    {
        return $this->recentMemo[$limit] ??= WikiDocQuery::recent($this->boardId, $this->exclude, $limit);
    }

    public function created(int $limit): array
    {
        return $this->createdMemo[$limit] ??= WikiDocQuery::recentCreated($this->boardId, $this->exclude, $limit);
    }

    public function index(): array
    {
        return $this->indexMemo ??= WikiDocQuery::forIndex($this->boardId, $this->exclude);
    }

    public function randomOne(): ?int
    {
        return WikiDocQuery::randomPostId($this->boardId, $this->exclude);
    }

    public function randomMany(int $limit): array
    {
        return WikiDocQuery::randomDocs($this->boardId, $this->exclude, $limit);
    }

    public function membersOf(string $name): array
    {
        return $this->members[TitleNormalizer::normalize($name)] ?? ['items' => [], 'more' => 0];
    }

    public function timeline(?string $docName): array
    {
        return $this->timelineMemo[$docName ?? ''] ??= WikiRefQuery::timelineFor($this->boardId, $docName);
    }
}
