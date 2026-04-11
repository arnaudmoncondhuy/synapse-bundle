<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Agent\Autonomy;

use ArnaudMoncondhuy\SynapseCore\Agent\Autonomy\PlanResponseSchema;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Agent\Autonomy\PlanResponseSchema
 */
final class PlanResponseSchemaTest extends TestCase
{
    public function testSchemaTopLevelShapeIsCanonical(): void
    {
        $schema = PlanResponseSchema::schema();

        // Format canonique attendu par ResponseFormatNormalizer : wrapper
        // `{type: json_schema, json_schema: {name, schema}}`.
        $this->assertSame('json_schema', $schema['type']);
        $this->assertIsArray($schema['json_schema']);
        $this->assertSame('planner_plan', $schema['json_schema']['name']);
        $this->assertIsArray($schema['json_schema']['schema']);
    }

    public function testInnerSchemaIsObjectWithRequiredFields(): void
    {
        $inner = PlanResponseSchema::schema()['json_schema']['schema'];

        $this->assertSame('object', $inner['type']);
        $this->assertContains('reasoning', $inner['required']);
        $this->assertContains('steps', $inner['required']);
    }

    public function testReasoningIsString(): void
    {
        $props = PlanResponseSchema::schema()['json_schema']['schema']['properties'];

        $this->assertSame('string', $props['reasoning']['type']);
        $this->assertArrayHasKey('description', $props['reasoning']);
    }

    public function testStepsIsArrayOfObjectsWithRequiredStepFields(): void
    {
        $props = PlanResponseSchema::schema()['json_schema']['schema']['properties'];

        $this->assertSame('array', $props['steps']['type']);
        $this->assertIsArray($props['steps']['items']);
        $this->assertSame('object', $props['steps']['items']['type']);

        $stepProps = $props['steps']['items']['properties'];
        $this->assertArrayHasKey('name', $stepProps);
        $this->assertArrayHasKey('agent_name', $stepProps);
        $this->assertArrayHasKey('rationale', $stepProps);
        $this->assertArrayHasKey('input_mapping', $stepProps);
        $this->assertArrayHasKey('output_key', $stepProps);

        $this->assertSame('string', $stepProps['name']['type']);
        $this->assertSame('string', $stepProps['agent_name']['type']);
        $this->assertSame('string', $stepProps['rationale']['type']);

        // Les 3 required sur un step : name, agent_name, rationale
        $this->assertContains('name', $props['steps']['items']['required']);
        $this->assertContains('agent_name', $props['steps']['items']['required']);
        $this->assertContains('rationale', $props['steps']['items']['required']);
    }

    public function testInputMappingIsObject(): void
    {
        $stepProps = PlanResponseSchema::schema()['json_schema']['schema']['properties']['steps']['items']['properties'];

        $this->assertSame('object', $stepProps['input_mapping']['type']);
    }

    public function testOutputsIsObject(): void
    {
        $props = PlanResponseSchema::schema()['json_schema']['schema']['properties'];

        $this->assertSame('object', $props['outputs']['type']);
    }

    public function testSchemaIsSerializableToJson(): void
    {
        $schema = PlanResponseSchema::schema();
        $json = json_encode($schema, \JSON_THROW_ON_ERROR);

        $this->assertIsString($json);
        $this->assertStringContainsString('json_schema', $json);
        $this->assertStringContainsString('planner_plan', $json);

        // Round-trip
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertSame($schema, $decoded);
    }
}
