<?php

namespace Plugins\G7\Light\Wiki\Support\Setup;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Services\BoardService;
use Plugins\G7\Light\Wiki\Support\FrontCacheInvalidator;

/**
 * 세트 설치 — 게시판 마련부터 설정 저장까지 한 트랜잭션.
 *
 * ## 순서 (전부 한 `DB::transaction` 안)
 *
 * 1. 게시판 마련 — 새로 만들기(설정값·게시판 관리자 포함) 또는 빈 게시판 위키화
 * 2. 시드 3건 + 색인·추출
 * 3. 설정 저장 — **마지막**. 파일 쓰기라 DB 와 함께 되돌릴 수 없으므로 맨 끝에 두고,
 *    실패하면 예외를 던져 1~2 를 되돌린다.
 *
 * 커밋 뒤 게시판 목록 캐시를 한 번 더 지운다 — 트랜잭션 안의 캐시 삭제와 커밋 사이에 다른
 * 요청이 옛 목록을 다시 채웠을 수 있다. 시드 문서의 봇 캐시도 비운다 — 모듈이 글 생성 때 미리
 * 렌더해 둔 화면은 위키 등록 전 모습이다(스테이징 실측: 대문 봇 화면 HIT, 위키 링크 0).
 *
 * ## 되돌릴 수 없는 것
 *
 * 캐시 삭제(지우기만 하므로 무해)와, 커밋 자체가 실패했는데 설정 파일은 이미 쓴 경우의
 * 설정 속 게시판 id. 후자는 존재하는 게시판으로 거르는 곳(목록 API·제거 판정)에서 걸러진다.
 */
final class WikiSetup
{
    public function __construct(
        private readonly BoardProvisioner $provisioner,
        private readonly SeedWriter $seeds,
        private readonly SetupSettings $settings,
        private readonly AuthorCandidates $authors,
        private readonly BoardService $boards,
    ) {}

    /**
     * 새 게시판을 만들어 위키로 마련한다.
     *
     * @return array{board_id: int, slug: string, front_post_id: int, seed_post_ids: array<string, int>}
     *
     * @throws SetupRejected
     */
    public function createNew(string $name, string $slug, int $authorId, User $actor, string $ipAddress): array
    {
        return $this->run(
            fn (): Board => $this->provisioner->create($name, $slug, (string) $actor->uuid),
            ManagedBoardList::ORIGIN_CREATED,
            $authorId,
            $actor,
            $ipAddress,
        );
    }

    /**
     * 빈 게시판을 위키로 바꿔 마련한다.
     *
     * @return array{board_id: int, slug: string, front_post_id: int, seed_post_ids: array<string, int>}
     *
     * @throws SetupRejected
     */
    public function convert(int $boardId, int $authorId, User $actor, string $ipAddress): array
    {
        return $this->run(
            fn (): Board => $this->provisioner->convert($boardId, (string) $actor->uuid),
            ManagedBoardList::ORIGIN_CONVERTED,
            $authorId,
            $actor,
            $ipAddress,
        );
    }

    /**
     * @param  callable(): Board  $provision
     * @return array{board_id: int, slug: string, front_post_id: int, seed_post_ids: array<string, int>}
     */
    private function run(callable $provision, string $origin, int $authorId, User $actor, string $ipAddress): array
    {
        if ($this->authors->find($authorId) === null) {
            throw new SetupRejected('author_invalid', 422, ['id' => $authorId]);
        }

        $result = DB::transaction(function () use ($provision, $origin, $authorId, $actor, $ipAddress): array {
            $board = $provision();
            $seedIds = $this->seeds->write($board, $authorId, $ipAddress);

            $entry = [
                'board_id' => (int) $board->id,
                'origin' => $origin,
                'seed_post_ids' => $seedIds,
                'author_id' => $authorId,
                'set_up_by' => (int) $actor->id,
                'set_up_at' => now()->toIso8601String(),
            ];

            $this->settings->save(SetupSettingsPatch::afterSetup($this->settings->current(), $entry, $entry['set_up_at']));

            return [
                'board_id' => (int) $board->id,
                'slug' => (string) $board->slug,
                'front_post_id' => $seedIds[SeedDocuments::FRONT],
                'seed_post_ids' => $seedIds,
            ];
        });

        $this->boards->clearAllBoardCaches();
        // 모듈은 글을 만들 때 상세 봇 화면을 바로 렌더해 저장한다. 그때는 아직 위키 등록 전(커밋 전)이라
        // 표기가 치환되지 않은 화면이 TTL 동안 남는다 — 커밋 뒤 시드 문서의 봇 캐시를 비운다.
        FrontCacheInvalidator::invalidatePosts($result['slug'], array_values($result['seed_post_ids']), 0);

        return $result;
    }
}
