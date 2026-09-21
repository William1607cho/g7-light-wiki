<?php

namespace Plugins\G7\Light\Wiki\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\G7\Light\Wiki\Support\BoardLookup;
use Plugins\G7\Light\Wiki\Support\DocIndexer;
use Plugins\G7\Light\Wiki\Support\FrontCacheInvalidator;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;

/**
 * 글이 바뀔 때 위키 문서 색인을 맞추고, 대문 글의 봇 캐시를 비운다.
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
     * 생성·수정·복원 — 색인 한 줄을 만들거나 고치고, 대문 글 봇 캐시를 비운다.
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

        $this->refreshFront($boardId, (string) ($args[1] ?? ''), (int) ($post->id ?? 0));
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

        DocIndexer::forget((int) ($post->id ?? 0));

        $this->refreshFront($boardId, (string) ($args[1] ?? ''), (int) ($post->id ?? 0));
    }

    /**
     * @inheritDoc
     */
    public function handle(...$args): void
    {
        // 훅별 메서드에서 처리한다.
    }

    /**
     * 대문 글의 봇 캐시를 비운다 (대문 글 자신을 저장한 경우는 코어가 이미 한다).
     */
    private function refreshFront(int $boardId, string $slug, int $savedPostId): void
    {
        $frontPostId = WikiBoardSettings::frontPostId($boardId);

        if ($frontPostId === null) {
            return;
        }

        if ($slug === '') {
            $slug = (string) BoardLookup::slug($boardId);
        }

        if ($slug === '') {
            return;
        }

        FrontCacheInvalidator::invalidate($slug, $frontPostId, $savedPostId);
    }
}
