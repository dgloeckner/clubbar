<?php

declare(strict_types=1);

namespace App\Shared\Config;

class AppConfig
{
    public readonly string $env;
    public readonly bool   $debug;
    public readonly int    $sessionMaxAge;
    public readonly int    $sessionRegenInterval;
    public readonly int    $tokenTtlDays;
    public readonly string $logDir;
    public readonly string $installKey;
    public readonly string $appUrl;
    public readonly ?string $llmProvider;
    public readonly ?string $llmApiKey;
    public readonly ?string $llmModel;
    public readonly int     $llmThinkingBudget;
    public readonly ?string $googleVisionKey;

    public function __construct()
    {
        $this->env                  = Env::get('APP_ENV', 'production');
        $this->debug                = filter_var(Env::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);
        $this->sessionMaxAge        = (int) Env::get('SESSION_MAX_AGE', '7200');
        $this->sessionRegenInterval = (int) Env::get('SESSION_REGEN_INTERVAL', '900');
        $this->tokenTtlDays         = self::readTokenTtlDays();
        $this->logDir               = __DIR__ . '/../../../logs';
        $this->installKey           = Env::get('INSTALL_KEY', '');
        $this->appUrl               = Env::get('APP_URL', 'http://localhost:8080');
        $this->llmProvider          = Env::get('LLM_PROVIDER', '') ?: null;
        $this->llmApiKey            = Env::get('LLM_API_KEY', '') ?: null;
        $this->llmModel             = Env::get('LLM_MODEL', '') ?: null;
        $this->llmThinkingBudget    = (int) Env::get('LLM_THINKING_BUDGET', '0');
        $this->googleVisionKey      = Env::get('GCLOUD_VISION_API', '') ?: null;
    }

    public function isProduction(): bool
    {
        return $this->env === 'production';
    }

    /**
     * Lifetime of a terminal API token, in days (#106).
     *
     * A zero or negative value is not honoured: it would either mint tokens
     * that are already expired or, read the other way, invite "0 = never" —
     * and "never" is the state this setting exists to end. The default stands
     * in instead.
     */
    private static function readTokenTtlDays(): int
    {
        $days = (int) Env::get('API_TOKEN_TTL_DAYS', '90');

        return $days > 0 ? $days : 90;
    }
}
