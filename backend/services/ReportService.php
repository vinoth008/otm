<?php
declare(strict_types=1);

/**
 * ReportService.php — Financial report generation using MongoDB aggregations
 */

require_once __DIR__ . '/../config/database.php';

class ReportService
{
    /** Generate a complete financial summary for a given period */
    public static function getSummary(string $userId, string $period = 'month'): array
    {
        [$from, $to] = self::periodDates($period);

        try {
            $txCol = getCollection('transactions');
            $exCol = getCollection('expenses');
            if (!$txCol) return [];

            $txMatch = ['user_id' => new MongoDB\BSON\ObjectId($userId), 'deleted_at' => null, 'date' => ['$gte' => $from, '$lte' => $to]];

            // Income
            $income = (float)self::aggregateSum($txCol, array_merge($txMatch, ['type' => 'income']));
            // Expenses from transactions
            $txExpense = (float)self::aggregateSum($txCol, array_merge($txMatch, ['type' => 'expense']));
            // Expenses from expense collection
            $exMatch = ['user_id' => new MongoDB\BSON\ObjectId($userId), 'deleted_at' => null, 'date' => ['$gte' => $from, '$lte' => $to]];
            $directExpense = $exCol ? (float)self::aggregateSum($exCol, $exMatch) : 0.0;

            $totalExpense = $txExpense + $directExpense;
            $net = $income - $totalExpense;

            // Category breakdown
            $catBreakdown = self::categoryBreakdown($txCol, $txMatch);

            return [
                'period'          => $period,
                'from'            => $from,
                'to'              => $to,
                'total_income'    => round($income, 2),
                'total_expense'   => round($totalExpense, 2),
                'net'             => round($net, 2),
                'savings_rate'    => $income > 0 ? round((($income - $totalExpense) / $income) * 100, 1) : 0,
                'category_breakdown' => $catBreakdown,
            ];
        } catch (\Exception $e) {
            error_log('[ReportService] getSummary: ' . $e->getMessage());
            return [];
        }
    }

    /** Monthly trend for income and expense over last N months */
    public static function monthlyTrend(string $userId, int $months = 6): array
    {
        try {
            $collection = getCollection('transactions');
            if (!$collection) return [];

            $since = date('Y-m-d', strtotime("-{$months} months"));
            $pipeline = [
                ['$match' => ['user_id' => new MongoDB\BSON\ObjectId($userId), 'deleted_at' => null, 'date' => ['$gte' => $since], 'type' => ['$in' => ['income', 'expense']]]],
                ['$group' => ['_id' => ['month' => ['$substr' => ['$date', 0, 7]], 'type' => '$type'], 'total' => ['$sum' => '$amount']]],
                ['$sort'  => ['_id.month' => 1]],
            ];

            $raw = $collection->aggregate($pipeline)->toArray();
            $grouped = [];
            foreach ($raw as $doc) {
                $month = (string)$doc['_id']['month'];
                $type  = (string)$doc['_id']['type'];
                $grouped[$month][$type] = (float)$doc['total'];
            }

            $result = [];
            foreach ($grouped as $month => $data) {
                $result[] = [
                    'month'   => $month,
                    'income'  => $data['income'] ?? 0.0,
                    'expense' => $data['expense'] ?? 0.0,
                ];
            }
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    /** Top spending categories */
    public static function topCategories(string $userId, int $topN = 5, string $period = 'month'): array
    {
        [$from, $to] = self::periodDates($period);
        try {
            $collection = getCollection('transactions');
            if (!$collection) return [];

            $pipeline = [
                ['$match' => ['user_id' => new MongoDB\BSON\ObjectId($userId), 'type' => 'expense', 'deleted_at' => null, 'date' => ['$gte' => $from, '$lte' => $to]]],
                ['$group' => ['_id' => '$category', 'total' => ['$sum' => '$amount'], 'count' => ['$sum' => 1]]],
                ['$sort'  => ['total' => -1]],
                ['$limit' => $topN],
            ];

            $result = [];
            foreach ($collection->aggregate($pipeline)->toArray() as $doc) {
                $result[] = ['category' => (string)$doc['_id'], 'total' => (float)$doc['total'], 'count' => (int)$doc['count']];
            }
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    /** Daily transaction totals for a month */
    public static function dailyTotals(string $userId, string $month = null): array
    {
        $monthStr = $month ?? date('Y-m');
        $from     = $monthStr . '-01';
        $to       = date('Y-m-t', strtotime($from));

        try {
            $collection = getCollection('transactions');
            if (!$collection) return [];

            $pipeline = [
                ['$match' => ['user_id' => new MongoDB\BSON\ObjectId($userId), 'deleted_at' => null, 'date' => ['$gte' => $from, '$lte' => $to]]],
                ['$group' => ['_id' => ['date' => '$date', 'type' => '$type'], 'total' => ['$sum' => '$amount']]],
                ['$sort'  => ['_id.date' => 1]],
            ];

            $result = [];
            foreach ($collection->aggregate($pipeline)->toArray() as $doc) {
                $date = (string)$doc['_id']['date'];
                $type = (string)$doc['_id']['type'];
                if (!isset($result[$date])) $result[$date] = ['date' => $date, 'income' => 0.0, 'expense' => 0.0];
                $result[$date][$type] = (float)$doc['total'];
            }
            return array_values($result);
        } catch (\Exception $e) {
            return [];
        }
    }

    /** Wallet balance history snapshot */
    public static function walletBalances(string $userId): array
    {
        try {
            $collection = getCollection('wallets');
            if (!$collection) return [];

            $cursor = $collection->find(['user_id' => new MongoDB\BSON\ObjectId($userId), 'deleted_at' => null]);
            $result = [];
            foreach ($cursor as $doc) {
                $result[] = ['wallet' => $doc['name'] ?? '', 'balance' => (float)($doc['balance'] ?? 0), 'currency' => $doc['currency'] ?? 'INR'];
            }
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    // ── Helpers ──────────────────────────────────────────────────

    private static function aggregateSum($collection, array $match): float
    {
        $pipeline = [
            ['$match' => $match],
            ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount']]],
        ];
        $result = $collection->aggregate($pipeline)->toArray();
        return isset($result[0]) ? (float)$result[0]['total'] : 0.0;
    }

    private static function categoryBreakdown($collection, array $match): array
    {
        $pipeline = [
            ['$match' => array_merge($match, ['type' => 'expense'])],
            ['$group' => ['_id' => '$category', 'total' => ['$sum' => '$amount']]],
            ['$sort'  => ['total' => -1]],
        ];
        $result = [];
        foreach ($collection->aggregate($pipeline)->toArray() as $doc) {
            $result[] = ['category' => (string)$doc['_id'], 'total' => (float)$doc['total']];
        }
        return $result;
    }

    private static function periodDates(string $period): array
    {
        return match ($period) {
            'week'    => [date('Y-m-d', strtotime('-7 days')), date('Y-m-d')],
            'month'   => [date('Y-m-01'), date('Y-m-d')],
            'quarter' => [date('Y-m-01', strtotime('-3 months')), date('Y-m-d')],
            'year'    => [date('Y-01-01'), date('Y-m-d')],
            default   => [date('Y-m-01'), date('Y-m-d')],
        };
    }
}
