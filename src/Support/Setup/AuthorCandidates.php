<?php

namespace Plugins\G7\Light\Wiki\Support\Setup;

use App\Models\User;

/**
 * 시드 문서 작성자 후보 — `admin` 역할 보유자.
 *
 * 시드 문서는 나중에 고칠 수 있는 사람의 글이어야 한다. `admin` 역할은 게시판 권한 전부를
 * 가진다. 후보가 적어 목록으로 충분하므로 코어 회원 검색 API 를 쓰지 않고, 응답에 이메일을
 * 싣지 않는다.
 */
final class AuthorCandidates
{
    /** 후보로 삼는 역할 */
    public const ROLE = 'admin';

    /** 목록 상한 */
    private const LIMIT = 50;

    /**
     * 후보 목록.
     *
     * @return list<array{id: int, name: string}>
     */
    public function list(): array
    {
        return $this->candidates()
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get(['id', 'name'])
            ->map(static fn (User $user): array => ['id' => (int) $user->id, 'name' => (string) $user->name])
            ->values()
            ->all();
    }

    /**
     * 이 사용자가 지금도 후보인가 (저장 직전 재확인).
     */
    public function find(int $userId): ?User
    {
        return $this->candidates()->whereKey($userId)->first();
    }

    /**
     * 후보 조건 — 이 한 곳에만 적는다.
     *
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    private function candidates(): \Illuminate\Database\Eloquent\Builder
    {
        return User::query()->whereHas('roles', static fn ($q) => $q->where('identifier', self::ROLE));
    }
}
