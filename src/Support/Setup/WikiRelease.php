<?php

namespace Plugins\G7\Light\Wiki\Support\Setup;

use Plugins\G7\Light\Wiki\Support\DocIndexer;
use Plugins\G7\Light\Wiki\Support\RefIndexer;

/**
 * 위키 해제 — 설정에서 그 게시판을 빼고, 이 플러그인이 그 게시판에 대해 만든 색인·추출 행을 지운다.
 *
 * 게시판·글은 **그대로** 둔다. 해제하면 일반 게시판이 되고, 문서의 `[[…]]` 표기는 글자 그대로
 * 보인다. 봇 화면 캐시는 지우지 않는다 — TTL(2시간) 뒤 저절로 새로 만들어진다(확인 문구로 안내).
 *
 * **세트 설치로 마련한 게시판(관리 대상)만** 받는다. 수동으로 등록한 위키 게시판은 이 API 로
 * 건드릴 수 없다.
 *
 * 설정을 먼저 쓴다 — 설정에서 빠지는 순간 모든 미들웨어·리스너가 그 게시판을 무시하므로,
 * 색인 삭제가 실패해도 화면은 해제된 상태가 되고 남은 행은 `light-wiki:rebuild` 가 정리한다.
 */
final class WikiRelease
{
    private readonly TableGuard $tables;

    /**
     * @param  TableGuard|null  $tables  비우면 코어 스키마 빌더로 판정한다
     */
    public function __construct(
        private readonly SetupSettings $settings,
        ?TableGuard $tables = null,
    ) {
        $this->tables = $tables ?? TableGuard::schema();
    }

    /**
     * @return array{board_id: int, removed_docs: int, removed_refs: int}
     *
     * @throws SetupRejected 관리 대상이 아님
     */
    public function release(int $boardId): array
    {
        $current = $this->settings->current();

        if (! in_array($boardId, ManagedBoardList::boardIds($current[ManagedBoardList::KEY] ?? []), true)) {
            throw new SetupRejected('not_managed', 422, ['id' => $boardId]);
        }

        $this->settings->save(SetupSettingsPatch::afterRelease($current, $boardId));

        $removed = $this->tables->run(
            static fn (): array => [DocIndexer::forgetBoard($boardId), RefIndexer::forgetBoard($boardId)],
            [0, 0],
        );

        return ['board_id' => $boardId, 'removed_docs' => $removed[0], 'removed_refs' => $removed[1]];
    }
}
