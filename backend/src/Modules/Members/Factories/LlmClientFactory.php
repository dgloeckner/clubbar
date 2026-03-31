<?php

declare(strict_types=1);

namespace App\Modules\Members\Factories;

use App\Modules\Members\Contracts\LlmClientInterface;
use App\Modules\Members\LlmClients\AnthropicClient;
use App\Modules\Members\LlmClients\OpenAiClient;
use App\Shared\Config\AppConfig;

class LlmClientFactory
{
    public function __construct(private AppConfig $config) {}

    /**
     * Returns null when LLM is not configured (LLM_PROVIDER or LLM_API_KEY absent).
     * Extraction is silently skipped when this returns null.
     *
     * @throws \RuntimeException for unknown provider values
     */
    public function create(): ?LlmClientInterface
    {
        if ($this->config->llmProvider === null || $this->config->llmApiKey === null) {
            return null;
        }

        return match ($this->config->llmProvider) {
            'anthropic' => new AnthropicClient(
                $this->config->llmApiKey,
                $this->config->llmModel ?? 'claude-sonnet-4-6',
                $this->config->llmThinkingBudget,
            ),
            'openai' => new OpenAiClient(
                $this->config->llmApiKey,
                $this->config->llmModel ?? 'gpt-4o',
            ),
            default => throw new \RuntimeException(
                "Unknown LLM_PROVIDER: '{$this->config->llmProvider}'. Valid values: anthropic, openai"
            ),
        };
    }
}
