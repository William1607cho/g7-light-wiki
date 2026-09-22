<?php

namespace Plugins\G7\Light\Wiki\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Plugins\G7\Light\Wiki\Models\WikiRef;
use Plugins\G7\Light\Wiki\Support\BoardLookup;
use Plugins\G7\Light\Wiki\Support\DocIndexer;
use Plugins\G7\Light\Wiki\Support\FrontCacheInvalidator;
use Plugins\G7\Light\Wiki\Support\RefIndexer;
use Plugins\G7\Light\Wiki\Support\TitleNormalizer;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;
use Plugins\G7\Light\Wiki\Support\WikiCategory;
use Plugins\G7\Light\Wiki\Support\WikiDocQuery;
use Plugins\G7\Light\Wiki\Support\WikiRefQuery;

/**
 * 글이 바뀔 때 위키 문서 색인을 맞추고, 자리표시가 실린 문서들의 봇 캐시를 비운다.
 *
 * ## `'sync' => true` 인 이유
 *
 * 액션 훅은 기본이 큐 디스패치다(`QUEUE_CONNECTION=database`). 색인이 워커를 기다리면
 * 방금 만든 문서로 가는 링크가 몇 초 동안 빨간 링크로 보인다. 저장 응답 안에서 끝나야
 * 하므로 `'sync' => true` 를 명시한다.
 *
 * ## 첫 줄에서 가른다
 *
 * 모든 메서드가 "이 글의 게시판이 위키인가" 를 먼저 보고 아니면 즉시 반환한다.
 * 위키가 아닌 게시판의 글 저장에는 질의 하나(슬러그→ID)와 설정 읽기만 얹힌다.
 */
class PostIndexListener implements HookListenerInterface
{
    /** 이 플러그인이 쓰는 훅 우선순위 (코어 10·20 과 겹치지 않는다) */
    private const PRIORITY = 30;

    /** 글 하나를 저장할 때 봇 캐시를 비울 자리표시 문서의 최대 수 */
    private const CACHE_REFRESH_MAX = 50;

    /** 표기가 걸린 문서까지 합쳐 한 번에 비울 수 있는 최대 수 (명령서 3절 공통 규칙) */
    private const CACHE_TOTAL_MAX = 100;

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-board.post.after_create' => [
                'method' => 'onSaved', 'priority' => self::PRIORITY, 'sync' => true,
            ],
            'sirsoft-board.post.after_update' => [
                'method' => 'onSaved', 'priority' => self::PRIORITY, 'sync' => true,
            ],
            'sirsoft-board.post.after_restore' => [
                'method' => 'onSaved', 'priority' => self::PRIORITY, 'sync' => true,
            ],
            'sirsoft-board.post.after_delete' => [
                'method' => 'onDeleted', 'priority' => self::PRIORITY, 'sync' => true,
            ],
        ];
    }

    /**
     * 생성·수정·복원 — 색인 한 줄을 만들거나 고치고, 자리표시 문서의 봇 캐시를 비운다.
     *
     * @param  mixed  ...$args  (0: Post 모델, 1: 게시판 슬러그, …)
     */
    public function onSaved(...$args): void
    {
        $post = $args[0] ?? null;

        if (! is_object($post)) {
            return;
        }

        $boardId = (int) ($post->board_id ?? 0);

        if (! WikiBoardSettings::isWikiBoard($boardId)) {
            return;
        }

        DocIndexer::sync($post);

        // 표기 색인은 제목 색인 **뒤에** 맞춘다 — 캐시 무효화가 "지금 이 문서가 무엇으로
        // 불리는가"(제목·별칭)를 보기 때문이다.
        $refs = RefIndexer::sync($post);

        $this->refreshPlaceholderCaches($boardId, (string) ($args[1] ?? ''), (int) ($post->id ?? 0), $refs);
    }

    /**
     * 소프트 삭제 — 색인에서 뺀다.
     *
     * @param  mixed  ...$args  (0: Post 모델, 1: 게시판 슬러그, …)
     */
    public function onDeleted(...$args): void
    {
        $post = $args[0] ?? null;

        if (! is_object($post)) {
            return;
        }

        $boardId = (int) ($post->board_id ?? 0);

        if (! WikiBoardSettings::isWikiBoard($boardId)) {
            return;
        }

        $postId = (int) ($post->id ?? 0);

        DocIndexer::forget($postId);

        // 지운 표기가 "저장 전" 이다 — 이 문서가 가리키던 곳들의 캐시도 비워야 한다.
        $before = RefIndexer::forget($postId);

        $this->refreshPlaceholderCaches($boardId, (string) ($args[1] ?? ''), $postId, [
            'before' => $before,
            'after' => [],
        ]);
    }

    /**
     * @inheritDoc
     */
    public function handle(...$args): void
    {
        // 훅별 메서드에서 처리한다.
    }

    /**
     * 자리표시가 실린 문서들의 봇 캐시를 비운다 (방금 저장한 글은 코어가 이미 한다).
     *
     * 1단계에서는 대문 글 하나만 비웠다. 1.5단계부터는 자리표시가 아무 문서에서나 쓰이므로
     * **같은 게시판에서 본문에 `[[#` 가 든 문서**를 저장 시점에 찾아 함께 비운다.
     * 마이그레이션(자리표시 보유 여부를 적어 두는 칸)을 더하지 않고 조회 1회로 해결한다.
     *
     * 상한 {@see self::CACHE_REFRESH_MAX} 을 둔다 — 위키가 커진 게시판에서 글 하나 저장이
     * 수백 건의 캐시 무효화를 끌고 가면 저장 응답이 눈에 띄게 느려진다. 잘리면 경고를 남긴다.
     */
    private function refreshPlaceholderCaches(int $boardId, string $slug, int $savedPostId, array $refs): void
    {
        if ($slug === '') {
            $slug = (string) BoardLookup::slug($boardId);
        }

        if ($slug === '') {
            return;
        }

        $found = WikiDocQuery::placeholderPostIds($boardId, self::CACHE_REFRESH_MAX);
        $targets = $found['ids'];

        if ($found['truncated']) {
            Log::warning('[g7-light-wiki] 자리표시 문서가 상한을 넘어 일부만 봇 캐시를 비웁니다', [
                'board_id' => $boardId,
                'board_slug' => $slug,
                'limit' => self::CACHE_REFRESH_MAX,
            ]);
        }

        // 대문 글은 자리표시가 없어도 비운다 — 1단계부터의 동작이다.
        $frontPostId = WikiBoardSettings::frontPostId($boardId);

        if ($frontPostId !== null) {
            $targets[] = $frontPostId;
        }

        foreach ($this->refTargets($boardId, $refs) as $postId) {
            $targets[] = $postId;
        }

        $targets = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $targets),
            static fn (int $id): bool => $id > 0
        )));

        if (count($targets) > self::CACHE_TOTAL_MAX) {
            Log::warning('[g7-light-wiki] 봇 캐시를 비울 문서가 상한을 넘어 앞부분만 비웁니다', [
                'board_id' => $boardId,
                'board_slug' => $slug,
                'limit' => self::CACHE_TOTAL_MAX,
                'found' => count($targets),
            ]);

            $targets = array_slice($targets, 0, self::CACHE_TOTAL_MAX);
        }

        FrontCacheInvalidator::invalidatePosts($slug, $targets, $savedPostId);
    }

    /**
     * 표기 때문에 낡는 문서들의 글 ID (명령서 3절 봇 캐시 ①②③).
     *
     * ① **링크 대상** — 저장 전후로 이 문서가 가리킨 이름들. 그 문서의 역링크 목록이 바뀐다.
     *    이름은 실제 제목일 수도 별칭일 수도 있어 둘 다 찾는다.
     * ② **분류 문서** — 저장 전후의 분류들. `분류:<이름>` 을 제목으로 가진 문서의 소속 목록이 바뀐다.
     * ③ **별칭이 바뀌었으면** 그 별칭을 링크한 문서들. 링크 색이 빨강↔파랑으로 달라진다.
     *
     * 저장 **전후를 합집합**으로 본다. 링크를 지운 경우엔 "전" 에만 있고, 새로 건 경우엔
     * "후" 에만 있는데, 어느 쪽이든 그 문서의 역링크는 바뀐다.
     *
     * 조회는 최대 4회다(①제목·①별칭·②·③). 표기가 없으면 0회다.
     *
     * @param  array{before?: list<array{kind: string, target_norm: string}>, after?: list<array{kind: string, target_norm: string}>}  $refs
     * @return list<int>
     */
    private function refTargets(int $boardId, array $refs): array
    {
        $before = $refs['before'] ?? [];
        $after = $refs['after'] ?? [];

        $names = static function (array $rows, string $kind): array {
            $out = [];

            foreach ($rows as $row) {
                if (($row['kind'] ?? '') === $kind && ($row['target_norm'] ?? '') !== '') {
                    $out[] = (string) $row['target_norm'];
                }
            }

            return $out;
        };

        $links = array_values(array_unique(array_merge(
            $names($before, WikiRef::KIND_LINK),
            $names($after, WikiRef::KIND_LINK),
        )));

        $categories = array_values(array_unique(array_merge(
            $names($before, WikiRef::KIND_CATEGORY),
            $names($after, WikiRef::KIND_CATEGORY),
        )));

        $aliasBefore = $names($before, WikiRef::KIND_ALIAS);
        $aliasAfter = $names($after, WikiRef::KIND_ALIAS);

        sort($aliasBefore);
        sort($aliasAfter);

        $ids = [];

        // ① 링크 대상 — 실제 제목인 것.
        if ($links !== []) {
            foreach (WikiDocQuery::resolve($boardId, $links) as $doc) {
                $ids[] = (int) $doc['post_id'];
            }

            // ① 링크 대상 — 별칭인 것.
            foreach (WikiRefQuery::aliasPostIds($boardId, $links, self::CACHE_TOTAL_MAX) as $postId) {
                $ids[] = $postId;
            }
        }

        // ② 분류 문서 — 제목이 `분류:<이름>` 인 문서.
        if ($categories !== []) {
            $titles = array_map(
                static fn (string $name): string => TitleNormalizer::normalize(WikiCategory::PREFIX.$name),
                $categories,
            );

            foreach (WikiDocQuery::resolve($boardId, $titles) as $doc) {
                $ids[] = (int) $doc['post_id'];
            }
        }

        // ③ 별칭이 바뀌었을 때만 — 그 별칭들을 링크한 문서들.
        if ($aliasBefore !== $aliasAfter) {
            $changed = array_values(array_unique(array_merge($aliasBefore, $aliasAfter)));

            foreach (WikiRefQuery::linkerPostIds($boardId, $changed, self::CACHE_TOTAL_MAX) as $postId) {
                $ids[] = $postId;
            }
        }

        return $ids;
    }
}
