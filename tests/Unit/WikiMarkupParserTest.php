<?php

namespace Plugins\G7\Light\Wiki\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Light\Wiki\Support\WikiMarkupParser;

class WikiMarkupParserTest extends TestCase
{
    public function test_문서_링크를_찾는다(): void
    {
        $tokens = WikiMarkupParser::tokenize('앞 [[설치 방법]] 뒤');

        $this->assertCount(1, $tokens);
        $this->assertSame(WikiMarkupParser::KIND_LINK, $tokens[0]['kind']);
        $this->assertSame('설치 방법', $tokens[0]['target']);
        $this->assertNull($tokens[0]['label']);
        $this->assertSame('[[설치 방법]]', $tokens[0]['raw']);
    }

    public function test_표시_글자를_가른다(): void
    {
        $tokens = WikiMarkupParser::tokenize('[[설치 방법|설치하기]]');

        $this->assertSame('설치 방법', $tokens[0]['target']);
        $this->assertSame('설치하기', $tokens[0]['label']);
    }

    public function test_예약_표기는_reserved_로_온다(): void
    {
        $tokens = WikiMarkupParser::tokenize('[[분류:인물]] [[연표:1.0|x]]');

        $this->assertCount(2, $tokens);
        $this->assertSame(WikiMarkupParser::KIND_RESERVED, $tokens[0]['kind']);
        $this->assertSame(WikiMarkupParser::KIND_RESERVED, $tokens[1]['kind']);
    }

    public function test_자리표시를_이름과_인자로_가른다(): void
    {
        $tokens = WikiMarkupParser::tokenize('[[#최근수정]] [[#최근수정|20]] [[#랜덤]] [[#색인]]');

        $this->assertCount(4, $tokens);
        $this->assertSame(WikiMarkupParser::KIND_PLACEHOLDER, $tokens[0]['kind']);
        $this->assertSame(WikiMarkupParser::PLACEHOLDER_RECENT, $tokens[0]['name']);
        $this->assertNull($tokens[0]['argument']);
        $this->assertSame('20', $tokens[1]['argument']);
        $this->assertSame(WikiMarkupParser::PLACEHOLDER_RANDOM, $tokens[2]['name']);
        $this->assertSame(WikiMarkupParser::PLACEHOLDER_INDEX, $tokens[3]['name']);
    }

    public function test_모르는_자리표시는_토큰이_아니다(): void
    {
        $this->assertSame([], WikiMarkupParser::tokenize('[[#없는것]]'));
    }

    public function test_빈_이름과_닫히지_않은_표기는_토큰이_아니다(): void
    {
        $this->assertSame([], WikiMarkupParser::tokenize('[[]]'));
        $this->assertSame([], WikiMarkupParser::tokenize('[[   ]]'));
        $this->assertSame([], WikiMarkupParser::tokenize('[[설치 방법'));
    }

    public function test_대괄호가_더_든_표기는_토큰이_아니다(): void
    {
        $this->assertSame([], WikiMarkupParser::tokenize('[[[설치]]]'));
    }

    public function test_오프셋과_길이로_원문_자리를_짚는다(): void
    {
        $text = '앞 [[문서]] 뒤';
        $token = WikiMarkupParser::tokenize($text)[0];

        $this->assertSame('[[문서]]', substr($text, $token['offset'], $token['length']));
    }

    public function test_여러_표기를_등장_순서대로_돌려준다(): void
    {
        $tokens = WikiMarkupParser::tokenize('[[가]] 과 [[나|다]]');

        $this->assertSame('가', $tokens[0]['target']);
        $this->assertSame('나', $tokens[1]['target']);
        $this->assertTrue($tokens[0]['offset'] < $tokens[1]['offset']);
    }
}
