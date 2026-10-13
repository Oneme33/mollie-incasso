<?php

namespace Lukraak\MollieIncasso;

final class Queue
{
    public function __construct(private readonly Store $store, private readonly Config $config)
    {
    }
    public function put(string $mode, string $kind, string $key, array $payload = [], ?string $at = null): void
    {
        $this->store->query('INSERT INTO ' . $this->store->table('jobs') . ' (mode,kind,dedup,payload,run_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE generation=generation+1,payload=VALUES(payload),run_at=LEAST(run_at,VALUES(run_at)),state=IF(state="working",state,"pending"),attempts=IF(state="working",attempts,0),updated_at=VALUES(updated_at)', [$mode, $kind, $key, wp_json_encode($payload), $at ?? Store::now(), Store::now()]);
    }
    public function work(callable $handler, int $limit = 20): void
    {
        $mode = $this->config->mode();
        $this->config->assertRun($mode);
        $start = microtime(true);
        $now = Store::now();
        $rows = $this->store->select('SELECT * FROM ' . $this->store->table('jobs') . ' WHERE mode=%s AND ((state="pending" AND run_at<=%s) OR (state="working" AND lease_until<%s)) ORDER BY run_at,id LIMIT %d', [$mode, $now, $now, $limit]);
        foreach ($rows as $job) {
            if (microtime(true) - $start > 40) {
                break;
            }
            $lease = bin2hex(random_bytes(16));
            $claimed = $this->store->query('UPDATE ' . $this->store->table('jobs') . ' SET state="working",lease=%s,lease_until=%s WHERE id=%d AND mode=%s AND generation=%d AND ((state="pending" AND run_at<=%s) OR (state="working" AND lease_until<%s))', [$lease, gmdate('Y-m-d H:i:s', time() + 180), (int) $job['id'], $mode, (int) $job['generation'], $now, $now]);
            if (!$claimed) {
                continue;
            }
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
    }
    public function recover(int $id, string $mode): void
    {
        $this->store->update('jobs', ['state' => 'pending', 'attempts' => 0, 'run_at' => Store::now(), 'lease' => null, 'lease_until' => null, 'error' => ''], $id, $mode);
    }
}
