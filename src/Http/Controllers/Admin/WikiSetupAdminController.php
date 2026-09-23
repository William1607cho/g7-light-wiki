<?php

namespace Plugins\G7\Light\Wiki\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\G7\Light\Wiki\Http\Requests\ConvertWikiBoardRequest;
use Plugins\G7\Light\Wiki\Http\Requests\StoreWikiBoardRequest;
use Plugins\G7\Light\Wiki\Support\Setup\AuthorCandidates;
use Plugins\G7\Light\Wiki\Support\Setup\BoardProvisioner;
use Plugins\G7\Light\Wiki\Support\Setup\ManagedBoardList;
use Plugins\G7\Light\Wiki\Support\Setup\SetupRejected;
use Plugins\G7\Light\Wiki\Support\Setup\SetupSettings;
use Plugins\G7\Light\Wiki\Support\Setup\SetupSettingsPatch;
use Plugins\G7\Light\Wiki\Support\Setup\WikiRelease;
use Plugins\G7\Light\Wiki\Support\Setup\WikiSetup;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;

/**
 * 위키 세트 설치 관리자 API — 상태 조회, 새로 만들기, 빈 게시판 위키화, 해제.
 *
 * `/api/plugins/g7-light-wiki/admin/wiki-setup…`
 *
 * 권한은 라우트 미들웨어가 본다({@see \Plugins\G7\Light\Wiki\Support\Setup\SetupPermissions}).
 * 이 컨트롤러는 요청을 넘기고 응답을 만들기만 한다 — 조회·판정·쓰기는 `Support\Setup` 이 한다.
 */
class WikiSetupAdminController extends AdminBaseController
{
    /**
     * 상태·작성자 후보·위키화 가능한 게시판·관리 게시판.
     */
    public function show(SetupSettings $settings, AuthorCandidates $authors, BoardProvisioner $provisioner): JsonResponse
    {
        $current = $settings->current();
        $state = SetupSettingsPatch::state($current);
        $fronts = [];

        foreach (WikiBoardSettings::normalize($current[WikiBoardSettings::KEY] ?? []) as $row) {
            $fronts[$row['board_id']] = $row['front_post_id'];
        }

        $rows = ManagedBoardList::normalize($current[ManagedBoardList::KEY] ?? []);
        $labels = $provisioner->labels(ManagedBoardList::boardIds($rows));

        $managed = array_map(static fn (array $row): array => [
            'board_id' => $row['board_id'],
            'name' => $labels[$row['board_id']]['name'] ?? null,
            'slug' => $labels[$row['board_id']]['slug'] ?? null,
            'exists' => isset($labels[$row['board_id']]),
            'origin' => $row['origin'],
            'front_post_id' => $fronts[$row['board_id']] ?? null,
            'set_up_at' => $row['set_up_at'],
        ], $rows);

        return ResponseHelper::success('common.success', [
            'setup_completed' => $state['completed_at'] !== null,
            'last_author_id' => $state['last_author_id'],
            'author_candidates' => $authors->list(),
            'convertible_boards' => $provisioner->convertible(),
            'managed_boards' => $managed,
        ]);
    }

    /**
     * 새 게시판을 만들어 위키로 마련합니다.
     */
    public function store(StoreWikiBoardRequest $request, WikiSetup $setup): JsonResponse
    {
        return $this->respond(fn (): array => $setup->createNew(
            trim((string) $request->validated('name')),
            (string) $request->validated('slug'),
            (int) $request->validated('author_id'),
            $request->user(),
            (string) $request->ip(),
        ), 'messages.setup.created', 201);
    }

    /**
     * 빈 게시판을 위키로 바꿉니다.
     */
    public function convert(ConvertWikiBoardRequest $request, int $board, WikiSetup $setup): JsonResponse
    {
        return $this->respond(fn (): array => $setup->convert(
            $board,
            (int) $request->validated('author_id'),
            $request->user(),
            (string) $request->ip(),
        ), 'messages.setup.converted', 201);
    }

    /**
     * 위키를 해제합니다 (게시판·글은 남는다).
     */
    public function release(int $board, WikiRelease $release): JsonResponse
    {
        return $this->respond(fn (): array => $release->release($board), 'messages.setup.released', 200);
    }

    /**
     * 거부는 사유별 번역 문구로, 성공은 결과 그대로.
     *
     * @param  callable(): array<string, mixed>  $work
     */
    private function respond(callable $work, string $successKey, int $status): JsonResponse
    {
        try {
            $result = $work();
        } catch (SetupRejected $e) {
            return ResponseHelper::error(
                'messages.setup.'.$e->reason,
                $e->status,
                ['reason' => [$e->reason]],
                $e->params,
                domain: WikiBoardSettings::IDENTIFIER,
            );
        }

        return ResponseHelper::success($successKey, $result, $status, domain: WikiBoardSettings::IDENTIFIER);
    }
}
