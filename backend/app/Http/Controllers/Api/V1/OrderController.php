<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Jobs\GenerateTicketPdfJob;
use App\Jobs\SendOrderConfirmationEmailJob;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Services\PaymentMockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Commandes : tunnel d'achat asynchrone (j3-laravel).
 *
 * Flow :
 *   1. Validation
 *   2. Transaction : création Order pending + Tickets, increment sold
 *   3. PaymentMockService::process — usleep 800-1500 ms, marque paid
 *      (préservé : c'est le bottleneck métier réaliste qu'on simule, pas
 *      une dette à supprimer).
 *   4. Dispatch GenerateTicketPdfJob × N (queue Redis, supervisor Horizon)
 *   5. Dispatch SendOrderConfirmationEmailJob (queue Redis)
 *
 * Coût UX attendu : ~ 1.0-1.4 s mock paiement (latence métier), ~ 50 ms
 * d'overhead transaction + dispatch. Cible < 1.5 s vs 3-5 s starter.
 *
 * @perf-fix: dompdf et SMTP déportés en queue (cf. App\Jobs\*).
 * @perf-debt: pas de SELECT FOR UPDATE SKIP LOCKED → race conditions
 *             possibles sur ticket_categories.sold en haute charge
 *             (visible sous k6 checkout-stress). Hors périmètre
 *             j3-laravel (queues + Octane), à reprendre dans une
 *             itération concurrence dédiée.
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly PaymentMockService $payment,
    ) {}

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $user = $request->user();
        $sessionId = (int) $request->integer('event_session_id');
        $items = $request->array('items');

        // 1) Création Order pending + Tickets en transaction.
        $order = DB::transaction(function () use ($user, $sessionId, $items) {
            $totalCents = 0;

            // Validation des stocks et calcul du total.
            foreach ($items as $item) {
                $category = TicketCategory::where('id', $item['ticket_category_id'])
                    ->where('event_session_id', $sessionId)
                    ->first();

                if (! $category) {
                    throw new UnprocessableEntityHttpException(
                        "Catégorie {$item['ticket_category_id']} introuvable pour la session {$sessionId}."
                    );
                }
                if (! $category->isAvailable($item['quantity'])) {
                    throw new UnprocessableEntityHttpException(
                        "Plus assez de places dans la catégorie « {$category->name} »."
                    );
                }
                $totalCents += $category->price_cents * $item['quantity'];
            }

            $order = Order::create([
                'user_id' => $user->id,
                'total_cents' => $totalCents,
                'status' => Order::STATUS_PENDING,
            ]);

            foreach ($items as $item) {
                $category = TicketCategory::find($item['ticket_category_id']);
                for ($i = 0; $i < $item['quantity']; $i++) {
                    Ticket::create([
                        'order_id' => $order->id,
                        'ticket_category_id' => $category->id,
                        'event_session_id' => $sessionId,
                        'code' => Str::uuid()->toString(),
                        'holder_name' => $item['holder_name'],
                        'status' => Ticket::STATUS_VALID,
                    ]);
                }
                // @perf-debt: increment naïf, pas de FOR UPDATE SKIP LOCKED.
                $category->increment('sold', $item['quantity']);
            }

            return $order;
        });

        // 2) Process paiement (latence simulée 800-1500 ms — préservée).
        $order = $this->payment->process($order);

        // 3) Déport asynchrone : PDF tickets + mail confirmation.
        // Les jobs s'exécutent dans le worker Horizon (queue Redis).
        // Le client reçoit son 201 dès la fin du paiement.
        foreach ($order->tickets as $ticket) {
            GenerateTicketPdfJob::dispatch($ticket);
        }
        SendOrderConfirmationEmailJob::dispatch($order, $user->email);

        $order = $order->fresh([
            'tickets.ticketCategory',
            'tickets.eventSession.event',
        ]);

        return (new OrderResource($order))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Order $order): OrderResource
    {
        if ($order->user_id !== $request->user()->id) {
            throw new AccessDeniedHttpException('Cette commande ne vous appartient pas.');
        }

        return new OrderResource($order->load([
            'tickets.ticketCategory',
            'tickets.eventSession.event',
        ]));
    }
}
