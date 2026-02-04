<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Util;

use ArnaudMoncondhuy\SynapseBundle\Util\TextUtil;
use PHPUnit\Framework\TestCase;

class TextUtilTest extends TestCase
{
    public function testSanitizeUtf8ValidString(): void
    {
        $input = 'Hello, World! Héllo 世界';

        $this->assertEquals($input, TextUtil::sanitizeUtf8($input));
    }

    public function testSanitizeUtf8EmptyString(): void
    {
        $this->assertEquals('', TextUtil::sanitizeUtf8(''));
    }

    public function testSanitizeUtf8AsciiString(): void
    {
        $this->assertEquals('simple ascii', TextUtil::sanitizeUtf8('simple ascii'));
    }

    public function testSanitizeArrayUtf8SimpleArray(): void
    {
        $input = ['key1' => 'value1', 'key2' => 'value2'];
        $result = TextUtil::sanitizeArrayUtf8($input);

        $this->assertEquals($input, $result);
    }

    public function testSanitizeArrayUtf8PreservesNonStrings(): void
    {
        $input = ['name' => 'test', 'count' => 42, 'active' => true, 'data' => null];
        $result = TextUtil::sanitizeArrayUtf8($input);

        $this->assertEquals('test', $result['name']);
        $this->assertEquals(42, $result['count']);
        $this->assertTrue($result['active']);
        $this->assertNull($result['data']);
    }

    public function testSanitizeArrayUtf8NestedArray(): void
    {
        $input = [
            'level1' => [
                'level2' => [
                    'value' => 'deep',
                ],
            ],
        ];
        $result = TextUtil::sanitizeArrayUtf8($input);

        $this->assertEquals('deep', $result['level1']['level2']['value']);
    }

    public function testSanitizeArrayUtf8NumericKeys(): void
    {
        $input = ['first', 'second', 'third'];
        $result = TextUtil::sanitizeArrayUtf8($input);

        $this->assertEquals(['first', 'second', 'third'], $result);
    }

    public function testSanitizeArrayUtf8EmptyArray(): void
    {
        $this->assertEquals([], TextUtil::sanitizeArrayUtf8([]));
    }

    public function testSanitizeArrayUtf8MixedDepth(): void
    {
        $input = [
            'flat' => 'value',
            'nested' => ['a' => 'b'],
            'number' => 123,
        ];
        $result = TextUtil::sanitizeArrayUtf8($input);

        $this->assertEquals('value', $result['flat']);
        $this->assertEquals(['a' => 'b'], $result['nested']);
        $this->assertEquals(123, $result['number']);
    }
}
