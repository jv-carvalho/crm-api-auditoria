<?php

namespace Tests\Unit;

use App\Services\CrmApiClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CrmApiClientTest extends TestCase
{
    public function test_get_leads_aggregates_every_page()
    {
        Http::fake(function ($request) {
            $page = (int) $request->data()['page'];

            return Http::response([
                'data' => [
                    ['id' => $page, 'name' => 'Lead '.$page],
                ],
                'meta' => [
                    'current_page' => $page,
                    'last_page' => 2,
                ],
            ]);
        });

        $leads = (new CrmApiClient())->getLeads();

        $this->assertSame([
            ['id' => 1, 'name' => 'Lead 1'],
            ['id' => 2, 'name' => 'Lead 2'],
        ], $leads);

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);
        $this->assertSame(1, $recorded[0][0]->data()['page']);
        $this->assertSame(100, $recorded[0][0]->data()['per_page']);
        $this->assertSame(2, $recorded[1][0]->data()['page']);
        $this->assertSame(100, $recorded[1][0]->data()['per_page']);
    }

    public function test_get_leads_returns_empty_list_when_crm_has_no_leads()
    {
        Http::fake([
            '*' => Http::response([
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                ],
            ]),
        ]);

        $this->assertSame([], (new CrmApiClient())->getLeads());
        $this->assertCount(1, Http::recorded());
    }

    public function test_get_leads_rejects_more_than_one_thousand_pages()
    {
        Http::fake([
            '*' => Http::response([
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1001,
                ],
            ]),
        ]);

        try {
            (new CrmApiClient())->getLeads();
            $this->fail('A coleta deveria ter sido interrompida.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Paginação do CRM excede o limite de 1000 páginas.', $exception->getMessage());
        }

        $this->assertCount(1, Http::recorded());
    }

    public function test_get_leads_rejects_inconsistent_last_page()
    {
        Http::fake(function ($request) {
            $page = (int) $request->data()['page'];

            return Http::response([
                'data' => [],
                'meta' => [
                    'current_page' => $page,
                    'last_page' => $page === 1 ? 2 : 3,
                ],
            ]);
        });

        try {
            (new CrmApiClient())->getLeads();
            $this->fail('A paginação inconsistente deveria ter sido rejeitada.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Paginação inconsistente do CRM na página 2.', $exception->getMessage());
        }

        $this->assertCount(2, Http::recorded());
    }

    public function test_get_leads_rejects_current_page_that_does_not_match_the_request()
    {
        Http::fake([
            '*' => Http::response([
                'data' => [],
                'meta' => [
                    'current_page' => 2,
                    'last_page' => 1,
                ],
            ]),
        ]);

        try {
            (new CrmApiClient())->getLeads();
            $this->fail('A página divergente deveria ter sido rejeitada.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Resposta inválida ao buscar a página 1 do CRM: meta.current_page não corresponde à página solicitada.',
                $exception->getMessage()
            );
        }

        $this->assertCount(1, Http::recorded());
    }
}
