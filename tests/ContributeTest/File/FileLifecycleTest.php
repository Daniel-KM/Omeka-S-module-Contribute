<?php declare(strict_types=1);

namespace ContributeTest\File;

use CommonTest\AbstractHttpControllerTestCase;
use ContributeTest\ContributeTestTrait;

/**
 * End-to-end tests for the lifecycle of the files of contributions.
 *
 * These tests verify that:
 * - The files of a contribution are kept when another contribution is deleted.
 * - The cleaned files are moved to a trash directory, purged after 30 days.
 * - The table contribution_file is synchronized with the proposals.
 * - A validation without flush does not store files inside files/original.
 *
 * @group file
 * @group lifecycle
 */
class FileLifecycleTest extends AbstractHttpControllerTestCase
{
    use ContributeTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        parent::tearDown();
    }

    protected function basePath(): string
    {
        return ($this->getServiceLocator()->get('Config')['file_store']['local']['base_path'] ?? null)
            ?: (OMEKA_PATH . '/files');
    }

    protected function trashFileExists(string $filename): bool
    {
        return file_exists($this->basePath() . '/contribution_trash/' . $filename);
    }

    protected function proposalWithFile(int $templateId, string $filename, string $sourceName, $mediaKey = 0): array
    {
        return [
            'template' => $templateId,
            'media' => [
                $mediaKey => [
                    'file' => [
                        0 => [
                            'original' => ['@value' => null],
                            'proposed' => ['@value' => $sourceName, 'store' => $filename],
                        ],
                    ],
                ],
            ],
            'dcterms:title' => [
                ['original' => ['@value' => null], 'proposed' => ['@value' => 'Lifecycle ' . $sourceName]],
            ],
        ];
    }

    /**
     * The files of a pending contribution must be kept when another
     * contribution is deleted, even when the keys of its medias are not
     * sequential (json object), and even when the files are old.
     */
    public function testDeleteOtherContributionKeepsFiles(): void
    {
        $template = $this->createContributiveTemplate('Lifecycle Keep Test');

        $filenameA = 'lifecycle-a-' . uniqid() . '.txt';
        $filenameB = 'lifecycle-b-' . uniqid() . '.txt';
        $pathA = $this->createTestFile($filenameA, 'Content A');
        $pathB = $this->createTestFile($filenameB, 'Content B');
        // Age the files beyond the one hour grace period of the cleaning, so
        // the protection comes only from the index of the stored files.
        touch($pathA, time() - 7200);
        touch($pathB, time() - 7200);

        // The non-sequential key "1" creates a json object in the proposal.
        $contributionA = $this->createContribution(null, $this->proposalWithFile($template->id(), $filenameA, 'a.txt', 1));
        $contributionB = $this->createContribution(null, $this->proposalWithFile($template->id(), $filenameB, 'b.txt'));

        $this->api()->delete('contributions', $contributionB->id());

        $this->assertTrue(
            $this->contributionFileExists($filenameA),
            'The file of the remaining contribution should be kept'
        );
        $this->assertFalse(
            $this->contributionFileExists($filenameB),
            'The file of the deleted contribution should be removed from files/contribution'
        );
        $this->assertTrue(
            $this->trashFileExists($filenameB),
            'The file of the deleted contribution should be moved to the trash'
        );
        $this->assertNotEmpty($contributionA->id());
    }

    /**
     * The trashed files must be purged after 30 days and kept before.
     */
    public function testTrashPurgeAfter30Days(): void
    {
        $template = $this->createContributiveTemplate('Lifecycle Purge Test');

        $trashPath = $this->basePath() . '/contribution_trash';
        if (!is_dir($trashPath)) {
            mkdir($trashPath, 0775, true);
        }
        $oldTrashed = 'lifecycle-old-' . uniqid() . '.txt';
        $recentTrashed = 'lifecycle-recent-' . uniqid() . '.txt';
        file_put_contents($trashPath . '/' . $oldTrashed, 'Old');
        file_put_contents($trashPath . '/' . $recentTrashed, 'Recent');
        touch($trashPath . '/' . $oldTrashed, time() - 31 * 86400);

        // Any deletion of a contribution triggers the cleaning and the purge.
        $filename = 'lifecycle-purge-' . uniqid() . '.txt';
        $this->createTestFile($filename, 'Purge');
        $contribution = $this->createContribution(null, $this->proposalWithFile($template->id(), $filename, 'p.txt'));
        $this->api()->delete('contributions', $contribution->id());

        $this->assertFalse(
            $this->trashFileExists($oldTrashed),
            'A file trashed more than 30 days ago should be purged'
        );
        $this->assertTrue(
            $this->trashFileExists($recentTrashed),
            'A file trashed recently should be kept'
        );

        @unlink($trashPath . '/' . $recentTrashed);
    }

    /**
     * A trashed file must not be purged nor trashed while it is attached to a
     * contribution: only the files not attached to a contribution are trashed
     * and purged.
     */
    public function testAttachedFileIsNeverTrashedNorPurged(): void
    {
        $template = $this->createContributiveTemplate('Lifecycle Attached Test');

        $trashPath = $this->basePath() . '/contribution_trash';
        if (!is_dir($trashPath)) {
            mkdir($trashPath, 0775, true);
        }

        // A file attached to a contribution, but also present and old in the
        // trash and in the contribution directory (simulates a re-attachment).
        $attached = 'lifecycle-attached-' . uniqid() . '.txt';
        $this->createTestFile($attached, 'Attached');
        $contributionAttached = $this->createContribution(null, $this->proposalWithFile($template->id(), $attached, 'attached.txt'));
        file_put_contents($trashPath . '/' . $attached, 'Attached');
        touch($trashPath . '/' . $attached, time() - 31 * 86400);
        touch($this->basePath() . '/contribution/' . $attached, time() - 7200);

        // Trigger the cleaning and the purge by deleting another contribution.
        $other = 'lifecycle-other-' . uniqid() . '.txt';
        $this->createTestFile($other, 'Other');
        $contributionOther = $this->createContribution(null, $this->proposalWithFile($template->id(), $other, 'other.txt'));
        $this->api()->delete('contributions', $contributionOther->id());

        $this->assertTrue(
            $this->contributionFileExists($attached),
            'An attached file must not be trashed even when old'
        );
        $this->assertTrue(
            $this->trashFileExists($attached),
            'An attached file present in the trash must not be purged'
        );
        $this->assertNotEmpty($contributionAttached->id());

        @unlink($trashPath . '/' . $attached);
    }

    /**
     * The table contribution_file must be synchronized on create, update and
     * delete of a contribution.
     */
    public function testContributionFileTableSync(): void
    {
        $template = $this->createContributiveTemplate('Lifecycle Sync Test');

        $filename = 'lifecycle-sync-' . uniqid() . '.txt';
        $content = 'Sync content';
        $this->createTestFile($filename, $content);

        $contribution = $this->createContribution(null, $this->proposalWithFile($template->id(), $filename, 'sync.txt'));

        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $row = $connection->executeQuery(
            'SELECT * FROM `contribution_file` WHERE `contribution_id` = :id',
            ['id' => $contribution->id()]
        )->fetchAssociative();

        $this->assertNotFalse($row, 'A row should be created for the stored file');
        $this->assertEquals($filename, $row['store']);
        $this->assertEquals('sync.txt', $row['source_name']);
        $this->assertEquals(strlen($content), (int) $row['size']);
        $this->assertEquals(hash('sha256', $content), $row['sha256']);

        // Update the contribution without the file: the row should be removed.
        $proposal = $this->proposalWithFile($template->id(), $filename, 'sync.txt');
        $proposal['media'] = [];
        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:proposal' => $proposal,
        ], [], ['isPartial' => true]);

        $count = $connection->executeQuery(
            'SELECT COUNT(*) FROM `contribution_file` WHERE `contribution_id` = :id',
            ['id' => $contribution->id()]
        )->fetchOne();
        $this->assertEquals(0, (int) $count, 'The rows should be removed when the file is removed from the proposal');

        // Delete the contribution: the rows should be deleted in cascade.
        $filename2 = 'lifecycle-sync-2-' . uniqid() . '.txt';
        $this->createTestFile($filename2, 'Sync 2');
        $contribution2 = $this->createContribution(null, $this->proposalWithFile($template->id(), $filename2, 'sync2.txt'));
        $contribution2Id = $contribution2->id();
        $this->api()->delete('contributions', $contribution2Id);
        $count = $connection->executeQuery(
            'SELECT COUNT(*) FROM `contribution_file` WHERE `contribution_id` = :id',
            ['id' => $contribution2Id]
        )->fetchOne();
        $this->assertEquals(0, (int) $count, 'The rows should be deleted in cascade with the contribution');
    }

    /**
     * An original filename with special characters (accents, quotes, cjk,
     * emoji, spaces) must round-trip exactly in the table contribution_file,
     * and a very long name must be truncated on characters, not bytes.
     */
    public function testSpecialCharactersInSourceName(): void
    {
        $template = $this->createContributiveTemplate('Lifecycle Charset Test');

        $filename = 'lifecycle-charset-' . uniqid() . '.txt';
        $this->createTestFile($filename, 'Charset');

        // The store name is always a random hex plus a lowercased ascii
        // extension, so the special characters only live in the source name.
        $sourceName = 'Résumé — «présence» de l’élève, 日本語 & <b>tag</b> 😀.PDF';
        $contribution = $this->createContribution(null, $this->proposalWithFile($template->id(), $filename, $sourceName));

        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $row = $connection->executeQuery(
            'SELECT * FROM `contribution_file` WHERE `contribution_id` = :id',
            ['id' => $contribution->id()]
        )->fetchAssociative();

        $this->assertNotFalse($row, 'A row should be created');
        $this->assertSame(
            $sourceName,
            $row['source_name'],
            'The special characters of the source name must round-trip byte for byte'
        );
        $this->assertSame($filename, $row['store'], 'The store name must stay ascii and unchanged');

        // A very long multibyte name must be truncated on characters (max 1000)
        // without cutting a multibyte character.
        $longName = str_repeat('é', 1500) . '.pdf';
        $filename2 = 'lifecycle-charset-2-' . uniqid() . '.txt';
        $this->createTestFile($filename2, 'Charset 2');
        $contribution2 = $this->createContribution(null, $this->proposalWithFile($template->id(), $filename2, $longName));

        $stored = $connection->executeQuery(
            'SELECT `source_name` FROM `contribution_file` WHERE `contribution_id` = :id',
            ['id' => $contribution2->id()]
        )->fetchOne();

        $this->assertLessThanOrEqual(1000, mb_strlen($stored, 'UTF-8'), 'The name must be truncated to 1000 characters');
        $this->assertSame(
            $stored,
            mb_convert_encoding($stored, 'UTF-8', 'UTF-8'),
            'The truncated name must remain valid UTF-8 (no multibyte character cut)'
        );
    }

    /**
     * The validation of a contribution on submission (validate only, without
     * flush) must not store the original file nor the thumbnails.
     */
    public function testValidateOnlyDoesNotStoreOriginal(): void
    {
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');

        $filename = 'lifecycle-validate-' . uniqid() . '.txt';
        $this->createTestFile($filename, 'Validate only');

        $originalPath = $this->basePath() . '/original';
        $before = is_dir($originalPath) ? scandir($originalPath) : [];

        // Same api call as ContributionTrait::validateOrCreateOrUpdate() on
        // submission, with the media marked "validateOnly".
        $resourceData = [
            'o:is_public' => false,
            'dcterms:title' => [
                [
                    'type' => 'literal',
                    'property_id' => 1,
                    '@value' => 'Validate Only Test',
                ],
            ],
            'o:media' => [
                [
                    'o:ingester' => 'contribution',
                    'o:source' => 'validate.txt',
                    'store' => $filename,
                    'validateOnly' => true,
                    'dcterms:title' => [
                        [
                            'type' => 'literal',
                            'property_id' => 1,
                            '@value' => 'Validate Only Media',
                        ],
                    ],
                ],
            ],
        ];

        try {
            $this->api(null, true)->create('items', $resourceData, [], [
                'flushEntityManager' => false,
                'validateOnly' => true,
                'isContribution' => true,
            ]);
        } catch (\Omeka\Api\Exception\ValidationException $e) {
            // The exception is the expected way to end a validation without
            // flush: the error "validateOnly" means there is no other error.
            $errors = $e->getErrorStore()->getErrors();
            $this->assertArrayHasKey('validateOnly', $errors, 'The validation should end with the marker error only');
        } finally {
            $entityManager->clear();
        }

        $after = is_dir($originalPath) ? scandir($originalPath) : [];
        $this->assertEquals(
            $before,
            $after,
            'No file should be stored inside files/original during a validation without flush'
        );
        $this->assertTrue(
            $this->contributionFileExists($filename),
            'The file should be kept inside files/contribution'
        );
    }
}
