<?php

namespace App\Http\Controllers;

use App\Models\CustomerSite;
use App\Models\ServiceTicket;
use App\Services\M1CommandService;
use App\Services\ServiceTicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ServiceTicketController extends Controller
{
    public function index(Request $request, M1CommandService $commands): Response
    {
        $commands->authorize($request->user(), ['admin', 'koordinator']);

        return Inertia::render('tickets/index', [
            'tickets' => ServiceTicket::query()->with('site:id,site_name')->latest('id')
                ->paginate(10)->through(fn (ServiceTicket $ticket): array => [
                    'id' => $ticket->id,
                    'number' => $ticket->ticket_number,
                    'status' => $ticket->status,
                    'contact' => $ticket->contact_label,
                    'site' => $ticket->site?->site_name,
                    'show_url' => route('tickets.show', $ticket),
                ]),
            'createUrl' => $request->user()->hasRole('admin') ? route('tickets.create') : null,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('tickets/create', [
            'commandKey' => (string) Str::uuid(),
            'sites' => $this->sites(),
            'links' => ['store' => route('tickets.store'), 'index' => route('tickets.index')],
        ]);
    }

    public function store(Request $request, ServiceTicketService $service): RedirectResponse
    {
        $ticket = $service->intake($request->user(), $request->all());

        return redirect()->route('tickets.show', $ticket)->with('success', 'Ticket berhasil dicatat.');
    }

    public function show(Request $request, ServiceTicket $ticket, M1CommandService $commands): Response
    {
        $actor = $commands->authorize($request->user(), ['admin', 'koordinator']);

        return Inertia::render('tickets/show', [
            'ticket' => $ticket->only([
                'id', 'ticket_number', 'description', 'contact_label', 'status', 'site_id',
                'primary_asset_id', 'coverage_disposition', 'authorization_ref', 'lock_version',
            ]),
            'sites' => $this->sites(),
            'commandKeys' => ['triage' => (string) Str::uuid(), 'approve' => (string) Str::uuid()],
            'workOrders' => $ticket->workOrders()->visibleTo($actor)->latest('id')->get(['id', 'wo_number', 'status']),
            'links' => [
                'index' => route('tickets.index'),
                'triage' => $actor->hasRole('koordinator') && in_array($ticket->status, ['new', 'triaged', 'waiting_information'], true)
                    ? route('tickets.triage', $ticket)
                    : null,
                'approve' => $actor->hasRole('koordinator') && $ticket->status === 'triaged'
                    ? route('tickets.approve', $ticket)
                    : null,
                'createWorkOrder' => $actor->hasRole('koordinator') && in_array($ticket->status, ['approved', 'in_fulfillment'], true)
                    ? route('work-orders.create', ['ticket_id' => $ticket->id, 'm1' => 1])
                    : null,
            ],
        ]);
    }

    public function triage(Request $request, ServiceTicket $ticket, ServiceTicketService $service): RedirectResponse
    {
        $service->decide($request->user(), $ticket->id, 'triage', $request->all());

        return redirect()->route('tickets.show', $ticket)->with('success', 'Triage tersimpan.');
    }

    public function approve(Request $request, ServiceTicket $ticket, ServiceTicketService $service): RedirectResponse
    {
        $service->decide($request->user(), $ticket->id, 'approve', $request->all());

        return redirect()->route('tickets.show', $ticket)->with('success', 'Persetujuan operasional tercatat.');
    }

    /** @return array<int, array<string, mixed>> */
    private function sites(): array
    {
        return CustomerSite::query()->with('assets:id,customer_site_id,asset_code')
            ->orderBy('site_name')->orderBy('id')->get(['id', 'site_name'])
            ->map(fn (CustomerSite $site): array => [
                'id' => $site->id,
                'site_name' => $site->site_name,
                'assets' => $site->assets->map->only(['id', 'asset_code'])->all(),
            ])->all();
    }
}
