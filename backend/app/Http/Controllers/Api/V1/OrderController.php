<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Services\PaymentMockService;
use App\Services\TicketPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Commandes : tunnel d'achat 100 % synchrone (starter).
 *
 * Flow étape 11 :
 *   1. Validation
 *   2. Transaction : création Order pending + Tickets, increment sold
 *   3. PaymentMockService::process — usleep 800-1500ms, marque paid
 *   4. Pour chaque ticket : TicketPdfService::generate (dompdf inline + upload MinIO)
 *   5. Mail::to($user)->send(new OrderConfirmationMail) — SMTP Mailpit synchrone
 *
 * Coût attendu UX : ~3-5s entre POST et 201, conformément à spec §8.
 *
 * @perf-debt: latence simulée bloquante (800-1500ms) — préservée en final
 *             pour réalisme PSP, mais le reste du flow passe en queue.
 * @perf-debt: dompdf inline (200-500 ms / ticket) — résolu J3 par
 *             GenerateTicketPdfJob ShouldQueue.
 * @perf-debt: SMTP synchrone bloquant — résolu J3 par
 *             SendOrderConfirmationEmailJob ShouldQueue.
 * @perf-debt: pas de SELECT FOR UPDATE SKIP LOCKED → race conditions
 *             possibles sur ticket_categories.sold en haute charge.
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly PaymentMockService $payment,
        private readonly TicketPdfService $pdf,
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

        // 2) Process paiement (latence simulée 800-1500ms).
        $order = $this->payment->process($order);

        // 3) Génération PDF synchrone pour chaque ticket.
        // @perf-debt: 200-500 ms × N tickets, en série, dans le thread HTTP.
        foreach ($order->tickets as $ticket) {
            $this->pdf->generate($ticket);
        }

        // 4) Envoi email confirmation synchrone (Mail::send PAS Mail::queue).
        // @perf-debt: SMTP bloquant. Volontairement Mail::send (cf. §8 starter).
        Mail::to($user->email)->send(new OrderConfirmationMail($order));

        return (new OrderResource($order->fresh('tickets')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Order $order): OrderResource
    {
        if ($order->user_id !== $request->user()->id) {
            throw new AccessDeniedHttpException('Cette commande ne vous appartient pas.');
        }

        return new OrderResource($order->load('tickets'));
    }
}
