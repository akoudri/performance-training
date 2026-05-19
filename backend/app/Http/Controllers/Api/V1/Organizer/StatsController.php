<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organizer;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * KPIs et courbe de ventes du dashboard organisateur.
 *
 * @perf-debt: aucun cache (Cache::remember) — chaque polling 10s frappe
 *             la base. Résolu en J3 atelier "laravel-redis-cache" avec
 *             tags `organizer:{id}` et TTL 30s.
 * @perf-debt: aucun index sur orders.paid_at ni orders(user_id, paid_at)
 *             — les 4 agrégations se font en seq scan sur 120k orders.
 *             Résolu en J3 atelier "postgres-indexes".
 */
class StatsController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $organizerId = $this->organizerId($request);

        // Période : ventes du jour, revenus du mois.
        $today = now()->startOfDay();
        $monthStart = now()->startOfMonth();

        // @perf-debt: 4 requêtes séparées + JOINs lourds sans index.
        $eventIds = Event::where('organizer_id', $organizerId)->pluck('id');

        $todayOrdersCount = Order::query()
            ->where('status', Order::STATUS_PAID)
            ->where('paid_at', '>=', $today)
            ->whereIn('id', function ($q) use ($eventIds) {
                $q->select('order_id')->from('tickets')
                    ->whereIn('event_session_id', function ($sq) use ($eventIds) {
                        $sq->select('id')->from('event_sessions')->whereIn('event_id', $eventIds);
                    });
            })
            ->count();

        $monthRevenueCents = Order::query()
            ->where('status', Order::STATUS_PAID)
            ->where('paid_at', '>=', $monthStart)
            ->whereIn('id', function ($q) use ($eventIds) {
                $q->select('order_id')->from('tickets')
                    ->whereIn('event_session_id', function ($sq) use ($eventIds) {
                        $sq->select('id')->from('event_sessions')->whereIn('event_id', $eventIds);
                    });
            })
            ->sum('total_cents');

        $fillRate = DB::table('ticket_categories')
            ->joinSub(
                DB::table('event_sessions')->whereIn('event_id', $eventIds)->select('id'),
                'es',
                'es.id',
                '=',
                'ticket_categories.event_session_id',
            )
            ->selectRaw('COALESCE(SUM(sold)::float / NULLIF(SUM(quota), 0), 0) AS rate')
            ->value('rate');

        $activeEvents = Event::where('organizer_id', $organizerId)
            ->where('status', Event::STATUS_PUBLISHED)
            ->count();

        return response()->json([
            'data' => [
                'today_orders' => $todayOrdersCount,
                'month_revenue_cents' => (int) $monthRevenueCents,
                'fill_rate' => round((float) $fillRate, 4),
                'active_events' => $activeEvents,
            ],
        ]);
    }

    public function salesChart(Request $request): JsonResponse
    {
        $organizerId = $this->organizerId($request);
        $eventIds = Event::where('organizer_id', $organizerId)->pluck('id');

        // @perf-debt: agrégation par jour sans index sur paid_at, GROUP BY
        // sur 120k orders → seq scan + sort. Résolu en J3.
        $rows = DB::table('orders')
            ->selectRaw("DATE_TRUNC('day', paid_at) AS day, COUNT(*) AS orders, SUM(total_cents) AS revenue_cents")
            ->where('status', Order::STATUS_PAID)
            ->where('paid_at', '>=', now()->subDays(30))
            ->whereIn('id', function ($q) use ($eventIds) {
                $q->select('order_id')->from('tickets')
                    ->whereIn('event_session_id', function ($sq) use ($eventIds) {
                        $sq->select('id')->from('event_sessions')->whereIn('event_id', $eventIds);
                    });
            })
            ->groupByRaw("DATE_TRUNC('day', paid_at)")
            ->orderBy('day')
            ->get();

        return response()->json(['data' => $rows]);
    }

    private function organizerId(Request $request): int
    {
        $organizer = $request->user()->organizers()->first();

        return $organizer?->id ?? 0;
    }
}
