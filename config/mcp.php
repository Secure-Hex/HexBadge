<?php

declare(strict_types=1);

// Servidor MCP (POST /mcp). Origins permitidos para protegerse de DNS rebinding:
// lista separada por comas en MCP_ALLOWED_ORIGINS; por defecto, la URL propia (APP_URL).
// Las peticiones sin header Origin (clientes CLI/servidor) no se ven afectadas.
return [
    'allowed_origins' => array_values(array_filter(array_map(
        static fn (string $o): string => strtolower(rtrim(trim($o), '/')),
        explode(',', (string) (env('MCP_ALLOWED_ORIGINS') ?: env('APP_URL', 'http://localhost')))
    ))),
];
