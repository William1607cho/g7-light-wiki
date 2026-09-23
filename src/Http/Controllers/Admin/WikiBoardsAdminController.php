<?php

namespace Plugins\G7\Light\Wiki\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Plugins\G7\Light\Wiki\Http\Requests\UpdateWikiBoardsRequest;
use Plugins\G7\Light\Wiki\Support\Setup\BoardProvisioner;
use Plugins\G7\Light\Wiki\Support\Setup\ManagedBoardList;
use Plugins\G7\Light\Wiki\Support\Setup\SetupSettings;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;

/**
 * 위키 게시판 설정 화면이 쓰는 관리자 API.
 *
 * `GET|PUT /api/plugins/g7-light-wiki/admin/wiki-boards`
 *
 * 권한은 라우트 미들웨어가 본다 — 조회 `core.plugins.read`, 저장 `core.plugins.update`.
 * 이 설정은 "플러그인 설정 변경" 의 일부라 코어 권한을 그대로 쓰고 별도 권한을 만들지 않는다.
 *
 * 저장 검증(명령서 2-1): **게시판이 실제로 있고**, 대문 글을 지정했다면 **그 글이 그
 * 게시판 소속**이어야 한다. 대문 글은 비워 둘 수 있다(대문 없이 링크 기능만 쓰는 게시판).
 */
class WikiBoardsAdminController extends AdminBaseController
{
    /**
     * 게시판 목록과 현재 설정을 반환합니다.
     */
    public function show(): JsonResponse
    {
        return ResponseHelper::success('common.success', $this->payload());
    }

    /**
     * 설정을 저장합니다.
     */
    public function update(UpdateWikiBoardsRequest $request): JsonResponse
    {
        $rows = $request->rows();
        $errors = $this->validateRows($rows) + $this->keptManagedRows($rows);

        if ($errors !== []) {
            return ResponseHelper::error(
                'messages.settings.invalid',
                422,
                $errors,
                domain: WikiBoardSettings::IDENTIFIER,
            );
        }

        $failureReason = null;

        if (! WikiBoardSettings::save($rows, $failureReason)) {
            Log::error('[g7-light-wiki] 위키 게시판 설정 저장 실패', ['reason' => $failureReason]);

            return ResponseHelper::error(
                'messages.settings.save_failed',
                500,
                domain: WikiBoardSettings::IDENTIFIER,
            );
        }

        return ResponseHelper::success(
            'messages.settings.saved',
            $this->payload(),
            domain: WikiBoardSettings::IDENTIFIER,
        );
    }

    /**
     * 게시판·대문 글의 실재를 확인한다.
     *
     * @param  list<array{board_id: int, front_post_id: ?int}>  $rows
     * @return array<string, list<string>> 필드별 오류 메시지 (없으면 빈 배열)
     */
    private function validateRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $boardIds = array_map(static fn (array $row): int => $row['board_id'], $rows);
        $existing = Board::query()->whereIn('id', $boardIds)->pluck('id')
            ->map(static fn ($id): int => (int) $id)->all();

        $errors = [];

        foreach ($rows as $index => $row) {
            $field = "wiki_boards.{$index}";

            if (! in_array($row['board_id'], $existing, true)) {
                $errors[$field.'.board_id'] = [
                    (string) __('g7-light-wiki::messages.settings.board_missing', ['id' => $row['board_id']]),
                ];

                continue;
            }

            if ($row['front_post_id'] === null) {
                continue;
            }

            $belongs = Post::query()
                ->where('id', $row['front_post_id'])
                ->where('board_id', $row['board_id'])
                ->exists();

            if (! $belongs) {
                $errors[$field.'.front_post_id'] = [
                    (string) __('g7-light-wiki::messages.settings.front_post_mismatch', ['id' => $row['front_post_id']]),
                ];
            }
        }

        return $errors;
    }

    /**
     * 세트 설치로 마련한 게시판(관리 대상)의 줄은 이 API 로 지울 수 없다 — 해제 API 로만 뺀다.
     * 여기서 지우면 관리 기록(`managed_boards`)과 실제 위키 목록이 어긋나고, 해제가 하는 색인
     * 정리도 빠진다. 수동 등록 위키 게시판은 전과 같이 자유롭게 넣고 뺄 수 있다.
     *
     * @param  list<array{board_id: int, front_post_id: ?int}>  $rows
     * @return array<string, list<string>>
     */
    private function keptManagedRows(array $rows): array
    {
        $kept = array_map(static fn (array $row): int => $row['board_id'], $rows);
        $managedIds = ManagedBoardList::boardIds(app(SetupSettings::class)->managed());
        // 게시판 자체가 지워진 관리 줄은 화면에 나오지 않으므로(아래 payload 가 거른다) 따지지 않는다.
        $existing = array_keys(app(BoardProvisioner::class)->labels($managedIds));
        $missing = array_diff($existing, $kept);

        if ($missing === []) {
            return [];
        }

        return [
            'wiki_boards' => array_values(array_map(
                static fn (int $id): string => (string) __('g7-light-wiki::messages.setup.managed_row_removed', ['id' => $id]),
                $missing,
            )),
        ];
    }

    /**
     * 조회·저장 공통 응답.
     *
     * @return array{boards: list<array<string, mixed>>, wiki_boards: list<array{board_id: int, front_post_id: ?int}>}
     */
    private function payload(): array
    {
        // audit:allow query-unbounded-get reason: boards 는 운영자 등록 설정성 테이블 — 행 수가 운영자 행위에 묶여 데이터 증가에 비례하지 않는다
        $boards = Board::query()->orderBy('id')->get(['id', 'name', 'slug', 'is_active', 'type']);
        $boardIds = $boards->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $rows = array_values(array_filter(
            WikiBoardSettings::all(),
            static fn (array $row): bool => in_array($row['board_id'], $boardIds, true),
        ));

        return [
            'boards' => $boards->map(static fn (Board $board): array => [
                'id' => (int) $board->id,
                'name' => $board->getLocalizedName(),
                'slug' => (string) $board->slug,
                'type' => (string) ($board->type instanceof \BackedEnum ? $board->type->value : $board->type),
                'is_active' => (bool) $board->is_active,
            ])->values()->all(),
            'wiki_boards' => $rows,
        ];
    }
}
