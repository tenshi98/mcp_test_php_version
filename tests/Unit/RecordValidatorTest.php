<?php

declare(strict_types=1);

namespace McpPhp\Tests\Unit;

use McpPhp\Domain\EntityDefinition;
use McpPhp\Exceptions\ValidationException;
use McpPhp\Validators\RecordValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecordValidatorTest extends TestCase
{
    private RecordValidator $v;

    protected function setUp(): void
    {
        $this->v = new RecordValidator(EntityDefinition::sampleRecords());
    }

    /** @return array<string, mixed> */
    private function valid(): array
    {
        return ['first_name' => 'Ana', 'last_name' => 'Perez', 'email' => 'Ana@Example.com', 'title' => 'T', 'category' => 'c'];
    }

    public function testCreateAcceptsMinimalRecordAndNormalizesEmail(): void
    {
        $clean = $this->v->validateForCreate($this->valid());
        $this->assertSame('ana@example.com', $clean['email']);
        $this->assertArrayNotHasKey('quantity', $clean, 'los campos omitidos usan el valor por defecto de la BD');
    }

    public function testCreateRequiresMandatoryFields(): void
    {
        try {
            $this->v->validateForCreate(['first_name' => 'Ana']);
            $this->fail('debio fallar');
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(['last_name', 'email', 'title', 'category'], array_keys($e->errors()));
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidValues(): iterable
    {
        yield 'email mal formado' => ['email', 'no-es-correo'];
        yield 'email no string' => ['email', 123];
        yield 'fecha imposible' => ['birth_date', '2025-02-30'];
        yield 'fecha otro formato' => ['birth_date', '30/01/2025'];
        yield 'entero como texto' => ['quantity', 'abc'];
        yield 'entero decimal' => ['quantity', 1.5];
        yield 'decimal con 3 decimales' => ['price', 1.234];
        yield 'decimal negativo' => ['price', -1];
        yield 'decimal fuera de rango' => ['rating', 100];
        yield 'booleano invalido' => ['is_active', 'quizas'];
        yield 'enum invalido' => ['status', 'borrado'];
        yield 'texto demasiado largo' => ['first_name', str_repeat('a', 81)];
        yield 'texto no string' => ['title', ['x']];
        yield 'caracter de control' => ['title', "mal\x00texto"];
        yield 'NULL en campo obligatorio' => ['title', null];
        yield 'campo desconocido' => ['hack', 'x'];
        yield 'campo de solo lectura (id)' => ['id', 5];
        yield 'campo de solo lectura (created_at)' => ['created_at', '2020-01-01 00:00:00'];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValues(string $field, mixed $value): void
    {
        $this->expectException(ValidationException::class);
        $this->v->validateForCreate([$field => $value] + $this->valid());
    }

    public function testAcceptsOptionalNullsAndTypedValues(): void
    {
        $clean = $this->v->validateForCreate($this->valid() + [
            'birth_date' => null, 'price' => '19.99', 'quantity' => '5', 'is_active' => 'false', 'status' => 'active', 'rating' => 4.5,
        ]);
        $this->assertNull($clean['birth_date']);
        $this->assertSame(19.99, $clean['price']);
        $this->assertSame(5, $clean['quantity']);
        $this->assertFalse($clean['is_active']);
    }

    public function testUpdateIsPartialAndRequiresAtLeastOneField(): void
    {
        $this->assertSame(['quantity' => 3], $this->v->validateForUpdate(['quantity' => 3]));
        $this->expectException(ValidationException::class);
        $this->v->validateForUpdate([]);
    }

    public function testUpdateCanSetOptionalFieldToNull(): void
    {
        $this->assertSame(['notes' => null], $this->v->validateForUpdate(['notes' => null]));
    }

    #[DataProvider('ids')]
    public function testValidateId(mixed $input, ?int $expected): void
    {
        if ($expected === null) {
            $this->expectException(ValidationException::class);
        }
        $this->assertSame($expected, $this->v->validateId($input));
    }

    /** @return iterable<string, array{mixed, ?int}> */
    public static function ids(): iterable
    {
        yield 'int' => [7, 7];
        yield 'string numerica' => ['12', 12];
        yield 'cero' => [0, null];
        yield 'negativo' => [-3, null];
        yield 'texto' => ['1 OR 1=1', null];
        yield 'float' => [1.5, null];
        yield 'null' => [null, null];
    }
}
