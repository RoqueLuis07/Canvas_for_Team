<?php

namespace App\Services\LLM;

final class LLMResponse
{
    public function __construct(
        public readonly string $content,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {}

    /**
     * Misma forma que la respuesta de `chat/completions` de OpenAI, útil para
     * probar código que espera ese formato.
     *
     * @return array<string, mixed>
     */
    public function toOpenAiFormat(): array
    {
        return [
            'id' => 'chatcmpl-simulado',
            'object' => 'chat.completion',
            'model' => $this->model,
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $this->content],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => $this->inputTokens,
                'completion_tokens' => $this->outputTokens,
                'total_tokens' => $this->inputTokens + $this->outputTokens,
            ],
        ];
    }
}
