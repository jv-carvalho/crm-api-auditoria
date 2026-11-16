<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeadRequest;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    public function index(Request $request)
    {
        $query = Lead::query();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('source')) {
            $query->where('source', $request->source);
        }

        if ($request->has('search')) {
            $search = '%'.$request->search.'%';

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                    ->orWhere('email', 'like', $search)
                    ->orWhere('company', 'like', $search);
            });
        }

        $leads = $query->orderBy('created_at', 'desc')->get();

        $summary = [];
        foreach (Lead::STATUSES as $status) {
            $summary[$status] = Lead::where('status', $status)->count();
        }

        $data = LeadResource::collection($leads)->resolve(request());

        return response()->json([
            'data' => $data,
            'total' => $leads->count(),
            'summary' => $summary,
        ]);
    }

    public function show($id)
    {
        $lead = Lead::find($id);

        if (! $lead) {
            return response()->json(['message' => 'Lead não encontrado'], 404);
        }

        return new LeadResource($lead);
    }

    public function store(StoreLeadRequest $request)
    {
        $lead = Lead::create($request->validated());

        return (new LeadResource($lead))->response()->setStatusCode(201);
    }

    public function update(Request $request, $id)
    {
        $lead = Lead::find($id);

        if (! $lead) {
            return response()->json(['error' => 'Lead not found'], 404);
        }

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:leads,email,'.$lead->id,
            'phone' => 'nullable|string',
            'company' => 'nullable|string',
            'source' => 'sometimes|string',
            'status' => 'sometimes|in:'.implode(',', Lead::STATUSES),
            'notes' => 'nullable|string',
        ]);

        // Leads ganhos ou perdidos não podem mais mudar de status.
        if (
            $request->has('status')
            && $lead->status !== $request->status
            && $lead->isClosed()
        ) {
            return response()->json([
                'message' => 'Não é possível alterar o status de um lead finalizado',
            ], 400);
        }

        $lead->update($request->only([
            'name',
            'email',
            'phone',
            'company',
            'source',
            'status',
            'notes',
        ]));

        return new LeadResource($lead);
    }

    public function destroy($id)
    {
        $lead = Lead::findOrFail($id);
        $lead->delete();

        return response()->json(['message' => 'Lead removido com sucesso']);
    }
}
