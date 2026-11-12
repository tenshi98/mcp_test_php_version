<?php

declare(strict_types=1);

namespace McpPhp\Mcp;

use Mcp\Server\Capability\Registry\ElementReference;
use Mcp\Server\Capability\Registry\ReferenceHandler;
use Mcp\Server\Capability\Registry\ReferenceHandlerInterface;

/**
 * Decorador del ReferenceHandler del SDK: los closures creados por ToolInvoker reciben
 * el bag de argumentos completo (incluye _session/_request para leer el AccessToken);
 * todo lo demas se delega al manejador original.
 */
final class ToolAwareReferenceHandler implements ReferenceHandlerInterface
{
    public function __construct(private readonly ReferenceHandlerInterface $inner = new ReferenceHandler())
    {
    }

    public function handle(ElementReference $reference, array $arguments): mixed
    {
        $handler = $reference->handler;
        if ($handler instanceof \Closure && (new \ReflectionFunction($handler))->getClosureScopeClass()?->getName() === ToolInvoker::class) {
            return $handler($arguments);
        }

        return $this->inner->handle($reference, $arguments);
    }
}
