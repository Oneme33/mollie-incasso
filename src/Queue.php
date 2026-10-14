<?php

namespace Lukraak\MollieIncasso;

final class Queue
{
    /** Lower runs first: customer-facing and financial work before background polling. */
    public const URGENT = 1;
    public const NORMAL = 5;
    public const BACKGROUND = 9;
    public function __construct(private readonly Store $store, private readonly Config $config)
    {
    }
    public static function priority(string $kind): int
    {
        return match ($kind) {
            'command', 'mail', 'payment', 'withdraw', 'stop', 'checkout', 'auto_subscription' => self::URGENT,
            default => self::NORMAL,
        };
    }
    /**
     * Polling ($revive = false) never resurrects a failed job; only a new event or an admin recovery does.
     * MySQL applies ON DUPLICATE assignments left to right, so priority is decided before state changes.
     */
    public function put(string $mode, string $kind, string $key, array $payload = [], ?string $at = null, bool $revive = true): void
    {
        $priority = $revive ? self::priority($kind) : self::BACKGROUND;
        $keep = $revive ? '("working")' : '("working","failed")';
        $this->store->query('INSERT INTO ' . $this->store->table('jobs') . ' (mode,kind,dedup,payload,priority,run_at,updated_at) VALUES (%s,%s,%s,%s,%d,%s,%s) ON DUPLICATE KEY UPDATE generation=IF(state IN ' . $keep . ' AND state!="working",generation,generation+1),priority=IF(state IN ("done","failed"),VALUES(priority),LEAST(priority,VALUES(priority))),payload=VALUES(payload),run_at=IF(state IN ' . $keep . ' AND state!="working",run_at,LEAST(run_at,VALUES(run_at))),attempts=IF(state IN ' . $keep . ',attempts,0),state=IF(state IN ' . $keep . ',state,"pending"),updated_at=VALUES(updated_at)', [$mode, $kind, $key, wp_json_encode($payload), $priority, $at ?? Store::now(), Store::now()]);
    }
    /** Seconds a run may spend, leaving room below PHP's own execution limit. */
    public static function budget(): int
    {
        $max = (int) ini_get('max_execution_time');
        return $max > 0 ? max(5, min(40, $max - 10)) : 40;
    }
    public function work(callable $handler, int $limit = 20, ?int $budget = null, int $maxJobs = 400): int
    {
        $mode = $this->config->mode();
        $this->config->assertRun($mode);
        $budget ??= self::budget();
        $start = microtime(true);
        $done = 0;
        do {
            $now = Store::now();
            $rows = $this->store->select('SELECT * FROM ' . $this->store->table('jobs') . ' WHERE mode=%s AND ((state="pending" AND run_at<=%s) OR (state="working" AND lease_until<%s)) ORDER BY priority,run_at,id LIMIT %d', [$mode, $now, $now, $limit]);
            $claimedAny = false;
            foreach ($rows as $job) {
                if (microtime(true) - $start > $budget || $done >= $maxJobs) {
                    return $done;
                }
                $lease = bin2hex(random_bytes(16));
                $claimed = $this->store->query('UPDATE ' . $this->store->table('jobs') . ' SET state="working",lease=%s,lease_until=%s WHERE id=%d AND mode=%s AND generation=%d AND ((state="pending" AND run_at<=%s) OR (state="working" AND lease_until<%s))', [$lease, gmdate('Y-m-d H:i:s', time() + 180), (int) $job['id'], $mode, (int) $job['generation'], $now, $now]);
                if (!$claimed) {
                    continue;
                }
                $claimedAny = true;
                ++$done;
                try {
                    $handler($job['kind'], json_decode($job['payload'], true), $mode);
                    $this->store->query('UPDATE ' . $this->store->table('jobs') . ' SET state=IF(generation=%d,"done","pending"),lease=NULL,lease_until=NULL,error="",updated_at=%s WHERE id=%d AND lease=%s', [(int) $job['generation'], Store::now(), (int) $job['id'], $lease]);
                } catch (\Throwable $e) {
                    $reason = $e instanceof Failure ? $e->reason : 'internal_error';
                    $tries = (int) $job['attempts'] + 1;
                    $state = $tries >= 8 ? 'failed' : 'pending';
                    $delay = min(3600, 30 * 2 ** min($tries, 7));
                    $this->store->query('UPDATE ' . $this->store->table('jobs') . ' SET state=IF(generation=%d,%s,"pending"),attempts=IF(generation=%d,%d,0),run_at=IF(generation=%d,%s,LEAST(run_at,%s)),lease=NULL,lease_until=NULL,error=%s,updated_at=%s WHERE id=%d AND lease=%s', [(int) $job['generation'], $state, (int) $job['generation'], $tries, (int) $job['generation'], gmdate('Y-m-d H:i:s', time() + $delay), Store::now(), $reason, Store::now(), (int) $job['id'], $lease]);
                }
            }
        } while ($claimedAny && count($rows) === $limit && microtime(true) - $start <= $budget && $done < $maxJobs);
        return $done;
    }
    public function recover(int $id, string $mode): void
    {
        $this->store->update('jobs', ['state' => 'pending', 'attempts' => 0, 'run_at' => Store::now(), 'lease' => null, 'lease_until' => null, 'error' => ''], $id, $mode);
    }
}
