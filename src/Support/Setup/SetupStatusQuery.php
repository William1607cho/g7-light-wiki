<?php

namespace Plugins\G7\Light\Wiki\Support\Setup;

use App\Models\User;
use Modules\Sirsoft\Board\Models\Post;

/**
 * 설정 화면 상태 조회 — 관리 게시판 줄에 붙일 값을 DB 에서 모은다.
 *
 * 조립은 {@see SetupStatusView}(순수)가 하고, 여기서는 **세기와 찾기만** 한다.
 */
final class SetupStatusQuery
{
    /**
     * 게시판별로, 기록된 시드 글 가운데 지금 그 게시판에 살아 있는 글 수.
     *
     * 휴지통 글은 세지 않는다 — 사용자가 시드 문서를 지웠다면 그 사실이 화면에 보여야 한다.
     *
     * @param  list<array<string, mixed>>  $rows  정리된 `managed_boards`
     * @return array<int, int> 게시판 id => 살아 있는 시드 글 수
     */
    public function liveSeedCounts(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $ids = array_values(array_filter($row['seed_post_ids'] ?? [], static fn ($id): bool => is_int($id)));

            $counts[$row['board_id']] = $ids === []
                ? 0
                : Post::query()->where('board_id', $row['board_id'])->whereIn('id', $ids)->count();
        }

        return $counts;
    }

    /**
     * 사용자 id 로 이름을 찾는다 (없는 사용자는 결과에 없다. 이메일은 싣지 않는다).
     *
     * @param  list<int>  $userIds
     * @return array<int, string>
     */
    public function userNames(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds, static fn ($id): bool => is_int($id) && $id > 0)));

        if ($userIds === []) {
            return [];
        }

        return User::query()->whereKey($userIds)->pluck('name', 'id')
            ->mapWithKeys(static fn ($name, $id): array => [(int) $id => (string) $name])
            ->all();
    }
}
