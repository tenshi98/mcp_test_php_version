<?php

declare(strict_types=1);

namespace McpPhp\Logging;

use McpPhp\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Registra eventos de seguridad en el log (archivo/stderr) y en la tabla security_events.
 * Nunca lanza excepciones: un fallo al registrar no debe romper la operacion.
 * NUNCA debe recibir tokens, secretos ni contrasenas en $context.
 */
class SecurityLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?Connection $db = null,
        private readonly ?string $ip = null,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function info(string $event, ?int $userId = null, array $context = []): void
    {
        $this->record('info', $event, $userId, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $event, ?int $userId = null, array $context = []): void
    {
        $this->record('warning', $event, $userId, $context);
    }

    /** @param array<string, mixed> $context */
    public function critical(string $event, ?int $userId = null, array $context = []): void
    {
        $this->record('critical', $event, $userId, $context);
    }

    /** @param array<string, mixed> $context */
    private function record(string $severity, string $event, ?int $userId, array $context): void
    {
        $context = $this->scrub($context);
        $level = match ($severity) {
            'critical' => 'critical',
            'warning' => 'warning',
            default => 'info',
        };
        try {
            $this->logger->log($level, $event, ['user_id' => $userId, 'ip' => $this->ip] + $context);
        } catch (\Throwable) {
            // ignorar
        }
        if ($this->db === null) {
            return;
        }
        try {
            $this->db->run(
                'INSERT INTO security_events (event_type, severity, user_id, ip_address, details) VALUES (:e, :s, :u, :ip, :d)',
                [
                    'e' => mb_substr($event, 0, 80),
                    's' => $severity,
                    'u' => $userId,
                    'ip' => $this->ip,
                    'd' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                ],
            );
        } catch (\Throwable $e) {
            try {
                $this->logger->error('No se pudo guardar el evento de seguridad en la base de datos.', ['event' => $event]);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Defensa adicional: elimina valores con aspecto de secreto.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function scrub(array $context): array
    {
        foreach ($context as $k => $v) {
            if (preg_match('/token|secret|password|authorization|key|code_verifier/i', (string) $k)) {
                $context[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $context[$k] = $this->scrub($v);
            }
        }

        return $context;
    }
}
