<?php

declare(strict_types=1);

namespace App\Logging;

class Logger
{
    private const LEVELS = [
        'DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3, 'CRITICAL' => 4
    ];

    private string $logDir;
    private string $minLevel;
    private string $channel;

    public function __construct(string $logDir, string $minLevel = 'DEBUG', string $channel = 'app')
    {
        $this->logDir   = rtrim($logDir, '/');
        $this->minLevel = $minLevel;
        $this->channel  = $channel;

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    public function debug(string $msg, array $ctx = []): void    { $this->log('DEBUG', $msg, $ctx); }
    public function info(string $msg, array $ctx = []): void     { $this->log('INFO', $msg, $ctx); }
    public function warning(string $msg, array $ctx = []): void  { $this->log('WARNING', $msg, $ctx); }
    public function error(string $msg, array $ctx = []): void    { $this->log('ERROR', $msg, $ctx); }
    public function critical(string $msg, array $ctx = []): void { $this->log('CRITICAL', $msg, $ctx); }

    private function log(string $level, string $message, array $context): void
    {
        if (self::LEVELS[$level] < self::LEVELS[$this->minLevel]) {
            return;
        }

        $entry = json_encode([
            'ts'      => date('c'),
            'level'   => $level,
            'channel' => $this->channel,
            'msg'     => $message,
            'ctx'     => $context ?: null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        $file = $this->logDir . '/' . date('Y-m-d') . '.log';
        file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
    }

    public function purge(int $retainDays = 30): int
    {
        $deleted = 0;
        $cutoff = strtotime("-{$retainDays} days");

        foreach (glob($this->logDir . '/*.log') as $file) {
            $dateStr = basename($file, '.log');
            if (strtotime($dateStr) < $cutoff) {
                unlink($file);
                $deleted++;
            }
        }
        return $deleted;
    }
}
