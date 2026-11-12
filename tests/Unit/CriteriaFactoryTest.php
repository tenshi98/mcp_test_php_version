<?php

declare(strict_types=1);

namespace McpPhp\Tests\Unit;

use McpPhp\Domain\EntityDefinition;
use McpPhp\Domain\Filter;
use McpPhp\Exceptions\ValidationException;
use McpPhp\Validators\CriteriaFactory;
use PHPUnit\Framework\TestCase;

final class CriteriaFactoryTest extends TestCase
{
    private CriteriaFactory $f;

    protected function setUp(): void
    {
        $this->f = new CriteriaFactory(EntityDefinition::sampleRecords());
    }

    public function testDefaults(): void
    {
        $c = $this->f->fromArguments([], false);
        $this->assertSame(1, $c->page);
        $this->assertSame(20, $c->perPage);
        $this->assertSame('ASC', $c->sortDir);
        $this->assertNull($c->fields);
    }

    public function testSearchBuildsFilters(): void
    {
        $c = $this->f->fromArguments([
            'text' => 'flota', 'category' => 'x', 'status' => 'active', 'is_active' => true,
            'min_price' => 10, 'max_price' => 20.5, 'min_quantity' => 1,
            'date_field' => 'event_date', 'date_from' => '2025-01-01', 'date_to' => '2025-12-31',
            'null_fields' => ['description'], 'sort_by' => 'price', 'sort_dir' => 'desc', 'fields' => ['id', 'price'],
        ], true);

        $this->assertSame('flota', $c->text);
        $this->assertContains('description', $c->textColumns);
        $this->assertSame('DESC', $c->sortDir);
        $this->assertSame(['id', 'price'], $c->fields);
        $ops = array_map(static fn (Filter $f) => $f->column . ' ' . $f->operator, $c->filters);
        foreach (['category =', 'status =', 'is_active =', 'price >=', 'price <=', 'quantity >=', 'event_date >=', 'event_date <=', 'description IS NULL'] as $expected) {
            $this->assertContains($expected, $ops);
        }
    }

    public function testDatetimeColumnsGetFullDayBounds(): void
    {
        $c = $this->f->fromArguments(['date_field' => 'created_at', 'date_from' => '2025-01-01', 'date_to' => '2025-01-31'], true);
        $values = array_map(static fn (Filter $f) => $f->value, $c->filters);
        $this->assertSame(['2025-01-01 00:00:00', '2025-01-31 23:59:59'], $values);
    }

    public function testListIgnoresSearchOnlyFilters(): void
    {
        $this->assertSame([], $this->f->fromArguments(['category' => 'x'], false)->filters);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badArgs(): iterable
    {
        yield 'sort_by con SQL' => [['sort_by' => 'id; DROP TABLE users']];
        yield 'sort_dir invalido' => [['sort_dir' => 'sideways']];
        yield 'campo inexistente' => [['fields' => ['id', 'password']]];
        yield 'fields vacio' => [['fields' => []]];
        yield 'page cero' => [['page' => 0]];
        yield 'per_page excesivo' => [['per_page' => 101]];
        yield 'fecha invalida' => [['date_from' => '2025-13-40']];
        yield 'date_field invalido' => [['date_field' => 'title', 'date_from' => '2025-01-01']];
        yield 'status invalido' => [['status' => 'x']];
        yield 'is_active no booleano' => [['is_active' => 'yes']];
        yield 'min_price texto' => [['min_price' => '10']];
        yield 'null_fields no opcional' => [['null_fields' => ['email']]];
    }

    /** @param array<string, mixed> $args */
    #[\PHPUnit\Framework\Attributes\DataProvider('badArgs')]
    public function testRejectsBadArguments(array $args): void
    {
        $this->expectException(ValidationException::class);
        $this->f->fromArguments($args, true);
    }
}
