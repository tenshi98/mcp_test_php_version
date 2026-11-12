<?php

declare(strict_types=1);

/*
 * Registro de herramientas MCP.
 *
 * Para agregar una herramienta nueva:
 *   1. Cree una clase que implemente McpPhp\Mcp\ToolInterface (ver src/Tools/).
 *   2. Agreguela a esta lista como una funcion que reciba la Application y devuelva la herramienta.
 * No hace falta modificar el nucleo del servidor.
 */

use McpPhp\Core\Application;
use McpPhp\Tools\CreateRecordTool;
use McpPhp\Tools\DeleteRecordTool;
use McpPhp\Tools\GetRecordTool;
use McpPhp\Tools\ListRecordsTool;
use McpPhp\Tools\SearchRecordsTool;
use McpPhp\Tools\UpdateRecordTool;

return [
    static fn (Application $app) => new ListRecordsTool($app->recordService()),
    static fn (Application $app) => new GetRecordTool($app->recordService()),
    static fn (Application $app) => new SearchRecordsTool($app->recordService()),
    static fn (Application $app) => new CreateRecordTool($app->recordService()),
    static fn (Application $app) => new UpdateRecordTool($app->recordService()),
    static fn (Application $app) => new DeleteRecordTool($app->recordService()),
];
