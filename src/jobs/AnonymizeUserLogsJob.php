<?php

declare(strict_types=1);

namespace anvildev\trails\jobs;

use anvildev\trails\Trails;
use Craft;
use craft\queue\BaseJob;
use yii\db\Query;

/**
 * GDPR Article 17 ("right to be forgotten") handler. Walks every non-dropped
 * partition table — active AND archives — and rewrites userName/userEmail
 * (plus any identical username/email PII embedded in the row's JSON
 * `metadata` context, written by listeners such as AuthEventListener and
 * WorkflowEventListener) to the deletion sentinel, recomputing each row's
 * integrity hash so the row's self-check still passes.
 *
 * The hash chain breaks at each rewritten row by design: the row's new hash
 * differs from the original, so the next row's prevHash no longer links.
 * That trade-off is unavoidable with a simple HMAC chain — see DEVELOPER_GUIDE
 * "GDPR & Hash Chain Integrity" for details and the Merkle-anchor mitigation.
 */
class AnonymizeUserLogsJob extends BaseJob
{
    private const CHUNK_SIZE = 500;

    /**
     * Keys that carry the deleted user's own username/email inside a log
     * row's JSON `metadata` context, mapped to the sentinel each is
     * rewritten to. Username-shaped keys use the same '[deleted]' sentinel
     * as the top-level `userName` column; email-shaped keys are nulled out
     * like `userEmail`.
     */
    private const CONTEXT_PII_SENTINELS = [
        'username' => '[deleted]',
        'actorUsername' => '[deleted]',
        'email' => null,
        'actorEmail' => null,
    ];

    public int $userId = 0;

    public function execute($queue): void
    {
        $plugin = Trails::getInstance();
        if ($plugin === null || $this->userId <= 0) {
            return;
        }

        // Pull every non-dropped partition (active + archives). dateFrom/dateTo
        // are null so the rotation service returns all currently-readable
        // tables. Without this, archive rows retain PII forever — a real
        // GDPR violation, not just a known-limitation footnote.
        $tables = $plugin->tableRotation->getTablesForDateRange(null, null);
        if ($tables === []) {
            return;
        }

        $totalProcessed = 0;
        $tableIndex = 0;
        $tableCount = count($tables);

        foreach ($tables as $tableName) {
            $tableIndex++;
            $totalProcessed += $this->anonymizeTable($plugin, $queue, $tableName, $tableIndex, $tableCount);
        }

        Craft::info(
            "Anonymized {$totalProcessed} audit log records across {$tableCount} table(s) for deleted user {$this->userId}",
            'trails'
        );
    }

    private function anonymizeTable(
        Trails $plugin,
        $queue,
        string $tableName,
        int $tableIndex,
        int $tableCount,
    ): int {
        $db = Craft::$app->getDb();
        $lastId = 0;
        $processed = 0;

        while (true) {
            $rows = (new Query())
                ->from($tableName)
                ->where(['userId' => $this->userId])
                ->andWhere(['>', 'id', $lastId])
                ->orderBy(['id' => SORT_ASC])
                ->limit(self::CHUNK_SIZE)
                ->all();

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $newRow = $row;
                $newRow['userName'] = '[deleted]';
                $newRow['userEmail'] = null;
                $newRow['metadata'] = $this->scrubMetadataPii($row['metadata'] ?? null);
                $newHash = $plugin->audit->hashRow($newRow);

                $db->createCommand()->update(
                    $tableName,
                    [
                        'userName' => '[deleted]',
                        'userEmail' => null,
                        'metadata' => $newRow['metadata'],
                        'hash' => $newHash,
                    ],
                    ['id' => $row['id']]
                )->execute();

                $lastId = (int) $row['id'];
                $processed++;
            }

            // Report progress as (completed tables + fraction of current chunk)
            // / total tables. Bounded at 0.99 until the final loop iteration
            // returns and the parent execute() can flag completion.
            $tableProgress = ($tableIndex - 1 + min(1.0, count($rows) / self::CHUNK_SIZE)) / max($tableCount, 1);
            $this->setProgress($queue, min(0.99, $tableProgress));

            if (count($rows) < self::CHUNK_SIZE) {
                break;
            }
        }

        return $processed;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('trails', 'Anonymizing audit logs for deleted user');
    }

    /**
     * Rewrites any of CONTEXT_PII_SENTINELS' keys found in a row's decoded
     * `metadata` JSON to their deletion sentinel. Returns the original
     * string untouched when it's null, isn't a JSON object, or contains
     * none of those keys.
     *
     * @param string|null $metadata The row's raw `metadata` column value.
     */
    private function scrubMetadataPii(?string $metadata): ?string
    {
        if ($metadata === null) {
            return null;
        }

        $decoded = json_decode($metadata, true);
        if (!is_array($decoded)) {
            return $metadata;
        }

        $scrubbed = false;
        foreach (self::CONTEXT_PII_SENTINELS as $key => $sentinel) {
            if (array_key_exists($key, $decoded) && $decoded[$key] !== $sentinel) {
                $decoded[$key] = $sentinel;
                $scrubbed = true;
            }
        }

        if (!$scrubbed) {
            return $metadata;
        }

        return json_encode($decoded, JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
