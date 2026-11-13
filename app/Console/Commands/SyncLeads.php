<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\CrmApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SyncLeads extends Command
{
    protected $signature = 'leads:sync';

    protected $description = 'Importa e atualiza os leads a partir da API do CRM';

    public function handle()
    {
        $client = new CrmApiClient();

        try {
            $leads = $client->getLeads();
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->info('Leads recebidos: '.count($leads));

        // Valida todos os registros antes de iniciar qualquer gravação.
        foreach ($leads as $index => $item) {
            if (! is_array($item)) {
                $this->error(
                    'Lead inválido na posição '.($index + 1).': o registro não é um objeto válido.'
                );

                return Command::FAILURE;
            }

            $validator = Validator::make($item, [
                'id' => ['required', 'integer', 'min:1'],
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:255'],
                'company' => ['nullable', 'string', 'max:255'],
                'source' => ['required', 'string', 'max:255'],
                'status' => ['required', 'string', Rule::in(Lead::STATUSES)],
                'notes' => ['nullable', 'string'],
                'created_at' => ['required', 'date'],
            ]);

            if ($validator->fails()) {
                $leadId = $item['id'] ?? 'não informado';
                $invalidFields = implode(', ', $validator->errors()->keys());

                $this->error(
                    'Lead inválido na posição '.($index + 1).
                        " (ID: {$leadId}). Campos inválidos: {$invalidFields}."
                );

                return Command::FAILURE;
            }
        }

        // Todos os registros foram validados. Agora podemos persistir.
        DB::transaction(function () use ($leads) {
            foreach ($leads as $item) {
                $lead = Lead::firstOrNew(['external_id' => $item['id']]);

                $lead->fill([
                    'name' => $item['name'],
                    'email' => $item['email'],
                    'phone' => $item['phone'] ?? null,
                    'company' => $item['company'] ?? null,
                    'source' => $item['source'],
                    'created_at' => $item['created_at'],
                    'raw_payload' => json_encode($item),
                    'synced_at' => now(),
                ]);

                // Status e observações dos leads existentes são mantidos localmente.
                if (! $lead->exists) {
                    $lead->status = $item['status'];
                    $lead->notes = $item['notes'] ?? null;
                }

                $lead->save();
            }
        });

        $this->info('Sincronização concluída.');

        return Command::SUCCESS;
    }
}
