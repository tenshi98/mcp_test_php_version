<?php

declare(strict_types=1);

namespace McpPhp\Mcp;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\RequestContext;
use McpPhp\Authentication\PrincipalResolverInterface;
use McpPhp\Authorization\AuthorizationService;
use McpPhp\Exceptions\McpPhpException;
use Psr\Log\LoggerInterface;

/**
 * Puente entre el SDK MCP y una ToolInterface: resuelve la identidad, comprueba el permiso,
 * ejecuta la herramienta y traduce excepciones a resultados MCP (isError) sin filtrar detalles internos.
 */
final class ToolInvoker
{
    public function __construct(
        private readonly PrincipalResolverInterface $principals,
        private readonly AuthorizationService $authorization,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Devuelve el closure que se registra en el SDK. Esta marcado por su clase de origen
     * (ver ToolAwareReferenceHandler) y recibe el "bag" crudo de argumentos del SDK.
     */
    public function handlerFor(ToolInterface $tool): \Closure
    {
        return function (array $arguments) use ($tool): CallToolResult {
            $session = $arguments['_session'] ?? null;
            $request = $arguments['_request'] ?? null;
            unset($arguments['_session'], $arguments['_request']);

            return $this->invoke($tool, $arguments, $session !== null && $request !== null ? new RequestContext($session, $request) : null);
        };
    }

    /** @param array<string, mixed> $arguments */
    public function invoke(ToolInterface $tool, array $arguments, ?RequestContext $context): CallToolResult
    {
        try {
            $principal = $this->principals->resolve($context?->getAccessToken());
            $this->authorization->assertCan($principal, $tool->requiredPermission(), $tool->name());
            $result = $tool->execute($arguments, $principal);

            $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

            return new CallToolResult([new TextContent($json)], false, $result === [] ? new \stdClass() : $result);
        } catch (McpPhpException $e) {
            if ($e->getPrevious() !== null) {
                $this->logger->error('Error controlado en herramienta', ['tool' => $tool->name(), 'exception' => $e->getPrevious()]);
            }

            return $this->error($e->errorCode(), $e->getMessage(), $e->details());
        } catch (\Throwable $e) {
            // Detalle completo solo al log; el cliente recibe un mensaje generico.
            $this->logger->error('Error inesperado en herramienta', ['tool' => $tool->name(), 'exception' => $e]);

            return $this->error('internal_error', 'Error interno del servidor. Consulte los logs del servidor.');
        }
    }

    /** @param array<string, mixed> $details */
    private function error(string $code, string $message, array $details = []): CallToolResult
    {
        $payload = ['error' => ['code' => $code, 'message' => $message] + ($details === [] ? [] : ['details' => $details])];

        return CallToolResult::error([new TextContent(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE))]);
    }
}
