<?php

namespace App\Services;

use App\Exceptions\CrmApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CrmApiClient
{
    private const MAX_PAGES = 1000;

    /**
     * @return array<int, mixed>
     */
    public function getLeads(): array
    {
        $page = 1;
        $lastPage = 1;
        $leads = [];

        do {
            $payload = $this->fetchLeadsPage($page);
            $reportedLastPage = $payload['last_page'];

            if ($reportedLastPage > self::MAX_PAGES) {
                throw new RuntimeException(
                    'Paginação do CRM excede o limite de '.self::MAX_PAGES.' páginas.'
                );
            }

            if ($page > 1 && $reportedLastPage !== $lastPage) {
                throw new RuntimeException(
                    'Paginação inconsistente do CRM na página '.$page.'.'
                );
            }

            $lastPage = $reportedLastPage;

            foreach ($payload['data'] as $lead) {
                $leads[] = $lead;
            }

            $page++;
        } while ($page <= $lastPage);

        return $leads;
    }

    /**
     * @return array{data: array<int, mixed>, last_page: int}
     */
    private function fetchLeadsPage(int $page): array
    {
        try {
            $response = Http::withToken(config('services.crm_api.token'))
                ->acceptJson()
                ->get(config('services.crm_api.url').'/leads', [
                    'page' => $page,
                    'per_page' => 100,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'Falha de conexão ao buscar a página '.$page.' do CRM.',
                0,
                $exception
            );
        }

        $payload = $response->json();

        if ($response->failed() || ! is_array($payload)) {
            throw new RuntimeException(
                'Falha ao buscar a página '.$page.' do CRM.'
            );
        }

        if (! array_key_exists('data', $payload) || ! is_array($payload['data'])) {
            throw new RuntimeException(
                'Resposta inválida ao buscar a página '.$page.' do CRM: campo data ausente.'
            );
        }

        if (! isset($payload['meta']) || ! is_array($payload['meta']) || ! array_key_exists('last_page', $payload['meta'])) {
            throw new RuntimeException(
                'Resposta inválida ao buscar a página '.$page.' do CRM: campo meta.last_page ausente.'
            );
        }

        if (! $this->isPositiveInteger($payload['meta']['last_page'])) {
            throw new RuntimeException(
                'Resposta inválida ao buscar a página '.$page.' do CRM: meta.last_page inválido.'
            );
        }

        if (array_key_exists('current_page', $payload['meta']) && (
            ! $this->isPositiveInteger($payload['meta']['current_page'])
            || (int) $payload['meta']['current_page'] !== $page
        )) {
            throw new RuntimeException(
                'Resposta inválida ao buscar a página '.$page.' do CRM: meta.current_page não corresponde à página solicitada.'
            );
        }

        return [
            'data' => $payload['data'],
            'last_page' => (int) $payload['meta']['last_page'],
        ];
    }

    /**
     * @param  mixed  $value
     */
    private function isPositiveInteger($value): bool
    {
        if (is_int($value)) {
            return $value >= 1;
        }

        return is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1;
    }

    public function getSources(): array
    {
        try {
            $response = Http::withToken(config('services.crm_api.token'))
                ->acceptJson()
                ->get(config('services.crm_api.url').'/sources');
        } catch (ConnectionException $exception) {
            throw new CrmApiException(
                'Não foi possível conectar ao CRM.',
                0,
                $exception
            );
        }

        $payload = $response->json();

        if (! $response->successful() || ! is_array($payload)) {
            throw new CrmApiException(
                'Não foi possível consultar as origens do CRM.'
            );
        }

        if (! isset($payload['data']) || ! is_array($payload['data'])) {
            throw new CrmApiException(
                'O CRM retornou uma lista de origens inválida.'
            );
        }

        $sources = [];

        foreach ($payload['data'] as $source) {
            if (
                ! is_array($source)
                || ! isset($source['code'])
                || ! is_string($source['code'])
                || trim($source['code']) === ''
            ) {
                throw new CrmApiException(
                    'O CRM retornou uma origem inválida.'
                );
            }

            $sources[] = $source['code'];
        }

        return $sources;
    }
}
