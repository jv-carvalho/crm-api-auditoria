<?php

namespace Tests\Feature;

use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LeadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('X-Api-Key', 'test-key');
    }

    private function fakeSources()
    {
        Http::fake([
            '*/sources' => Http::response([
                'data' => [
                    ['code' => 'WEBSITE', 'label' => 'Site'],
                    ['code' => 'INSTAGRAM', 'label' => 'Instagram'],
                    ['code' => 'WHATSAPP', 'label' => 'WhatsApp'],
                ],
            ]),
        ]);
    }

    public function test_can_list_leads()
    {
        Lead::factory()->count(3)->create();

        $response = $this->getJson('/api/leads');

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');
    }

    public function test_can_filter_leads_by_status()
    {
        Lead::factory()->count(2)->create(['status' => 'QUALIFIED']);
        Lead::factory()->count(3)->create(['status' => 'NEW']);

        $response = $this->getJson('/api/leads?status=QUALIFIED');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function test_can_filter_leads_by_status_and_source()
    {
        Lead::factory()->create(['status' => 'QUALIFIED', 'source' => 'INSTAGRAM']);
        Lead::factory()->create(['status' => 'QUALIFIED', 'source' => 'WEBSITE']);
        Lead::factory()->create(['status' => 'NEW', 'source' => 'INSTAGRAM']);

        $response = $this->getJson('/api/leads?status=QUALIFIED&source=INSTAGRAM');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_can_search_leads()
    {
        Lead::factory()->create(['name' => 'João Pereira']);
        Lead::factory()->create(['name' => 'Maria Souza']);

        $response = $this->getJson('/api/leads?search=João');

        $response->assertStatus(200);
    }

    public function test_search_respects_status_filter()
    {
        Lead::factory()->create()->forceFill([
            'name' => 'João Pereira',
            'email' => 'joao@example.com',
            'status' => 'QUALIFIED',
        ])->save();

        Lead::factory()->create()->forceFill([
            'name' => 'Maria Souza',
            'email' => 'maria@example.com',
            'company' => 'Joao Consultoria',
            'status' => 'NEW',
        ])->save();

        Lead::factory()->create()->forceFill([
            'name' => 'Carlos Lima',
            'email' => 'carlos@example.com',
            'company' => 'Joao Engenharia',
            'status' => 'QUALIFIED',
        ])->save();

        $response = $this->getJson('/api/leads?status=QUALIFIED&search=joao');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonFragment(['name' => 'João Pereira']);
        $response->assertJsonFragment(['name' => 'Carlos Lima']);
        $response->assertJsonMissing(['name' => 'Maria Souza']);
    }

    public function test_can_show_lead()
    {
        $lead = Lead::factory()->create();

        $response = $this->getJson('/api/leads/'.$lead->id);

        $response->assertStatus(200);
        $response->assertJsonFragment(['email' => $lead->email]);
    }

    public function test_show_returns_404_when_lead_does_not_exist()
    {
        $response = $this->getJson('/api/leads/999');

        $response->assertStatus(404);
    }

    public function test_can_create_lead()
    {
        $this->fakeSources();

        $response = $this->postJson('/api/leads', [
            'name' => 'Carlos Lima',
            'email' => 'carlos.lima@example.com',
            'phone' => '(11) 91234-5678',
            'company' => 'Empresa Teste',
            'source' => 'WEBSITE',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('leads', [
            'email' => 'carlos.lima@example.com',
            'phone' => '11912345678',
        ]);
    }

    public function test_create_lead_does_not_allow_internal_fields()
    {
        $this->fakeSources();

        $response = $this->postJson('/api/leads', [
            'name' => 'Carlos Lima',
            'email' => 'carlos.lima@example.com',
            'source' => 'WEBSITE',
            'external_id' => 999,
            'raw_payload' => '{"malicious": true}',
            'synced_at' => '2026-01-01 00:00:00',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('leads', [
            'email' => 'carlos.lima@example.com',
            'external_id' => null,
            'raw_payload' => null,
        ]);
    }

    public function test_create_lead_requires_name()
    {
        $this->fakeSources();

        $response = $this->postJson('/api/leads', [
            'email' => 'sem.nome@example.com',
            'source' => 'WEBSITE',
        ]);

        $response->assertStatus(422);
    }

    public function test_create_lead_requires_valid_source()
    {
        $this->fakeSources();

        $response = $this->postJson('/api/leads', [
            'name' => 'Ana Costa',
            'email' => 'ana.costa@example.com',
            'source' => 'TELEGRAM',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('source');
    }

    public function test_create_lead_rejects_invalid_status()
    {
        $this->fakeSources();

        $response = $this->postJson('/api/leads', [
            'name' => 'Status Invalido',
            'email' => 'status.invalido@example.com',
            'source' => 'WEBSITE',
            'status' => 'INVALIDO',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('status');
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_create_lead_accepts_valid_status()
    {
        $this->fakeSources();

        $response = $this->postJson('/api/leads', [
            'name' => 'Lead Ganho',
            'email' => 'lead.ganho@example.com',
            'source' => 'WEBSITE',
            'status' => 'WON',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('leads', [
            'email' => 'lead.ganho@example.com',
            'status' => 'WON',
        ]);
    }

    public function test_create_lead_rejects_null_status()
    {
        $this->fakeSources();

        $response = $this->postJson('/api/leads', [
            'name' => 'Status Nulo',
            'email' => 'status.nulo@example.com',
            'source' => 'WEBSITE',
            'status' => null,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('status');
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_can_update_lead()
    {
        $lead = Lead::factory()->create(['status' => 'NEW']);

        $response = $this->putJson('/api/leads/'.$lead->id, [
            'status' => 'CONTACTED',
            'notes' => 'Primeiro contato realizado por telefone.',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'status' => 'CONTACTED',
        ]);
    }

    public function test_update_lead_does_not_allow_internal_fields()
    {
        $lead = Lead::factory()->create([
            'status' => 'NEW',
            'external_id' => 10,
            'raw_payload' => '{"original": true}',
        ]);

        $response = $this->putJson('/api/leads/'.$lead->id, [
            'name' => 'Nome Atualizado',
            'external_id' => 999,
            'raw_payload' => '{"malicious": true}',
            'synced_at' => '2026-01-01 00:00:00',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'name' => 'Nome Atualizado',
            'external_id' => 10,
            'raw_payload' => '{"original": true}',
        ]);
    }

    public function test_closed_lead_can_be_updated_without_changing_status()
    {
        $lead = Lead::factory()->create([
            'status' => 'WON',
            'notes' => 'Observação anterior',
        ]);

        $response = $this->putJson('/api/leads/'.$lead->id, [
            'notes' => 'Nova observação',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'status' => 'WON',
            'notes' => 'Nova observação',
        ]);
    }

    public function test_can_delete_lead()
    {
        $lead = Lead::factory()->create();

        $response = $this->deleteJson('/api/leads/'.$lead->id);

        $response->assertStatus(200);
    }

    public function test_write_request_without_api_key_is_rejected()
    {
        unset($this->defaultHeaders['X-Api-Key']);

        $response = $this->postJson('/api/leads', [
            'name' => 'Sem Chave',
            'email' => 'sem.chave@example.com',
            'source' => 'WEBSITE',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Unauthorized']);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_write_request_with_wrong_api_key_is_rejected()
    {
        $response = $this->withHeader('X-Api-Key', 'wrong-key')->postJson('/api/leads', [
            'name' => 'Chave Errada',
            'email' => 'chave.errada@example.com',
            'source' => 'WEBSITE',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Unauthorized']);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_write_request_with_correct_api_key_is_allowed()
    {
        $this->fakeSources();

        $response = $this->withHeader('X-Api-Key', 'test-key')->postJson('/api/leads', [
            'name' => 'Chave Certa',
            'email' => 'chave.certa@example.com',
            'source' => 'WEBSITE',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('leads', ['email' => 'chave.certa@example.com']);
    }

    public function test_write_request_is_rejected_when_api_key_is_empty()
    {
        config(['app.api_key' => '']);

        $response = $this->postJson('/api/leads', [
            'name' => 'Chave Vazia',
            'email' => 'chave.vazia@example.com',
            'source' => 'WEBSITE',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Unauthorized']);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_write_request_is_rejected_when_api_key_is_not_configured()
    {
        config(['app.api_key' => null]);

        $response = $this->postJson('/api/leads', [
            'name' => 'Chave Ausente',
            'email' => 'chave.ausente@example.com',
            'source' => 'WEBSITE',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Unauthorized']);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_sync_command_imports_leads_from_crm()
    {
        Http::fake([
            '*/leads*' => Http::response($this->crmPage([
                [
                    'id' => 10,
                    'name' => 'Fernanda Alves',
                    'email' => 'fernanda.alves@example.com',
                    'phone' => '11988887777',
                    'company' => null,
                    'source' => 'INSTAGRAM',
                    'status' => 'NEW',
                    'notes' => null,
                    'created_at' => '2026-05-10T14:00:00Z',
                ],
                [
                    'id' => 11,
                    'name' => 'Rafael Gomes',
                    'email' => 'rafael.gomes@example.com',
                    'phone' => '(21) 3333-4444',
                    'company' => 'Gomes Engenharia',
                    'source' => 'REFERRAL',
                    'status' => 'QUALIFIED',
                    'notes' => 'Indicado pelo cliente Padaria Pão Dourado.',
                    'created_at' => '2026-05-12T09:30:00Z',
                ],
            ], 1, 1)),
        ]);

        $this->artisan('leads:sync')->assertExitCode(0);

        $this->assertDatabaseCount('leads', 2);
        $this->assertDatabaseHas('leads', ['external_id' => 11, 'email' => 'rafael.gomes@example.com']);
    }

    public function test_sync_keeps_status_and_notes_maintained_locally()
    {
        $lead = Lead::factory()->create([
            'external_id' => 10,
            'status' => 'PROPOSAL',
            'notes' => 'Proposta enviada em reunião.',
        ]);

        Http::fake([
            '*/leads*' => Http::response($this->crmPage([
                [
                    'id' => 10,
                    'name' => 'Fernanda Alves',
                    'email' => 'fernanda.alves@example.com',
                    'phone' => '11988887777',
                    'company' => 'Alves Consultoria',
                    'source' => 'INSTAGRAM',
                    'status' => 'NEW',
                    'notes' => null,
                    'created_at' => '2026-05-10T14:00:00Z',
                ],
            ], 1, 1)),
        ]);

        $this->artisan('leads:sync')->assertExitCode(0);

        $lead->refresh();
        $this->assertEquals('PROPOSAL', $lead->status);
        $this->assertEquals('Proposta enviada em reunião.', $lead->notes);
        $this->assertEquals('Alves Consultoria', $lead->company);
        $this->assertDatabaseCount('leads', 1);
    }

    public function test_sync_command_fails_when_crm_is_unavailable()
    {
        Http::fake([
            '*' => Http::response(['error' => 'internal_error'], 500),
        ]);

        $this->artisan('leads:sync')
            ->assertExitCode(1)
            ->doesntExpectOutput('Sincronização concluída.');

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_sync_imports_leads_from_two_pages()
    {
        $this->fakeCrmPages([
            1 => [$this->crmLead(10, 'pagina1@example.com')],
            2 => [$this->crmLead(11, 'pagina2@example.com')],
        ]);

        $this->artisan('leads:sync')->assertExitCode(0);

        $this->assertDatabaseCount('leads', 2);
        $this->assertDatabaseHas('leads', ['external_id' => 10, 'email' => 'pagina1@example.com']);
        $this->assertDatabaseHas('leads', ['external_id' => 11, 'email' => 'pagina2@example.com']);
        $this->assertCrmPageRequests(2);
    }

    public function test_sync_imports_leads_from_three_pages()
    {
        $this->fakeCrmPages([
            1 => [$this->crmLead(10, 'pagina1@example.com')],
            2 => [$this->crmLead(11, 'pagina2@example.com')],
            3 => [$this->crmLead(12, 'pagina3@example.com')],
        ]);

        $this->artisan('leads:sync')->assertExitCode(0);

        $this->assertDatabaseCount('leads', 3);
        $this->assertDatabaseHas('leads', ['external_id' => 12, 'email' => 'pagina3@example.com']);
        $this->assertCrmPageRequests(3);
    }

    public function test_sync_sends_page_and_per_page_on_each_request()
    {
        $this->fakeCrmPages([
            1 => [$this->crmLead(10, 'pagina1@example.com')],
            2 => [$this->crmLead(11, 'pagina2@example.com')],
        ]);

        $this->artisan('leads:sync')->assertExitCode(0);

        $this->assertCrmPageRequests(2);
    }

    public function test_sync_fails_when_second_page_returns_http_error()
    {
        Http::fake(function ($request) {
            $page = (int) $request->data()['page'];

            if ($page === 1) {
                return Http::response($this->crmPage([
                    $this->crmLead(10, 'pagina1@example.com'),
                ], 1, 2));
            }

            return Http::response(['error' => 'internal_error'], 500);
        });

        $this->artisan('leads:sync')
            ->assertExitCode(1)
            ->doesntExpectOutput('Sincronização concluída.');

        $this->assertDatabaseMissing('leads', ['email' => 'pagina1@example.com']);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_sync_does_not_persist_partial_pages_when_collection_fails()
    {
        Lead::factory()->create([
            'external_id' => 1,
            'email' => 'local@example.com',
            'status' => 'PROPOSAL',
            'notes' => 'Nota local.',
        ]);

        Http::fake(function ($request) {
            $page = (int) $request->data()['page'];

            if ($page === 1) {
                return Http::response($this->crmPage([
                    $this->crmLead(10, 'pagina1@example.com'),
                ], 1, 2));
            }

            return Http::response(['error' => 'internal_error'], 500);
        });

        $this->artisan('leads:sync')->assertExitCode(1);

        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseHas('leads', [
            'external_id' => 1,
            'status' => 'PROPOSAL',
            'notes' => 'Nota local.',
        ]);
        $this->assertDatabaseMissing('leads', ['email' => 'pagina1@example.com']);
    }

    public function test_sync_fails_when_crm_connection_fails()
    {
        Http::fake(function () {
            throw new ConnectionException('Falha de conexão com o CRM.');
        });

        $this->artisan('leads:sync')
            ->assertExitCode(1)
            ->expectsOutput('Falha de conexão ao buscar a página 1 do CRM.')
            ->doesntExpectOutput('Sincronização concluída.');

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_sync_fails_when_response_has_no_data()
    {
        Http::fake([
            '*' => Http::response([
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ]),
        ]);

        $this->artisan('leads:sync')
            ->assertExitCode(1)
            ->expectsOutput('Resposta inválida ao buscar a página 1 do CRM: campo data ausente.')
            ->doesntExpectOutput('Sincronização concluída.');
    }

    public function test_sync_fails_when_response_has_no_last_page()
    {
        Http::fake([
            '*' => Http::response([
                'data' => [],
                'meta' => ['current_page' => 1],
            ]),
        ]);

        $this->artisan('leads:sync')
            ->assertExitCode(1)
            ->expectsOutput('Resposta inválida ao buscar a página 1 do CRM: campo meta.last_page ausente.')
            ->doesntExpectOutput('Sincronização concluída.');
    }

    public function test_sync_fails_when_last_page_is_invalid()
    {
        foreach ([1.5, 'abc', 0, -1] as $lastPage) {
            Http::fake([
                '*' => Http::response([
                    'data' => [],
                    'meta' => [
                        'current_page' => 1,
                        'last_page' => $lastPage,
                    ],
                ]),
            ]);

            $this->artisan('leads:sync')
                ->assertExitCode(1)
                ->expectsOutput('Resposta inválida ao buscar a página 1 do CRM: meta.last_page inválido.');

            $this->assertDatabaseCount('leads', 0);
        }
    }

    public function test_sync_fails_when_lead_has_no_id()
    {
        $lead = $this->crmLead(10, 'valido@example.com');
        unset($lead['id']);

        $this->fakeCrmPages([
            1 => [$lead],
        ]);

        $this->artisan('leads:sync')
            ->assertExitCode(1)
            ->expectsOutputToContain('Campos inválidos: id');

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_sync_fails_when_lead_has_no_name()
    {
        $lead = $this->crmLead(10, 'valido@example.com');
        unset($lead['name']);

        $this->fakeCrmPages([
            1 => [$lead],
        ]);

        $this->artisan('leads:sync')
            ->assertExitCode(1)
            ->expectsOutputToContain('Campos inválidos: name');

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_sync_fails_when_lead_has_invalid_email()
    {
        $this->fakeCrmPages([
            1 => [
                $this->crmLead(10, 'email-invalido'),
            ],
        ]);

        $this->artisan('leads:sync')
            ->assertExitCode(1)
            ->expectsOutputToContain('Campos inválidos: email');

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_sync_fails_when_lead_has_invalid_status()
    {
        $this->fakeCrmPages([
            1 => [
                $this->crmLead(10, 'status@example.com', [
                    'status' => 'INVALID',
                ]),
            ],
        ]);

        $this->artisan('leads:sync')
            ->assertExitCode(1)
            ->expectsOutputToContain('Campos inválidos: status');

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_sync_fails_when_lead_is_not_an_array()
    {
        $this->fakeCrmPages([
            1 => [
                'registro-invalido',
            ],
        ]);

        $this->artisan('leads:sync')
            ->assertExitCode(1)
            ->expectsOutputToContain('o registro não é um objeto válido');

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_sync_does_not_persist_any_lead_when_one_record_is_invalid()
    {
        $this->fakeCrmPages([
            1 => [
                $this->crmLead(10, 'valido@example.com'),
                $this->crmLead(11, 'email-invalido'),
            ],
        ]);

        $this->artisan('leads:sync')
            ->assertExitCode(1)
            ->expectsOutputToContain('Campos inválidos: email');

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_sync_rolls_back_all_leads_when_persistence_fails()
    {
        $this->fakeCrmPages([
            1 => [
                $this->crmLead(10, 'primeiro@example.com'),
                $this->crmLead(11, 'segundo@example.com'),
            ],
        ]);

        $created = 0;

        \Illuminate\Support\Facades\Event::listen(
            'eloquent.creating: '.Lead::class,
            function () use (&$created) {
                $created++;

                if ($created === 2) {
                    throw new \RuntimeException('Falha simulada durante a gravação.');
                }
            }
        );

        try {
            $this->artisan('leads:sync');

            $this->fail('Era esperada uma exceção durante a gravação.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Falha simulada durante a gravação.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount('leads', 0);
    }

    /**
     * @param  array<int, array<int, array<string, mixed>>>  $pages
     */
    private function fakeCrmPages(array $pages): void
    {
        $lastPage = max(array_keys($pages));

        Http::fake(function ($request) use ($pages, $lastPage) {
            $page = (int) $request->data()['page'];

            return Http::response($this->crmPage($pages[$page] ?? [], $page, $lastPage));
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function crmLead(int $id, string $email, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'name' => 'Lead '.$id,
            'email' => $email,
            'phone' => '11988887777',
            'company' => 'Empresa '.$id,
            'source' => 'WEBSITE',
            'status' => 'NEW',
            'notes' => null,
            'created_at' => '2026-05-10T14:00:00Z',
        ], $overrides);
    }

    /**
     * @param  array<int, array<string, mixed>>  $leads
     * @return array<string, mixed>
     */
    private function crmPage(array $leads, int $page, int $lastPage): array
    {
        return [
            'data' => $leads,
            'meta' => [
                'current_page' => $page,
                'per_page' => 100,
                'total' => count($leads),
                'last_page' => $lastPage,
            ],
        ];
    }

    private function assertCrmPageRequests(int $pages): void
    {
        $recorded = Http::recorded();

        $this->assertCount($pages, $recorded);

        foreach (range(1, $pages) as $page) {
            $query = $recorded[$page - 1][0]->data();

            $this->assertSame($page, $query['page']);
            $this->assertSame(100, $query['per_page']);
        }
    }
}
