<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organizer;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * KPIs et courbe de ventes du dashboard organisateur.
 *
 * @perf-fix: chaque KPI est servi depuis Redis (TTL 30 s, tag
 *           `organizer-stats`). Sous polling 10 s, ~2/3 des appels
 *           retombent en cache hit (~ 1 ms vs ~ 200 ms en seq scan).
 *           Invalidation propre depuis les writes organizer events.
 * @perf-debt: aucun index sur orders.paid_at ni orders(user_id, paid_at)
 *             — les agrégations se font en seq scan sur 120k orders au
 *             premier hit. Résolu en solution/j3-postgres atelier
 *             "postgres-indexes".
 */
class StatsController extends Controller
{
    private const TTL = 30;

    public function stats(Request $request): JsonResponse
    {
        $organizerId = $this->organizerId($request);

        $data = Cache::tags(['organizer-stats', "organizer:{$organizerId}"])->remember(
            "stats:organizer:{$organizerId}",
            self::TTL,
            fn () => $this->computeStats($organizerId)
        );

        return response()->json(['data' => $data]);
    }

    public function salesChart(Request $request): JsonResponse
    {
        $organizerId = $this->organizerId($request);

        $rows = Cache::tags(['organizer-stats', "organizer:{$organizerId}"])->remember(
            "sales-chart:organizer:{$organizerId}",
            self::TTL,
            fn () => $this->computeSalesChart($organizerId)
        );

        return response()->json(['data' => $rows]);
    }

    /**
     * @return array{today_orders:int,month_revenue_cents:int,fill_rate:float,active_events:int}
     */
    private function computeStats(int $organizerId): array
    {
        $today = now()->startOfDay();
        $monthStart = now()->startOfMonth();

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

        return [
            'today_orders' => $todayOrdersCount,
            'month_revenue_cents' => (int) $monthRevenueCents,
            'fill_rate' => round((float) $fillRate, 4),
            'active_events' => $activeEvents,
        ];
    }

    /**
     * @return list<array{day:string,orders:int,revenue_cents:int}>
     */
    private function computeSalesChart(int $organizerId): array
    {
        $eventIds = Event::where('organizer_id', $organizerId)->pluck('id');

        // @perf-debt: agrégation par jour sans index sur paid_at, GROUP BY
        // sur 120k orders → seq scan + sort. Résolu en solution/j3-postgres.
        // Cast en array natif (pas Collection<stdClass>) car Redis cache
        // refuse les classes par défaut (cache.serializable_classes=false).
        return DB::table('orders')
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
            ->get()
            ->map(fn ($row) => [
                'day' => (string) $row->day,
                'orders' => (int) $row->orders,
                'revenue_cents' => (int) $row->revenue_cents,
            ])
            ->values()
            ->all();
    }

    private function organizerId(Request $request): int
    {
        $organizer = $request->user()->organizers()->first();

        return $organizer?->id ?? 0;
    }
}
