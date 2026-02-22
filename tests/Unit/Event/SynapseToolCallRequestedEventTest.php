<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Event;

use ArnaudMoncondhuy\SynapseBundle\Core\Event\SynapseToolCallRequestedEvent;
use PHPUnit\Framework\TestCase;

class SynapseToolCallRequestedEventTest extends TestCase
{
    /**
     * Test création avec un seul tool call.
     */
    public function testCreationWithSingleToolCall(): void
    {
        // Arrange
        $toolCalls = [
            ['name' => 'get_weather', 'args' => ['location' => 'Paris']],
        ];

        // Act
        $event = new SynapseToolCallRequestedEvent($toolCalls);

        // Assert
        $this->assertEquals($toolCalls, $event->getToolCalls());
        $this->assertCount(1, $event->getToolCalls());
    }

    /**
     * Test création avec plusieurs tool calls.
     */
    public function testCreationWithMultipleToolCalls(): void
    {
        // Arrange
        $toolCalls = [
            ['name' => 'search', 'args' => ['query' => 'Python']],
            ['name' => 'calculate', 'args' => ['expression' => '2 + 2']],
            ['name' => 'translate', 'args' => ['text' => 'Hello', 'lang' => 'fr']],
        ];

        // Act
        $event = new SynapseToolCallRequestedEvent($toolCalls);

        // Assert
        $this->assertEquals($toolCalls, $event->getToolCalls());
        $this->assertCount(3, $event->getToolCalls());
    }

    /**
     * Test setToolResult et getResult.
     */
    public function testSetAndGetToolResult(): void
    {
        // Arrange
        $toolCalls = [
            ['name' => 'get_weather', 'args' => []],
        ];
        $event = new SynapseToolCallRequestedEvent($toolCalls);

        // Act
        $event->setToolResult('get_weather', ['temp' => 20, 'condition' => 'sunny']);

        // Assert
        $this->assertEquals(['temp' => 20, 'condition' => 'sunny'], $event->getResult('get_weather'));
    }

    /**
     * Test setToolResult retourne $this.
     */
    public function testSetToolResultReturnsSelf(): void
    {
        // Arrange
        $event = new SynapseToolCallRequestedEvent([['name' => 'test', 'args' => []]]);

        // Act
        $result = $event->setToolResult('test', 'value');

        // Assert
        $this->assertSame($event, $result);
    }

    /**
     * Test getResults retourne tous les résultats.
     */
    public function testGetResultsReturnsAllResults(): void
    {
        // Arrange
        $toolCalls = [
            ['name' => 'func1', 'args' => []],
            ['name' => 'func2', 'args' => []],
        ];
        $event = new SynapseToolCallRequestedEvent($toolCalls);

        // Act
        $event->setToolResult('func1', 'result1')
            ->setToolResult('func2', 'result2');

        // Assert
        $results = $event->getResults();
        $this->assertCount(2, $results);
        $this->assertEquals('result1', $results['func1']);
        $this->assertEquals('result2', $results['func2']);
    }

    /**
     * Test getResult retourne null pour tool inexistant.
     */
    public function testGetResultReturnsNullForNonExistent(): void
    {
        // Arrange
        $event = new SynapseToolCallRequestedEvent([['name' => 'existing', 'args' => []]]);

        // Act
        $result = $event->getResult('nonexistent');

        // Assert
        $this->assertNull($result);
    }

    /**
     * Test areAllResultsRegistered avec tous les résultats.
     */
    public function testAreAllResultsRegisteredTrue(): void
    {
        // Arrange
        $toolCalls = [
            ['name' => 'tool1', 'args' => []],
            ['name' => 'tool2', 'args' => []],
        ];
        $event = new SynapseToolCallRequestedEvent($toolCalls);

        // Act
        $event->setToolResult('tool1', 'result1')
            ->setToolResult('tool2', 'result2');

        // Assert
        $this->assertTrue($event->areAllResultsRegistered());
    }

    /**
     * Test areAllResultsRegistered avec résultats partiels.
     */
    public function testAreAllResultsRegisteredFalsePartial(): void
    {
        // Arrange
        $toolCalls = [
            ['name' => 'tool1', 'args' => []],
            ['name' => 'tool2', 'args' => []],
        ];
        $event = new SynapseToolCallRequestedEvent($toolCalls);

        // Act
        $event->setToolResult('tool1', 'result1');

        // Assert
        $this->assertFalse($event->areAllResultsRegistered());
    }

    /**
     * Test areAllResultsRegistered avec aucun résultat.
     */
    public function testAreAllResultsRegisteredFalseEmpty(): void
    {
        // Arrange
        $toolCalls = [
            ['name' => 'tool1', 'args' => []],
        ];
        $event = new SynapseToolCallRequestedEvent($toolCalls);

        // Act & Assert
        $this->assertFalse($event->areAllResultsRegistered());
    }

    /**
     * Test avec tool call vide.
     */
    public function testWithEmptyToolCallsList(): void
    {
        // Act
        $event = new SynapseToolCallRequestedEvent([]);

        // Assert
        $this->assertEmpty($event->getToolCalls());
        $this->assertEmpty($event->getResults());
        $this->assertTrue($event->areAllResultsRegistered());  // 0 calls = all registered
    }

    /**
     * Test fluent chainning de setToolResult.
     */
    public function testFluentSetToolResults(): void
    {
        // Arrange
        $toolCalls = [
            ['name' => 'get_time', 'args' => []],
            ['name' => 'get_date', 'args' => []],
            ['name' => 'get_timezone', 'args' => []],
        ];
        $event = new SynapseToolCallRequestedEvent($toolCalls);

        // Act
        $event->setToolResult('get_time', '14:30')
            ->setToolResult('get_date', '2026-02-22')
            ->setToolResult('get_timezone', 'Europe/Paris');

        // Assert
        $this->assertTrue($event->areAllResultsRegistered());
        $this->assertEquals('14:30', $event->getResult('get_time'));
        $this->assertEquals('2026-02-22', $event->getResult('get_date'));
    }

    /**
     * Test avec résultats de types différents.
     */
    public function testWithDifferentResultTypes(): void
    {
        // Arrange
        $toolCalls = [
            ['name' => 'get_string', 'args' => []],
            ['name' => 'get_number', 'args' => []],
            ['name' => 'get_array', 'args' => []],
            ['name' => 'get_bool', 'args' => []],
        ];
        $event = new SynapseToolCallRequestedEvent($toolCalls);

        // Act
        $event->setToolResult('get_string', 'text')
            ->setToolResult('get_number', 42)
            ->setToolResult('get_array', ['a', 'b', 'c'])
            ->setToolResult('get_bool', true);

        // Assert
        $this->assertIsString($event->getResult('get_string'));
        $this->assertIsInt($event->getResult('get_number'));
        $this->assertIsArray($event->getResult('get_array'));
        $this->assertIsBool($event->getResult('get_bool'));
    }

    /**
     * Test avec arguments complexes.
     */
    public function testWithComplexArguments(): void
    {
        // Arrange
        $toolCalls = [
            [
                'name' => 'search_database',
                'args' => [
                    'query' => 'SELECT * FROM users',
                    'params' => ['id' => 123, 'active' => true],
                    'options' => ['limit' => 10, 'offset' => 0],
                ],
            ],
        ];

        // Act
        $event = new SynapseToolCallRequestedEvent($toolCalls);

        // Assert
        $call = $event->getToolCalls()[0];
        $this->assertEquals('search_database', $call['name']);
        $this->assertEquals('SELECT * FROM users', $call['args']['query']);
        $this->assertEquals(123, $call['args']['params']['id']);
    }

    /**
     * Test remplacer un résultat.
     */
    public function testReplacingToolResult(): void
    {
        // Arrange
        $toolCalls = [['name' => 'fetch', 'args' => []]];
        $event = new SynapseToolCallRequestedEvent($toolCalls);

        // Act
        $event->setToolResult('fetch', 'first_result');
        $event->setToolResult('fetch', 'second_result');

        // Assert
        $this->assertEquals('second_result', $event->getResult('fetch'));
    }

    /**
     * Test avec erreur comme résultat.
     */
    public function testWithErrorResult(): void
    {
        // Arrange
        $toolCalls = [['name' => 'risky_operation', 'args' => []]];
        $event = new SynapseToolCallRequestedEvent($toolCalls);

        // Act
        $event->setToolResult('risky_operation', ['error' => 'Network timeout']);

        // Assert
        $result = $event->getResult('risky_operation');
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Network timeout', $result['error']);
    }

    /**
     * Test getToolCalls retourne array exact.
     */
    public function testGetToolCallsReturnsExactArray(): void
    {
        // Arrange
        $toolCalls = [
            ['name' => 'tool1', 'args' => ['a' => 1]],
            ['name' => 'tool2', 'args' => ['b' => 2]],
        ];

        // Act
        $event = new SynapseToolCallRequestedEvent($toolCalls);

        // Assert
        $retrieved = $event->getToolCalls();
        $this->assertEquals($toolCalls, $retrieved);
    }
}
