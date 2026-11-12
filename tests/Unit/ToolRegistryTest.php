<?php

declare(strict_types=1);

namespace McpPhp\Tests\Unit;

use McpPhp\Domain\EntityDefinition;
use McpPhp\Exceptions\ConfigurationException;
use McpPhp\Mcp\ToolRegistry;
use McpPhp\Repositories\RecordRepositoryInterface;
use McpPhp\Services\RecordService;
use McpPhp\Logging\SecurityLogger;
use McpPhp\Tools\CreateRecordTool;
use McpPhp\Tools\DeleteRecordTool;
use McpPhp\Tools\GetRecordTool;
use McpPhp\Tools\ListRecordsTool;
use McpPhp\Tools\SearchRecordsTool;
use McpPhp\Tools\UpdateRecordTool;
use McpPhp\Validators\CriteriaFactory;
use McpPhp\Validators\RecordValidator;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ToolRegistryTest extends TestCase
{
    private function service(): RecordService
    {
        $e = EntityDefinition::sampleRecords();

        return new RecordService($e, $this->createStub(RecordRepositoryInterface::class), new RecordValidator($e), new CriteriaFactory($e), new SecurityLogger(new NullLogger()));
    }

    /** @return list<\McpPhp\Mcp\ToolInterface> */
    private function allTools(): array
    {
        $s = $this->service();

        return [new ListRecordsTool($s), new GetRecordTool($s), new SearchRecordsTool($s), new CreateRecordTool($s), new UpdateRecordTool($s), new DeleteRecordTool($s)];
    }

    public function testRegistersAllSixCrudTools(): void
    {
        $r = new ToolRegistry($this->allTools());
        $this->assertEqualsCanonicalizing(
            ['list_records', 'get_record', 'search_records', 'create_record', 'update_record', 'delete_record'],
            $r->names(),
        );
    }

    public function testDuplicateNamesAreRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        new ToolRegistry([...$this->allTools(), new ListRecordsTool($this->service())]);
    }

    public function testEveryToolHasValidObjectSchemaAndMetadata(): void
    {
        $validator = new Validator();
        foreach ($this->allTools() as $tool) {
            $schema = $tool->inputSchema();
            $this->assertSame('object', $schema['type'], $tool->name());
            $this->assertFalse($schema['additionalProperties'], $tool->name() . ' debe rechazar propiedades desconocidas');
            $this->assertNotSame('', $tool->description());
            $res = $validator->validate(new \stdClass(), json_decode(json_encode($schema)));
            $this->assertNotNull($res, $tool->name()); // el esquema se puede compilar
        }
    }

    public function testDeleteIsMarkedDestructiveAndReadsAreReadOnly(): void
    {
        $r = new ToolRegistry($this->allTools());
        $this->assertTrue($r->get('delete_record')->annotations()['destructiveHint']);
        foreach (['list_records', 'get_record', 'search_records'] as $n) {
            $this->assertTrue($r->get($n)->annotations()['readOnlyHint'], $n);
        }
        $this->assertFalse($r->get('create_record')->annotations()['readOnlyHint']);
    }

    public function testSchemasEnforceRequiredFields(): void
    {
        $validator = new Validator();
        $create = json_decode(json_encode((new CreateRecordTool($this->service()))->inputSchema()));
        $this->assertFalse($validator->validate(json_decode('{"first_name":"A"}'), $create)->isValid());
        $this->assertTrue($validator->validate(json_decode('{"first_name":"A","last_name":"B","email":"a@b.co","title":"t","category":"c"}'), $create)->isValid());
        $this->assertFalse($validator->validate(json_decode('{"first_name":"A","last_name":"B","email":"a@b.co","title":"t","category":"c","evil":1}'), $create)->isValid());

        $update = json_decode(json_encode((new UpdateRecordTool($this->service()))->inputSchema()));
        $this->assertFalse($validator->validate(json_decode('{"id":1}'), $update)->isValid(), 'update sin campos no es valido');
        $this->assertTrue($validator->validate(json_decode('{"id":1,"notes":null}'), $update)->isValid());
    }

    public function testNewToolsCanBeAddedWithoutTouchingTheCore(): void
    {
        $r = new ToolRegistry($this->allTools());
        $extra = new class ($this->service()) extends \McpPhp\Tools\AbstractRecordTool {
            public function name(): string
            {
                return 'custom_tool';
            }

            public function title(): string
            {
                return 'Custom';
            }

            public function description(): string
            {
                return 'Herramienta de ejemplo.';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => new \stdClass()];
            }

            public function requiredPermission(): \McpPhp\Authorization\Permission
            {
                return \McpPhp\Authorization\Permission::RecordsRead;
            }

            public function execute(array $arguments, \McpPhp\Authorization\Principal $principal): array
            {
                return ['ok' => true];
            }
        };
        $r->register($extra);
        $this->assertContains('custom_tool', $r->names());
    }
}
