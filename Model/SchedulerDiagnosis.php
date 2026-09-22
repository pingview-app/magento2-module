<?php
declare(strict_types=1);

namespace PingView\Monitoring\Model;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * The free, local half of scheduler-watch (CT-PARITY): is Magento's own cron
 * still running?
 *
 * A dead cron is invisible to every external probe. The storefront keeps
 * answering 200 while indexers stop reindexing, orders stop being emailed and
 * scheduled prices never go live. Nothing outside the store can see it, which
 * is exactly why the plugin - which is inside - reports it.
 *
 * Read-only and defensive: a missing table or a locked database must leave the
 * panel intact, so every failure answers "unknown" instead of throwing.
 */
final class SchedulerDiagnosis
{
    /**
     * How stale the newest cron row may be before the scheduler counts as
     * stopped. Magento's default `cron_run` group ticks every minute and the
     * default schedule_ahead_for is 4 hours, so nothing newer than 1 hour old
     * means the generator itself has not run.
     */
    private const STALE_AFTER_SECONDS = 3600;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{
     *     known: bool,
     *     running: bool,
     *     pending: int,
     *     last_executed_at: string|null,
     *     overdue: bool
     * }
     */
    public function describe(): array
    {
        $unknown = [
            'known' => false,
            'running' => false,
            'pending' => 0,
            'last_executed_at' => null,
            'overdue' => false,
        ];

        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('cron_schedule');
            if (!$connection->isTableExists($table)) {
                return $unknown;
            }

            $pending = (int)$connection->fetchOne(
                $connection->select()->from($table, 'COUNT(*)')->where('status = ?', 'pending')
            );

            $lastExecuted = $connection->fetchOne(
                $connection->select()
                    ->from($table, 'executed_at')
                    ->where('executed_at IS NOT NULL')
                    ->order('executed_at DESC')
                    ->limit(1)
            );

            $lastExecuted = is_string($lastExecuted) && $lastExecuted !== '' ? $lastExecuted : null;
            $age = $lastExecuted === null ? null : time() - (int)strtotime($lastExecuted . ' UTC');

            return [
                'known' => true,
                // A job executed recently is the only positive proof. Pending
                // rows alone prove the generator ran, not the runner.
                'running' => $age !== null && $age <= self::STALE_AFTER_SECONDS,
                'pending' => $pending,
                'last_executed_at' => $lastExecuted,
                'overdue' => $age !== null && $age > self::STALE_AFTER_SECONDS,
            ];
        } catch (\Throwable $error) {
            $this->logger->debug('PingView could not read cron_schedule', ['exception' => $error]);
            return $unknown;
        }
    }
}
