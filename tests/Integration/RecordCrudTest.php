<?php

declare(strict_types=1);

namespace McpPhp\Tests\Integration;

use McpPhp\Exceptions\ConflictException;
use McpPhp\Exceptions\NotFoundException;
use McpPhp\Exceptions\ValidationException;
use McpPhp\Services\RecordService;
use McpPhp\Tests\Support\DatabaseTestCase;

final class RecordCrudTest extends DatabaseTestCase
{
    private RecordService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = $this->app->recordService();
    }

    /** @return array<string, mixed> */
    private function newRecord(array $over = []): array
    {
        return $over + ['first_name' => 'Zoe', 'last_name' => 'Nueva', 'email' => 'zoe.nueva@example.test', 'title' => 'Registro nuevo', 'category' => 'pruebas'];
    }

    // ---- READ ------------------------------------------------------------

    public function testListPaginates(): void
    {
        $p1 = $this->svc->list(['per_page' => 10]);
        $this->assertCount(10, $p1['records']);
        $this->assertSame(30, $p1['pagination']['total']);
        $this->assertSame(3, $p1['pagination']['total_pages']);
        $this->assertTrue($p1['pagination']['has_next']);

        $p3 = $this->svc->list(['per_page' => 10, 'page' => 3]);
        $this->assertFalse($p3['pagination']['has_next']);
        $this->assertSame(21, $p3['records'][0]['id']);

        $this->assertSame([], $this->svc->list(['per_page' => 10, 'page' => 4])['records']);
    }

    public function testListSortsAndSelectsFields(): void
    {
        $r = $this->svc->list(['sort_by' => 'price', 'sort_dir' => 'desc', 'per_page' => 2, 'fields' => ['id', 'price']]);
        $this->assertSame(['id', 'price'], array_keys($r['records'][0]));
        $this->assertSame(78000000.0, $r['records'][0]['price']);
        $this->assertGreaterThanOrEqual($r['records'][1]['price'], $r['records'][0]['price']);
    }

    public function testRecordsAreReturnedWithNativeTypes(): void
    {
        $r = $this->svc->get(1);
        $this->assertIsInt($r['id']);
        $this->assertIsInt($r['quantity']);
        $this->assertIsFloat($r['price']);
        $this->assertIsBool($r['is_active']);
        $this->assertIsString($r['birth_date']);
    }

    public function testGetByIdAndMissing(): void
    {
        $this->assertSame('Camila', $this->svc->get(1)['first_name']);
        $this->assertSame(['id' => 1, 'email' => 'camila.rojas@example.test'], $this->svc->get(1, ['id', 'email']));
        $this->expectException(NotFoundException::class);
        $this->svc->get(9999);
    }

    public function testSearchByTextAndName(): void
    {
        $ids = array_column($this->svc->search(['text' => 'FLOTA', 'fields' => ['id']])['records'], 'id');
        $this->assertContains(2, $ids, 'titulo "Mantencion de flota" (sin distinguir mayusculas)');
        $this->assertContains(10, $ids, 'descripcion "...toda la flota..."');

        $this->assertSame(1, $this->svc->search(['name' => 'camila rojas'])['pagination']['total']);
        $this->assertSame(1, $this->svc->search(['name' => 'ROJAS'])['pagination']['total']);
        $this->assertSame(0, $this->svc->search(['name' => 'inexistente'])['pagination']['total']);
        $this->assertSame(1, $this->svc->search(['text' => 'example.test', 'category' => 'calidad', 'status' => 'draft'])['pagination']['total']);
    }

    public function testSearchByCategoryStatusAndBoolean(): void
    {
        foreach ($this->svc->search(['category' => 'tecnologia'])['records'] as $r) {
            $this->assertSame('tecnologia', $r['category']);
        }
        foreach ($this->svc->search(['status' => 'archived'])['records'] as $r) {
            $this->assertSame('archived', $r['status']);
        }
        $inactive = $this->svc->search(['is_active' => false, 'per_page' => 100]);
        $this->assertGreaterThan(0, $inactive['pagination']['total']);
        foreach ($inactive['records'] as $r) {
            $this->assertFalse($r['is_active']);
        }
    }

    public function testSearchByNumericRanges(): void
    {
        foreach ($this->svc->search(['min_price' => 1000000, 'max_price' => 5000000, 'per_page' => 100])['records'] as $r) {
            $this->assertGreaterThanOrEqual(1000000, $r['price']);
            $this->assertLessThanOrEqual(5000000, $r['price']);
        }
        foreach ($this->svc->search(['min_quantity' => 100, 'per_page' => 100])['records'] as $r) {
            $this->assertGreaterThanOrEqual(100, $r['quantity']);
        }
        $this->assertSame(0, $this->svc->search(['min_price' => 1, 'max_price' => 0])['pagination']['total']);
    }

    public function testSearchByDateRange(): void
    {
        $r = $this->svc->search(['date_field' => 'event_date', 'date_from' => '2025-03-01', 'date_to' => '2025-04-30', 'fields' => ['id', 'event_date']]);
        $this->assertSame(4, $r['pagination']['total']);
        foreach ($r['records'] as $rec) {
            $this->assertGreaterThanOrEqual('2025-03-01', $rec['event_date']);
            $this->assertLessThanOrEqual('2025-04-30', $rec['event_date']);
        }
        $byCreated = $this->svc->search(['date_field' => 'created_at', 'date_from' => '2025-01-05', 'date_to' => '2025-01-05']);
        $this->assertSame(1, $byCreated['pagination']['total'], 'created_at cubre el dia completo');
    }

    public function testSearchNullHandling(): void
    {
        $nulls = $this->svc->search(['null_fields' => ['description'], 'fields' => ['id', 'description']]);
        $this->assertGreaterThan(0, $nulls['pagination']['total']);
        foreach ($nulls['records'] as $r) {
            $this->assertNull($r['description']);
        }
        foreach ($this->svc->search(['not_null_fields' => ['price'], 'per_page' => 100, 'fields' => ['price']])['records'] as $r) {
            $this->assertNotNull($r['price']);
        }
    }

    public function testCombinedFiltersUseAnd(): void
    {
        $r = $this->svc->search(['category' => 'flota', 'status' => 'active', 'min_price' => 100000, 'sort_by' => 'price', 'sort_dir' => 'desc']);
        $this->assertGreaterThan(0, $r['pagination']['total']);
        foreach ($r['records'] as $rec) {
            $this->assertSame('flota', $rec['category']);
            $this->assertSame('active', $rec['status']);
        }
    }

    // ---- Inyeccion SQL -----------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function injectionStrings(): iterable
    {
        yield 'comilla + OR' => ["' OR '1'='1"];
        yield 'comentario' => ["x' OR 1=1 -- "];
        yield 'union' => ["' UNION SELECT token_hash,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18 FROM tokens -- "];
        yield 'drop' => ["'; DROP TABLE sample_records; -- "];
        yield 'stacked' => ["1; DELETE FROM allowed_users"];
        yield 'backtick' => ['`id`, (SELECT 1)'];
        yield 'like wildcard %' => ['%'];
        yield 'like wildcard _' => ['_'];
        yield 'backslash' => ['\\'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('injectionStrings')]
    public function testInjectionStringsAreTreatedAsLiteralText(string $payload): void
    {
        foreach (['text', 'name', 'category'] as $field) {
            try {
                $r = $this->svc->search([$field => $payload]);
                $this->assertSame(0, $r['pagination']['total'], "$field con '$payload' no debe coincidir con nada");
            } catch (ValidationException) {
                // category admite maximo 50 caracteres: rechazar la entrada tambien es una defensa valida
                $this->assertSame('category', $field);
            }
        }
        $this->assertSame(30, $this->svc->list([])['pagination']['total'], 'la tabla sigue intacta');
        $this->assertCount(4, $this->app->userRepository()->all());
    }

    public function testInjectionInIdentifiersIsRejected(): void
    {
        foreach ([['sort_by' => 'id; DROP TABLE sample_records'], ['fields' => ['id', '1) OR (1=1']], ['sort_dir' => 'ASC; DROP TABLE x']] as $args) {
            try {
                $this->svc->list($args);
                $this->fail('debio rechazarse');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['1 OR 1=1', "1'; DROP TABLE sample_records;--", '1.5', ''] as $id) {
            try {
                $this->svc->get($id);
                $this->fail('debio rechazarse');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(30, $this->svc->list([])['pagination']['total']);
    }

    public function testMaliciousTextCanBeStoredLiterally(): void
    {
        $payload = "Robert'); DROP TABLE sample_records;-- <script>alert(1)</script>";
        $rec = $this->svc->create($this->newRecord(['title' => $payload, 'notes' => $payload]));
        $this->assertSame($payload, $rec['title']);
        $this->assertSame(31, $this->svc->list([])['pagination']['total']);
        $this->assertSame($payload, $this->svc->get($rec['id'])['notes']);
    }

    // ---- CREATE --------------------------------------------------------------

    public function testCreateAppliesDefaultsAndDates(): void
    {
        $rec = $this->svc->create($this->newRecord());
        $this->assertSame(31, $rec['id']);
        $this->assertSame(0, $rec['quantity']);
        $this->assertTrue($rec['is_active']);
        $this->assertSame('draft', $rec['status']);
        $this->assertNull($rec['price']);
        $this->assertNotNull($rec['created_at']);
        $this->assertNotNull($rec['updated_at']);
        $this->assertSame('zoe.nueva@example.test', $rec['email']);
    }

    public function testCreateWithAllFields(): void
    {
        $rec = $this->svc->create($this->newRecord([
            'birth_date' => '2000-02-29', 'event_date' => '2026-06-01', 'short_text' => 'corto', 'description' => str_repeat('largo ', 500) . 'fin',
            'quantity' => 42, 'price' => 99.99, 'rating' => 4.25, 'is_active' => false, 'status' => 'archived', 'notes' => null,
        ]));
        $this->assertSame('2000-02-29', $rec['birth_date']);
        $this->assertSame(99.99, $rec['price']);
        $this->assertFalse($rec['is_active']);
        $this->assertSame(3003, strlen($rec['description']));
    }

    public function testCreateRejectsInvalidAndDuplicateData(): void
    {
        try {
            $this->svc->create(['first_name' => 'x']);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }
        $this->svc->create($this->newRecord());
        $this->expectException(ConflictException::class);
        $this->svc->create($this->newRecord(['first_name' => 'Otro']));
    }

    // ---- UPDATE --------------------------------------------------------------

    public function testPartialUpdateKeepsOtherFields(): void
    {
        $before = $this->svc->get(1);
        $after = $this->svc->update(1, ['quantity' => 999, 'status' => 'archived']);
        $this->assertSame(999, $after['quantity']);
        $this->assertSame('archived', $after['status']);
        foreach (['first_name', 'last_name', 'email', 'title', 'price', 'description', 'birth_date', 'created_at'] as $kept) {
            $this->assertSame($before[$kept], $after[$kept], "$kept no debe cambiar");
        }
    }

    public function testUpdateRefreshesUpdatedAt(): void
    {
        $this->app->connection()->run("UPDATE sample_records SET updated_at = '2020-01-01 00:00:00' WHERE id = 1");
        $after = $this->svc->update(1, ['title' => 'Cambiado']);
        $this->assertGreaterThan('2020-01-01 00:00:00', $after['updated_at']);
    }

    public function testUpdateCanSetOptionalFieldToNull(): void
    {
        $this->assertNotNull($this->svc->get(1)['price']);
        $this->assertNull($this->svc->update(1, ['price' => null])['price']);
    }

    public function testUpdateWithIdenticalValuesStillSucceeds(): void
    {
        $r = $this->svc->get(1);
        $this->assertSame($r['title'], $this->svc->update(1, ['title' => $r['title']])['title']);
    }

    public function testUpdateValidationAndMissingRecord(): void
    {
        foreach ([['id' => 5], ['created_at' => '2020-01-01 00:00:00'], ['email' => 'mal'], ['nada' => 1], []] as $bad) {
            try {
                $this->svc->update(1, $bad);
                $this->fail('debio rechazarse');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            $this->svc->update(9999, ['title' => 'x']);
            $this->fail();
        } catch (NotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testUpdateToExistingEmailConflicts(): void
    {
        $this->expectException(ConflictException::class);
        $this->svc->update(1, ['email' => 'matias.gonzalez@example.test']);
    }

    // ---- DELETE --------------------------------------------------------------

    public function testDeleteWithoutConfirmDoesNothing(): void
    {
        $r = $this->svc->delete(5, false);
        $this->assertFalse($r['deleted']);
        $this->assertTrue($r['confirmation_required']);
        $this->assertSame(5, $r['record']['id']);
        $this->assertSame(30, $this->svc->list([])['pagination']['total']);
    }

    public function testDeleteWithConfirmRemovesRecord(): void
    {
        $r = $this->svc->delete(5, true, $this->userId('admin@example.com'));
        $this->assertTrue($r['deleted']);
        $this->assertSame(29, $this->svc->list([])['pagination']['total']);
        $this->assertCount(1, $this->events('record.deleted'));
        $this->expectException(NotFoundException::class);
        $this->svc->get(5);
    }

    public function testDeleteMissingRecord(): void
    {
        $this->expectException(NotFoundException::class);
        $this->svc->delete(9999, true);
    }

    public function testWritesAreAudited(): void
    {
        $uid = $this->userId('editor@example.com');
        $rec = $this->svc->create($this->newRecord(), $uid);
        $this->svc->update($rec['id'], ['title' => 'x'], $uid);
        $this->assertCount(1, $this->events('record.created'));
        $this->assertCount(1, $this->events('record.updated'));
        $this->assertSame($uid, (int) $this->events('record.created')[0]['user_id']);
    }
}
