<?php

declare(strict_types=1);

namespace McpPhp\Mcp;

use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Server;
use Mcp\Server\Builder;
use Mcp\Server\Session\FileSessionStore;
use Psr\Log\LoggerInterface;

/** Construye el servidor MCP (SDK oficial) a partir del ToolRegistry. */
final class ServerFactory
{
    public const NAME = 'mcp-php-crud-server';
    public const VERSION = '1.0.0';

    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ToolInvoker $invoker,
        private readonly LoggerInterface $logger,
        private readonly ?string $sessionDir = null,
    ) {
    }

    public function builder(): Builder
    {
        $builder = Server::builder()
            ->setServerInfo(self::NAME, self::VERSION, 'Servidor MCP de referencia en PHP con herramientas CRUD sobre MariaDB/MySQL.')
            ->setInstructions('Herramientas CRUD sobre la tabla sample_records: list_records, get_record, search_records, create_record, update_record, delete_record. delete_record exige confirm=true.')
            ->setLogger($this->logger)
            ->setReferenceHandler(new ToolAwareReferenceHandler());

        if ($this->sessionDir !== null) {
            if (!is_dir($this->sessionDir)) {
                @mkdir($this->sessionDir, 0700, true);
            }
            $builder->setSession(new FileSessionStore($this->sessionDir));
        }

        foreach ($this->registry->all() as $tool) {
            $a = $tool->annotations();
            $builder->addTool(
                handler: $this->invoker->handlerFor($tool),
                name: $tool->name(),
                title: $tool->title(),
                description: $tool->description(),
                annotations: new ToolAnnotations(
                    title: $tool->title(),
                    readOnlyHint: $a['readOnlyHint'] ?? null,
                    destructiveHint: $a['destructiveHint'] ?? null,
                    idempotentHint: $a['idempotentHint'] ?? null,
                    openWorldHint: $a['openWorldHint'] ?? false,
                ),
                inputSchema: $tool->inputSchema(),
            );
        }

        return $builder;
    }

    public function build(): Server
    {
        return $this->builder()->build();
    }
}
