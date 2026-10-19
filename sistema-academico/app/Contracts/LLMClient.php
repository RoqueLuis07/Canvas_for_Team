<?php

namespace App\Contracts;

use App\Services\LLM\LLMException;
use App\Services\LLM\LLMResponse;

interface LLMClient
{
    /**
     * Envía una conversación al modelo y devuelve su respuesta de texto.
     *
     * @param  list<array{role: 'system'|'user'|'assistant', content: string}>  $messages
     * @param  array{json?: bool, max_tokens?: int}  $options  `json: true` exige una respuesta JSON.
     *
     * @throws LLMException Si el proveedor falla, expira o no está disponible.
     */
    public function chat(array $messages, array $options = []): LLMResponse;
}
