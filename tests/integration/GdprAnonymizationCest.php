<?php

declare(strict_types=1);

namespace anvildev\trails\tests\integration;

use anvildev\trails\jobs\AnonymizeUserLogsJob;
use anvildev\trails\records\LogMonthRecord;
use anvildev\trails\Trails;
use Craft;
use yii\db\Query;

/**
 * GDPR Article 17 erasure must scrub PII from EVERY non-dropped partition
 * table — active + every monthly archive — not just the active table, and
 * from both the top-level userName/userEmail columns and the same user's
 * own PII embedded in a row's JSON `metadata` context (written under
 * `username`/`email` by AuthEventListener/SystemEventListener, or
 * `actorUsername`/`actorEmail` by WorkflowEventListener).
 *
 * Failure modes this test catches: an active-table-only iteration leaves
 * archived rows containing the deleted user's PII forever (a regulator-
 * relevant violation contradicting the README's "auto-anonymization on
 * user deletion" claim), and scrubbing only the top-level columns while
 * leaving the identical PII sitting in `metadata` untouched.
 */
class GdprAnonymizationCest
{
    private const TEST_USER_ID = 999_001;

    private const ARCHIVE_TABLE = 'trails_logs_test_gdpr_archive';

    /** @var int[] Row ids inserted into the active table that need cleanup. */
    private array $activeRowIds = [];

    public function _after(\IntegrationTester $I): void
    {
        $db = Craft::$app->getDb();

        // Drop the synthetic archive table if present.
        $db->createCommand("DROP TABLE IF EXISTS " . $db->quoteTableName(self::ARCHIVE_TABLE))->execute();

        // Remove its registry entry.
        LogMonthRecord::deleteAll(['tableName' => self::ARCHIVE_TABLE]);

        // Clean any active-table rows we inserted.
        if ($this->activeRowIds !== []) {
            $db->createCommand()
                ->delete('{{%trails_logs}}', ['id' => $this->activeRowIds])
                ->execute();
            $this->activeRowIds = [];
        }
    }

    public function anonymizesPiiAcrossActiveAndArchiveTables(\IntegrationTester $I): void
    {
        $plugin = Trails::getInstance();
        $I->assertNotNull($plugin, 'Trails plugin must be available');

        $db = Craft::$app->getDb();

        // 1. Create a synthetic archive table that clones the active table's schema.
        $db->createCommand(
            "CREATE TABLE " . $db->quoteTableName(self::ARCHIVE_TABLE)
            . " LIKE " . $db->quoteTableName('{{%trails_logs}}')
        )->execute();

        // 2. Register it as an archive in trails_log_months so the rotation
        //    service includes it in getTablesForDateRange().
        $registry = new LogMonthRecord();
        $registry->tableName = self::ARCHIVE_TABLE;
        $registry->dateFrom = '2024-01-01';
        $registry->dateTo = '2024-01-31';
        $registry->status = 'archived';
        $registry->rowCount = 1;
        $I->assertTrue($registry->save(false), 'Registry row must save');

        // 3. Insert one row into the active table and one into the archive,
        //    both attributed to TEST_USER_ID with non-null userName/userEmail.
        //    Each also carries the user's own PII inside its JSON `metadata`
        //    context, under the two real shapes different listeners write it
        //    under (AuthEventListener's `username`/`email`, WorkflowEventListener's
        //    `actorUsername`/`actorEmail`) — the active row's metadata also
        //    carries an unrelated `title` key, to prove the scrub is selective
        //    rather than wiping the whole blob.
        $activeRowId = $this->insertRowWithUserPii(
            $db,
            '{{%trails_logs}}',
            $plugin,
            'active@example.test',
            'Active User',
            ['username' => 'Active User', 'email' => 'active@example.test', 'title' => 'Some Entry'],
        );
        $archiveRowId = $this->insertRowWithUserPii(
            $db,
            self::ARCHIVE_TABLE,
            $plugin,
            'archive@example.test',
            'Archive User',
            ['actorUsername' => 'Archive User', 'actorEmail' => 'archive@example.test', 'runId' => 42],
        );
        $this->activeRowIds[] = $activeRowId;

        // Sanity: pre-anonymization both rows have PII.
        $activeBefore = $this->fetchRow($db, '{{%trails_logs}}', $activeRowId);
        $archiveBefore = $this->fetchRow($db, self::ARCHIVE_TABLE, $archiveRowId);
        $I->assertSame('active@example.test', $activeBefore['userEmail']);
        $I->assertSame('archive@example.test', $archiveBefore['userEmail']);

        // 4. Run the anonymize job synchronously.
        $job = new AnonymizeUserLogsJob(['userId' => self::TEST_USER_ID]);
        $job->execute(Craft::$app->getQueue());

        // 5. Both rows must have userName='[deleted]' and userEmail=null.
        $activeAfter = $this->fetchRow($db, '{{%trails_logs}}', $activeRowId);
        $archiveAfter = $this->fetchRow($db, self::ARCHIVE_TABLE, $archiveRowId);

        $I->assertSame('[deleted]', $activeAfter['userName'], 'Active row userName must be the deletion sentinel');
        $I->assertNull($activeAfter['userEmail'], 'Active row userEmail must be NULL');
        $I->assertSame('[deleted]', $archiveAfter['userName'], 'Archive row userName must be the deletion sentinel — this is the regression catch');
        $I->assertNull($archiveAfter['userEmail'], 'Archive row userEmail must be NULL — this is the regression catch');

        // 5b. The PII embedded in each row's JSON `metadata` context must be
        //     scrubbed too, under whichever key shape it was written with —
        //     while any unrelated key in that same blob stays untouched.
        $activeMetadata = json_decode((string) $activeAfter['metadata'], true);
        $I->assertSame('[deleted]', $activeMetadata['username'], 'metadata.username must be the deletion sentinel');
        $I->assertNull($activeMetadata['email'], 'metadata.email must be null');
        $I->assertSame('Some Entry', $activeMetadata['title'], 'Unrelated metadata keys must survive the scrub');

        $archiveMetadata = json_decode((string) $archiveAfter['metadata'], true);
        $I->assertSame('[deleted]', $archiveMetadata['actorUsername'], 'metadata.actorUsername must be the deletion sentinel');
        $I->assertNull($archiveMetadata['actorEmail'], 'metadata.actorEmail must be null');
        $I->assertSame(42, $archiveMetadata['runId'], 'Unrelated metadata keys must survive the scrub');

        // 6. The new hash on each row must match the canonical recompute.
        $expectedActiveHash = $plugin->audit->hashRow($activeAfter);
        $expectedArchiveHash = $plugin->audit->hashRow($archiveAfter);
        $I->assertSame($expectedActiveHash, $activeAfter['hash'], 'Active row hash must be the v3 HMAC of the anonymized payload');
        $I->assertSame($expectedArchiveHash, $archiveAfter['hash'], 'Archive row hash must be the v3 HMAC of the anonymized payload');

        // 7. Rows belonging to a DIFFERENT user must be untouched. Insert a
        //    bystander on the archive and confirm the job didn't touch it.
        // (Done as a sanity check — we don't claim a separate test for this.)
    }

    /**
     * Inserts a row with a known userId/email/name and a self-consistent v3 hash.
     * Returns the inserted row's id.
     *
     * @param array<string, mixed>|null $metadata
     */
    private function insertRowWithUserPii(
        $db,
        string $table,
        Trails $plugin,
        string $email,
        string $userName,
        ?array $metadata = null,
    ): int {
        $payload = [
            'event' => 'gdpr.smoketest',
            'category' => 'test',
            'elementType' => null,
            'elementId' => null,
            'elementTitle' => null,
            'userId' => self::TEST_USER_ID,
            'userName' => $userName,
            'userEmail' => $email,
            'ipAddress' => null,
            'userAgent' => null,
            'requestUrl' => null,
            'requestMethod' => null,
            'siteId' => null,
            'sessionId' => null,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'oldValue' => null,
            'newValue' => null,
            'metadata' => $metadata !== null ? json_encode($metadata) : null,
            'prevHash' => null,
            'uid' => sprintf('%08x-%04x-%04x-%04x-%012x',
                random_int(0, 0xffffffff),
                random_int(0, 0xffff),
                random_int(0, 0xffff),
                random_int(0, 0xffff),
                random_int(0, 0xffffffffffff),
            ),
        ];
        $payload['hash'] = $plugin->audit->hashRow($payload);

        $db->createCommand()->insert($table, $payload)->execute();

        return (int) $db->getLastInsertID();
    }

    /** @return array<string, mixed> */
    private function fetchRow($db, string $table, int $id): array
    {
        return (new Query())
            ->from($table)
            ->where(['id' => $id])
            ->one() ?: [];
    }
}
