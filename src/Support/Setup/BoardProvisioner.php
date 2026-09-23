<?php

namespace Plugins\G7\Light\Wiki\Support\Setup;

use App\Models\Role;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Services\BoardService;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;

/**
 * 위키 게시판을 마련한다 — 새로 만들기 / 빈 게시판 위키화.
 *
 * 게시판 생성·수정은 모듈 서비스(`BoardService`)로만 한다. 역할 2개 생성, 기본 권한 전개,
 * 게시판 관리자 연결, 훅 발행이 모두 그 안에서 일어난다 — 여기서 복제하지 않는다.
 *
 * 둘 다 호출부({@see WikiSetup})의 트랜잭션 안에서 불린다.
 */
final class BoardProvisioner
{
    public function __construct(private readonly BoardService $boards) {}

    /**
     * 새 위키 게시판을 만든다.
     *
     * @param  string  $managerUuid  게시판 관리자로 넣을 사람 (설정을 저장한 관리자)
     */
    public function create(string $name, string $slug, string $managerUuid): Board
    {
        $defaults = function_exists('g7_module_settings')
            ? g7_module_settings('sirsoft-board', 'basic_defaults', [])
            : [];

        return $this->boards->createBoard(
            NewBoardData::build(is_array($defaults) ? $defaults : [], $name, $slug, $managerUuid),
        );
    }

    /**
     * 빈 게시판을 위키로 바꾼다.
     *
     * 게시판 행을 잠근 뒤 판정한다 — 화면에서 본 "위키화 가능" 과 저장 사이에 누가 글을 써도
     * 여기서 걸린다. 설정은 위키 고정 칸(네 칸)**만** 보낸다: 권한·다른 설정은 그대로 남는다.
     * 게시판 관리자는 건드리지 않되, 0명이면 저장한 관리자를 넣는다(0명이면 나중에 관리자
     * 화면의 게시판 설정 저장이 "관리자 1명 이상" 규칙에 걸린다).
     *
     * @throws SetupRejected 게시판 없음·위키화 불가
     */
    public function convert(int $boardId, string $managerUuid): Board
    {
        $board = Board::query()->whereKey($boardId)->lockForUpdate()->first();

        if (! $board instanceof Board) {
            throw new SetupRejected('board_missing', 404, ['id' => $boardId]);
        }

        $reason = ConvertEligibility::reason(BoardFacts::of($board, WikiBoardSettings::boardIds()));

        if ($reason !== ConvertEligibility::OK) {
            throw new SetupRejected($reason, ConvertEligibility::status($reason));
        }

        $changes = NewBoardData::WIKI_SETTINGS;

        if ($this->managerCount($board) === 0) {
            $changes['board_manager_ids'] = [$managerUuid];
        }

        return $this->boards->updateBoard($boardId, $changes);
    }

    /**
     * 위키화할 수 있는 게시판 목록 (화면 표시용 참고값 — 저장 때 다시 판정한다).
     *
     * @return list<array{id: int, name: string, slug: string}>
     */
    public function convertible(): array
    {
        $wikiIds = WikiBoardSettings::boardIds();

        // audit:allow query-unbounded-get reason: boards 는 운영자 등록 설정성 테이블 — 행 수가 운영자 행위에 묶인다
        return Board::query()->orderBy('id')->get()
            ->filter(static fn (Board $board): bool => ConvertEligibility::allows(BoardFacts::of($board, $wikiIds)))
            ->map(static fn (Board $board): array => [
                'id' => (int) $board->id,
                'name' => $board->getLocalizedName(),
                'slug' => (string) $board->slug,
            ])
            ->values()
            ->all();
    }

    /**
     * 게시판 id 로 이름·slug 를 찾는다 (없는 게시판은 결과에 없다).
     *
     * @param  list<int>  $boardIds
     * @return array<int, array{name: string, slug: string}>
     */
    public function labels(array $boardIds): array
    {
        if ($boardIds === []) {
            return [];
        }

        $labels = [];

        foreach (Board::query()->whereIn('id', $boardIds)->get() as $board) {
            $labels[(int) $board->id] = ['name' => $board->getLocalizedName(), 'slug' => (string) $board->slug];
        }

        return $labels;
    }

    /**
     * 실제로 있는 관리 게시판 수 — 제거 거부 판정용. 모듈의 `boards` 표만 본다(이 플러그인의
     * 표는 보지 않으므로 `--delete-data` 가 표를 먼저 지운 순서에서도 안전하다).
     *
     * @param  list<array<string, mixed>>  $managedRows  설정 `managed_boards`
     */
    public static function existingCount(array $managedRows): int
    {
        $ids = ManagedBoardList::boardIds($managedRows);

        if ($ids === []) {
            return 0;
        }

        $existing = Board::query()->whereIn('id', $ids)->pluck('id')
            ->map(static fn ($id): int => (int) $id)->all();

        return count(ManagedBoardList::existingIds($managedRows, $existing));
    }

    /**
     * 게시판 관리자 역할에 연결된 사용자 수.
     */
    private function managerCount(Board $board): int
    {
        $role = Role::query()->where('identifier', "sirsoft-board.{$board->slug}.manager")->first();

        return $role instanceof Role ? $role->users()->count() : 0;
    }
}
