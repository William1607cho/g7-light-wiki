<?php

namespace Plugins\G7\Light\Wiki\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Light\Wiki\Support\Doc\ListMode;
use Plugins\G7\Light\Wiki\Support\Doc\WikiBoardFlag;

/**
 * 응답에 실리는 `board.wiki` 칸 — 값 조립과 얹는 자리.
 *
 * "이 게시판이 위키인가" 판정은 미들웨어 첫 줄이 하고 이 클래스는 하지 않으므로, 여기서
 * 시험하는 것은 **모양**이다. 실제 응답에 실리는지는 스테이징 측정에서 잰다.
 */
class WikiBoardFlagTest extends TestCase
{
    public function test_칸_이름은_wiki다(): void
    {
        $this->assertSame('wiki', WikiBoardFlag::KEY);
    }

    public function test_게시판_정보에_칸을_얹는다(): void
    {
        $payload = ['board' => ['id' => 8, 'slug' => 'test-wiki'], 'data' => []];

        $out = WikiBoardFlag::withWiki($payload, WikiBoardFlag::listValue(ListMode::RECENT));

        $this->assertSame(['list_mode' => 'recent'], $out['board']['wiki']);
    }

    public function test_원래_있던_게시판_칸은_건드리지_않는다(): void
    {
        $payload = ['board' => ['id' => 8, 'slug' => 'test-wiki'], 'data' => [1, 2]];

        $out = WikiBoardFlag::withWiki($payload, WikiBoardFlag::formMetaValue());

        $this->assertSame(8, $out['board']['id']);
        $this->assertSame('test-wiki', $out['board']['slug']);
        $this->assertSame([1, 2], $out['data']);
    }

    public function test_게시판_정보가_없으면_만들지_않는다(): void
    {
        // 모양을 모르는 응답에 칸을 새로 만들면 소비자가 없는 게시판을 본다.
        $this->assertSame(['data' => []], WikiBoardFlag::withWiki(['data' => []], []));
        $this->assertSame(['board' => 'x'], WikiBoardFlag::withWiki(['board' => 'x'], []));
    }

    public function test_목록_값은_실제로_적용된_모드다(): void
    {
        // 주소의 sort_by 가 아니다 — 검색어가 있으면 서버가 모드를 무시하므로
        // 그때는 '모드 없음' 이 실린다.
        $this->assertSame(['list_mode' => 'none'], WikiBoardFlag::listValue(ListMode::NONE));
        $this->assertSame(['list_mode' => 'recent'], WikiBoardFlag::listValue(ListMode::RECENT));
        $this->assertSame(['list_mode' => 'random'], WikiBoardFlag::listValue(ListMode::RANDOM));
    }

    public function test_상세_값은_대문_글_id다(): void
    {
        $this->assertSame(['front_post_id' => 38], WikiBoardFlag::pageValue(38));
    }

    public function test_대문이_없으면_상세_값은_null이다(): void
    {
        // 링크 기능만 쓰는 위키 게시판. 화면은 이때 목록 버튼을 그대로 둔다.
        $this->assertSame(['front_post_id' => null], WikiBoardFlag::pageValue(null));
    }

    public function test_폼_메타_값은_빈_객체다(): void
    {
        $value = WikiBoardFlag::formMetaValue();

        $this->assertInstanceOf(\stdClass::class, $value);
        // 빈 PHP 배열이면 JSON 에서 `[]` 가 된다. 세 응답의 값이 모두 객체여야 한다.
        $this->assertSame('{}', json_encode($value));
    }

    public function test_세_값이_모두_JSON_객체로_나간다(): void
    {
        foreach ([
            WikiBoardFlag::listValue(ListMode::NONE),
            WikiBoardFlag::pageValue(null),
            WikiBoardFlag::formMetaValue(),
        ] as $value) {
            $this->assertStringStartsWith('{', (string) json_encode($value));
        }
    }
}
