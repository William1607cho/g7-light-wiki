<?php

namespace Plugins\G7\Light\Wiki\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Plugins\G7\Light\Wiki\Support\DocIndexer;
use Plugins\G7\Light\Wiki\Support\RefIndexer;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;

/**
 * 게시판이 삭제되면 그 게시판의 위키 문서 색인을 모두 지운다.
 *
 * 게시판 삭제는 글을 **영구 삭제**하므로(코어 `BoardService::deleteBoard`) 글 단위
 * `after_delete` 가 발화하지 않는다. 그대로 두면 색인에 고아 줄이 남는다.
 *
 * 설정(`wiki_boards`)의 그 항목은 **건드리지 않는다.** 설정은 운영자가 관리자 화면에서
 * 정하는 값이고, 삭제 훅이 조용히 설정을 고치면 화면에서 본 것과 저장된 것이 달라진다.
 * 없는 게시판 ID 는 설정 화면이 다음 저장 때 걸러 낸다. 여기서는 기록만 남긴다.
 */
class BoardCleanupListener implements HookListenerInterface
{
    /** 이 플러그인이 쓰는 훅 우선순위 (코어 20 과 겹치지 않는다) */
    private const PRIORITY = 30;

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-board.board.after_delete' => [
                'method' => 'onBoardDeleted', 'priority' => self::PRIORITY, 'sync' => true,
            ],
        ];
    }

    /**
     * @param  mixed  ...$args  (0: Board 모델)
     */
    public function onBoardDeleted(...$args): void
    {
        $board = $args[0] ?? null;

        if (! is_object($board)) {
            return;
        }

        $boardId = (int) ($board->id ?? 0);

        if (! WikiBoardSettings::isWikiBoard($boardId)) {
            return;
        }

        $removed = DocIndexer::forgetBoard($boardId);
        $removedRefs = RefIndexer::forgetBoard($boardId);

        Log::warning('[g7-light-wiki] 위키 게시판이 삭제되어 문서 색인을 지웠습니다. 플러그인 설정의 해당 항목은 그대로 두었으니 관리자 화면에서 정리하세요', [
            'board_id' => $boardId,
            'removed_rows' => $removed,
            'removed_ref_rows' => $removedRefs,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function handle(...$args): void
    {
        // 훅별 메서드에서 처리한다.
    }
}
