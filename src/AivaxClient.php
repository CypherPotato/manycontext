<?php

namespace ManyContext;

final class AivaxClient
{
    private const MAX_RERANK_DOCUMENTS = 3000;
    private const MAX_RERANK_RESULTS = 200;

    public const DEFAULT_MODEL = '@inception/mercury-2';

    public function __construct(private readonly string $apiKey, private readonly string $model)
    {
    }

    public function complete(array $messages): string
    {
        if ($this->apiKey === '') {
            throw new HttpError(503, 'Chave da AIVAX não configurada. Um administrador pode defini-la em Administração.');
        }
        if (!function_exists('curl_init')) {
            throw new HttpError(503, 'A extensão cURL do PHP é necessária para usar a IA.');
        }

        $curl = curl_init('https://inference.aivax.net/api/v1/chat/completions');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'messages' => $messages,
                'model' => $this->model,
                'stream' => false,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'User-Agent: manycontext-php/1.0',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 90,
        ]);
        $raw = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if (!is_string($raw) || $raw === '') {
            throw new HttpError(502, $error !== '' ? 'Não foi possível acessar a AIVAX.' : 'AIVAX retornou uma resposta vazia.');
        }
        $response = json_decode($raw, true);
        $content = $response['choices'][0]['message']['content'] ?? null;
        if ($status < 200 || $status >= 300 || !is_string($content) || trim($content) === '') {
            throw new HttpError(502, 'AIVAX não conseguiu gerar o resultado.');
        }

        return trim($content);
    }

    public function rerank(string $query, array $documents, int $topN): array
    {
        if ($this->apiKey === '') {
            throw new HttpError(503, 'Chave da AIVAX não configurada. Um administrador pode defini-la em Administração.');
        }
        if (!function_exists('curl_init')) {
            throw new HttpError(503, 'A extensão cURL do PHP é necessária para usar a busca semântica.');
        }
        if (count($documents) === 0) {
            return [];
        }

        $curl = curl_init('https://inference.aivax.net/api/v1/generations/reflex/rerank');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'query' => $query,
                'documents' => array_slice(array_values($documents), 0, self::MAX_RERANK_DOCUMENTS),
                'top_n' => max(1, min($topN, count($documents))),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'User-Agent: manycontext-php/1.0',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 90,
        ]);
        $raw = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if (!is_string($raw) || $raw === '') {
            throw new HttpError(502, $error !== '' ? 'Não foi possível acessar a AIVAX.' : 'AIVAX retornou uma resposta vazia.');
        }
        $response = json_decode($raw, true);
        $results = $response['results'] ?? null;
        if ($status < 200 || $status >= 300 || !is_array($results)) {
            throw new HttpError(502, 'AIVAX não conseguiu realizar a busca semântica.');
        }

        return array_values(array_filter(array_map(
            fn (mixed $result) => is_array($result)
                && is_int($result['index'] ?? null)
                && is_numeric($result['relevance_score'] ?? null)
                ? ['index' => $result['index'], 'relevance_score' => (float)$result['relevance_score']]
                : null,
            $results
        )));
    }

    public function rerankTasks(string $query, array $tasks): array
    {
        $query = trim($query);
        if ($query === '' || count($tasks) === 0) {
            return $tasks;
        }

        $candidates = array_slice(array_values($tasks), 0, self::MAX_RERANK_DOCUMENTS);
        $results = $this->rerank(
            $query,
            array_map(fn (array $task) => implode("\n", array_filter([
                'ID: #' . $task['id'],
                'Título: ' . $task['title'],
                'Descrição: ' . $task['desc'],
                isset($task['group']) ? 'Grupo: ' . $task['group'] : null,
                'Status: ' . $task['status'],
                'Prioridade: ' . $task['priority'],
                isset($task['workingAgent']) ? 'Agente: ' . $task['workingAgent'] : null,
                count($task['assignees'] ?? []) > 0 ? 'Responsáveis: ' . implode(', ', $task['assignees']) : null,
                count($task['tags'] ?? []) > 0 ? 'Tags: ' . implode(', ', $task['tags']) : null,
            ])), $candidates),
            min(self::MAX_RERANK_RESULTS, count($candidates))
        );

        return array_values(array_filter(array_map(
            fn (array $result) => isset($candidates[$result['index']]) ? [
                ...$candidates[$result['index']],
                'relevanceScore' => $result['relevance_score'],
            ] : null,
            $results
        )));
    }
}
