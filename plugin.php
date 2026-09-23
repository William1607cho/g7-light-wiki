<?php

namespace Plugins\G7\Light\Wiki;

use App\Enums\ExtensionOwnerType;
use App\Extension\AbstractPlugin;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use Plugins\G7\Light\Wiki\Http\Middleware\PrefillWikiTitleExtension;
use Plugins\G7\Light\Wiki\Http\Middleware\RenderWikiLinksExtension;
use Plugins\G7\Light\Wiki\Http\Middleware\WikiBoardListExtension;
use Plugins\G7\Light\Wiki\Http\Middleware\WikiFormMetaExtension;
use Plugins\G7\Light\Wiki\Listeners\BoardCleanupListener;
use Plugins\G7\Light\Wiki\Listeners\PostIndexListener;
use Plugins\G7\Light\Wiki\Listeners\PostTitleGuardListener;
use Plugins\G7\Light\Wiki\Support\Setup\BoardProvisioner;
use Plugins\G7\Light\Wiki\Support\Setup\ManagedBoardList;
use Plugins\G7\Light\Wiki\Support\Setup\SetupSettings;
use Plugins\G7\Light\Wiki\Support\Setup\SetupSettingsPatch;
use Plugins\G7\Light\Wiki\Support\Setup\UninstallGuard;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;

/**
 * 가벼운 위키 (g7-light-wiki)
 *
 * sirsoft-board 기본형 게시판을 개인 위키로 쓰게 한다. 게시판별로 켜며, 권한 구성에 따라
 * 공개 위키와 비공개 위키를 겸용한다. 코어·sirsoft-board·템플릿은 **파일 한 줄도 고치지
 * 않는다** — 전용 테이블 2개, 응답 미들웨어 4개, 훅 리스너 3개, 플러그인 라우트 7개로만 동작한다.
 * 세트 설치 API 는 게시판·글을 **모듈 서비스로** 만든다(코어·모듈 파일은 여전히 그대로다).
 *
 * ## 하는 일
 *
 * 1. **제목이 문서 이름** — 위키 게시판에서 정규화 제목이 같은 글 두 개를 막는다(422).
 *    무엇을 같은 제목으로 볼지는 `Support\TitleNormalizer` 가 정하고, 최후의 보루는
 *    색인 표의 `(board_id, title_norm)` 유니크 인덱스다.
 * 2. **문서 링크** — 글 상세 응답에서 본문의 `[[문서명]]`·`[[문서명|표시]]` 를 링크로 바꾼다.
 *    없는 문서는 빨간 링크(글쓰기 권한이 없으면 빨간 글자)다.
 * 3. **자리표시** — 위키 게시판의 HTML 모드 문서에서 `[[#최근수정]]`·`[[#최근작성]]`·
 *    `[[#랜덤]]`·`[[#색인]]`·`[[#둘러보기]]` 를 채운다(1.5단계부터 대문 전용이 아니다).
 *    랜덤 대상은 **치환 시점에** 요청자 기준으로 고른다(새로고침하면 다시 뽑힌다).
 * 4. **제목 미리 채우기** — 빨간 링크를 누르면 그 제목이 들어간 작성 화면이 열린다.
 * 5. **문서 목록** — 위키 게시판의 글 목록을 검색어 없으면 대문 1건, 검색어가 있으면
 *    제목이 걸리는 문서 목록으로 바꾼다. 항목은 코어 변환기가 만든 것을 그대로 쓴다.
 *
 * ## 권한을 어디서 보는가
 *
 * 브라우저가 **전체 페이지 이동**으로 여는 주소(`…/new`)에는 SPA 가 붙이는 Bearer 토큰이
 * 없어 로그인한 사람도 비회원으로 보인다. 그래서 그런 주소에서는 권한을 보지 않고,
 * 토큰이 실려 오는 곳(글 상세 응답·작성 폼 데이터 응답)과 코어의 권한 미들웨어에 맡긴다.
 *
 * ## 되돌리기
 *
 * 비활성화하면 미들웨어·훅이 등록 대상에서 빠져 **응답이 설치 전과 같아진다**(본문 DB 는
 * 손대지 않으므로 `[[…]]` 가 다시 글자로 보인다). 아무것도 지우지 않는다.
 *
 * 제거(uninstall)는 세트 설치로 마련한 위키 게시판이 하나라도 남아 있으면 **거부**한다 —
 * 설정 화면에서 해제한 뒤 제거한다. 게시판·글은 이 플러그인이 어떤 경우에도 지우지 않는다.
 * 색인 표·설정은 `plugin:uninstall --delete-data` 에서만 코어가 지우고, 언제든
 * `light-wiki:rebuild` 로 다시 만들 수 있다.
 *
 * ## 본문에 쓰면 안 되는 것
 *
 * `$t:키` 형태의 문자열. 방문자 화면(React)과 봇 SSR 양쪽이 본문 안의 `$t:` 토큰을
 * 번역으로 치환한다 — 이 플러그인과 무관한 코어 동작이며, 그래서 이 플러그인의 표기는
 * 전부 `[[…]]` 로만 만든다.
 */
class Plugin extends AbstractPlugin
{
    /**
     * 플러그인 메타데이터.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return [
            'author' => 'William Cho',
            'license' => 'MIT',
            'category' => 'content',
            'keywords' => ['wiki', 'board', 'sirsoft-board', 'documents', 'links'],
        ];
    }

    /**
     * 설정 스키마.
     *
     * `wiki_boards` 는 `[{board_id, front_post_id}]` 객체 배열이다. 게시판마다 한 줄이고
     * 대문 글은 비워 둘 수 있다(대문 없이 링크 기능만 쓰는 게시판).
     *
     * 스키마와 기본값을 **둘 다** 선언해야 한다 — 하나라도 빠지면 저장이 조용히 무시된다.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getSettingsSchema(): array
    {
        return [
            WikiBoardSettings::KEY => [
                'type' => 'array',
                'default' => [],
                'label' => [
                    'ko' => '위키로 쓸 게시판',
                    'en' => 'Boards Used as a Wiki',
                ],
                'hint' => [
                    'ko' => '게시판마다 대문 글을 하나 정할 수 있습니다. 대문 글을 비워 두면 문서 링크 기능만 켜집니다.',
                    'en' => 'Each board may have one front page post. Leaving it empty enables document links only.',
                ],
                'required' => false,
            ],
            ManagedBoardList::KEY => [
                'type' => 'array',
                'default' => [],
                'label' => ['ko' => '세트 설치로 마련한 게시판', 'en' => 'Boards Set Up by This Plugin'],
                'required' => false,
            ],
            SetupSettingsPatch::STATE_KEY => [
                'type' => 'array',
                'default' => [],
                'label' => ['ko' => '세트 설치 상태', 'en' => 'Setup State'],
                'required' => false,
            ],
        ];
    }

    /**
     * 설정 기본값 — 위키 게시판 없음, 세트 설치 전.
     *
     * @return array<string, array<int|string, mixed>>
     */
    public function getConfigValues(): array
    {
        return [
            WikiBoardSettings::KEY => [],
            ManagedBoardList::KEY => [],
            SetupSettingsPatch::STATE_KEY => [],
        ];
    }

    /**
     * 훅 리스너.
     *
     * - PostTitleGuardListener: 제목 중복을 422 로 막는다 (방문자·관리자 경로 모두).
     * - PostIndexListener: 생성·수정·복원·삭제에 맞춰 색인을 고치고 자리표시 문서의 봇 캐시를 비운다.
     * - BoardCleanupListener: 게시판이 삭제되면 그 게시판의 색인 줄을 모두 지운다.
     *
     * 액션 훅은 전부 `'sync' => true` 다 — 큐를 기다리면 방금 만든 문서가 몇 초 동안
     * 빨간 링크로 보인다. 우선순위는 30 으로, 코어(10·20)·다른 자작 확장(20·1000)과 겹치지 않는다.
     *
     * @return array<int, class-string>
     */
    public function getHookListeners(): array
    {
        return [
            PostTitleGuardListener::class,
            PostIndexListener::class,
            BoardCleanupListener::class,
        ];
    }

    /**
     * 등록할 미들웨어.
     *
     * 넷 다 첫 줄에서 "위키 게시판인가" 를 보고 아니면 원본 응답을 그대로 돌려준다.
     * 코어 게이트(`ExtensionMiddlewareGate`)가 라우트명을 `targets` 와 대조하므로
     * 다른 API 응답에는 아예 실행되지 않는다 — 관리자 게시물 API·홈 위젯 API,
     * 그리고 **글 저장(POST·PUT)** 포함.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMiddleware(): array
    {
        return [
            [
                'class' => RenderWikiLinksExtension::class,
                'groups' => ['api'],
                'timing' => 'after_core',
                'targets' => [
                    'api.modules.sirsoft-board.boards.posts.show',
                ],
            ],
            [
                'class' => WikiBoardListExtension::class,
                'groups' => ['api'],
                'timing' => 'after_core',
                'targets' => [
                    'api.modules.sirsoft-board.boards.posts.index',
                ],
            ],
            [
                // 세션 파이프라인을 직접 돌려야 해서 코어 전처리보다 앞(before_core)에 둔다.
                'class' => PrefillWikiTitleExtension::class,
                'groups' => ['api'],
                'timing' => 'before_core',
                'targets' => [
                    'api.modules.sirsoft-board.boards.posts.form-data',
                ],
            ],
            [
                // 작성 화면의 **메타**에만 붙는다. `form-data` 가 아니다 — 그쪽 응답은 템플릿이
                // 폼 상태로 통째로 받아 저장 요청에 그대로 실어 보내므로, 칸을 더하면 그 칸이
                // 글 저장 본문에 섞인다.
                'class' => WikiFormMetaExtension::class,
                'groups' => ['api'],
                'timing' => 'after_core',
                'targets' => [
                    'api.modules.sirsoft-board.boards.posts.form-meta',
                ],
            ],
        ];
    }

    /**
     * 플러그인이 관리하는 테이블 — `plugin:uninstall --delete-data` 에서만 코어가 DROP 한다.
     *
     * @return array<int, string>
     */
    public function getDynamicTables(): array
    {
        return [
            'light_wiki_docs',
            'light_wiki_refs',
        ];
    }

    /**
     * 관리자 메뉴.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAdminMenus(): array
    {
        return [
            [
                'name' => ['ko' => '위키 게시판 설정', 'en' => 'Wiki Boards'],
                'slug' => 'g7-light-wiki-settings',
                'url' => '/admin/plugins/g7-light-wiki/settings',
                'icon' => 'fas fa-book',
                'order' => 66,
            ],
        ];
    }

    /**
     * 활성화 — 관리자 메뉴 등록 (비활성화 후 재활성화 경로까지 덮는 멱등 동기화).
     */
    public function activate(): bool
    {
        $helper = app(ExtensionMenuSyncHelper::class);

        foreach ($this->getAdminMenus() as $menuData) {
            $helper->syncMenuRecursive(
                $menuData,
                ExtensionOwnerType::Plugin,
                $this->getIdentifier(),
            );
        }

        return true;
    }

    /**
     * 제거 — 세트 설치로 마련한 게시판이 남아 있으면 거부한다. 판정만 하고 아무것도 지우지 않는다.
     *
     * 코어는 `--delete-data` 일 때 이 메서드보다 **먼저** 이 플러그인의 표를 지운다. 그래서
     * 판정은 모듈의 `boards` 표와 설정 파일만 본다(이 플러그인의 표를 조회하지 않는다).
     */
    public function uninstall(): bool
    {
        $refusal = UninstallGuard::refusal(
            BoardProvisioner::existingCount(app(SetupSettings::class)->managed()),
            static fn (int $count): string => (string) __('g7-light-wiki::messages.setup.uninstall_blocked', ['count' => $count]),
        );

        return $refusal === null ? true : $this->failWith($refusal);
    }

    /**
     * 비활성화 — 관리자 메뉴 제거. 색인 표와 설정 값은 그대로 둔다.
     */
    public function deactivate(): bool
    {
        app(ExtensionMenuSyncHelper::class)->cleanupStaleMenus(
            ExtensionOwnerType::Plugin,
            $this->getIdentifier(),
            currentSlugs: [],
        );

        return true;
    }
}
