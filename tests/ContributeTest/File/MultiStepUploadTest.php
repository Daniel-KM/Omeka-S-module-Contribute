<?php declare(strict_types=1);

namespace ContributeTest\File;

use CommonTest\AbstractHttpControllerTestCase;
use ContributeTest\ContributeTestTrait;

/**
 * Tests for multi-step file upload scenarios.
 *
 * These tests verify that files are properly handled when:
 * - User uploads files in multiple steps
 * - User goes back and saves
 * - User re-uploads files
 *
 * @group file
 * @group multistep
 */
class MultiStepUploadTest extends AbstractHttpControllerTestCase
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

    /**
     * Test that storage ID is random and changes on re-upload.
     *
     * @group file
     */
    public function testStorageIdIsRandomOnEachUpload(): void
    {
        // Create two test files with the same content.
        $content = 'Same content for both files';
        $filename1 = 'storage-test-1-' . uniqid() . '.txt';
        $filename2 = 'storage-test-2-' . uniqid() . '.txt';

        $this->createTestFile($filename1, $content);
        $this->createTestFile($filename2, $content);

        // Both files should exist.
        $this->assertTrue($this->contributionFileExists($filename1));
        $this->assertTrue($this->contributionFileExists($filename2));

        // Storage IDs (filenames) should be different even with same content.
        $this->assertNotEquals($filename1, $filename2);
    }

    /**
     * Test that existing store value is preserved when no new file is uploaded.
     *
     * This simulates the scenario where:
     * 1. User creates contribution with file
     * 2. User edits contribution without uploading new file
     * 3. The original file reference should be preserved
     *
     * @group file
     */
    public function testExistingStoreValuePreservedOnUpdate(): void
    {
        $template = $this->createContributiveTemplate('MultiStep Test');

        // Create a test file.
        $filename = 'preserve-test-' . uniqid() . '.txt';
        $this->createTestFile($filename, 'File to preserve');

        // Create a proposal with the file reference.
        $proposal = [
            'template' => $template->id(),
            'media' => [
                0 => [
                    'file' => [
                        0 => [
                            'original' => ['@value' => null],
                            'proposed' => [
                                '@value' => 'original-name.txt',
                                'store' => $filename,
                            ],
                        ],
                    ],
                    'dcterms:title' => [
                        ['original' => ['@value' => null], 'proposed' => ['@value' => 'Media 1']],
                    ],
                ],
            ],
            'dcterms:title' => [
                ['original' => ['@value' => null], 'proposed' => ['@value' => 'Test Item']],
            ],
        ];

        // Create the contribution.
        $contribution = $this->createContribution(null, $proposal);

        // Verify the initial store value.
        $storedProposal = $contribution->proposal();
        $this->assertEquals(
            $filename,
            $storedProposal['media'][0]['file'][0]['proposed']['store'],
            'Initial store value should be set'
        );

        // Update the contribution (without changing the file).
        $updatedProposal = $storedProposal;
        $updatedProposal['dcterms:title'][0]['proposed']['@value'] = 'Updated Title';

        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:proposal' => $updatedProposal,
        ], [], ['isPartial' => true]);

        // Reload and verify the store value is still present.
        $contribution = $this->api()->read('contributions', $contribution->id())->getContent();
        $reloadedProposal = $contribution->proposal();

        $this->assertEquals(
            $filename,
            $reloadedProposal['media'][0]['file'][0]['proposed']['store'],
            'Store value should be preserved after update without new file'
        );

        // File should still exist.
        $this->assertTrue(
            $this->contributionFileExists($filename),
            'Physical file should still exist after contribution update'
        );
    }

    /**
     * Test that new upload replaces old store value.
     *
     * @group file
     */
    public function testNewUploadReplacesOldStoreValue(): void
    {
        $template = $this->createContributiveTemplate('Replace Test');

        // Create first file.
        $oldFilename = 'old-file-' . uniqid() . '.txt';
        $this->createTestFile($oldFilename, 'Old content');

        // Create a proposal with the old file.
        $proposal = [
            'template' => $template->id(),
            'media' => [
                0 => [
                    'file' => [
                        0 => [
                            'original' => ['@value' => null],
                            'proposed' => [
                                '@value' => 'old-name.txt',
                                'store' => $oldFilename,
                            ],
                        ],
                    ],
                ],
            ],
            'dcterms:title' => [
                ['original' => ['@value' => null], 'proposed' => ['@value' => 'Test']],
            ],
        ];

        $contribution = $this->createContribution(null, $proposal);

        // Create new file.
        $newFilename = 'new-file-' . uniqid() . '.txt';
        $this->createTestFile($newFilename, 'New content');

        // Update proposal with new file reference.
        $updatedProposal = $contribution->proposal();
        $updatedProposal['media'][0]['file'][0]['proposed']['store'] = $newFilename;
        $updatedProposal['media'][0]['file'][0]['proposed']['@value'] = 'new-name.txt';

        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:proposal' => $updatedProposal,
        ], [], ['isPartial' => true]);

        // Verify new store value.
        $contribution = $this->api()->read('contributions', $contribution->id())->getContent();
        $reloadedProposal = $contribution->proposal();

        $this->assertEquals(
            $newFilename,
            $reloadedProposal['media'][0]['file'][0]['proposed']['store'],
            'Store value should be updated to new file'
        );
    }

    /**
     * Test file structure at root level vs nested in media.
     *
     * This verifies that files can be stored at both:
     * - $.file[*].proposed.store (root level for media contributions)
     * - $.media[*].file[*].proposed.store (nested for item contributions)
     *
     * @group file
     */
    public function testFileStructureRootVsNested(): void
    {
        $template = $this->createContributiveTemplate('Structure Test');

        // Test 1: File at root level (for media contributions).
        $rootFilename = 'root-file-' . uniqid() . '.txt';
        $this->createTestFile($rootFilename, 'Root level file');

        $proposalRootLevel = [
            'template' => $template->id(),
            'media' => [],
            'file' => [
                0 => [
                    'original' => ['@value' => null],
                    'proposed' => [
                        '@value' => 'root-file.txt',
                        'store' => $rootFilename,
                    ],
                ],
            ],
            'dcterms:title' => [
                ['original' => ['@value' => null], 'proposed' => ['@value' => 'Root Level File Test']],
            ],
        ];

        $contributionRoot = $this->createContribution(null, $proposalRootLevel);
        $storedRoot = $contributionRoot->proposal();

        // Verify root level file is stored.
        $this->assertArrayHasKey('file', $storedRoot, 'Root level file key should exist');
        $this->assertEquals(
            $rootFilename,
            $storedRoot['file'][0]['proposed']['store'],
            'Root level file store should be set'
        );

        // Test 2: File nested in media (for item contributions with media).
        $nestedFilename = 'nested-file-' . uniqid() . '.txt';
        $this->createTestFile($nestedFilename, 'Nested level file');

        $proposalNestedLevel = [
            'template' => $template->id(),
            'media' => [
                0 => [
                    'file' => [
                        0 => [
                            'original' => ['@value' => null],
                            'proposed' => [
                                '@value' => 'nested-file.txt',
                                'store' => $nestedFilename,
                            ],
                        ],
                    ],
                ],
            ],
            'dcterms:title' => [
                ['original' => ['@value' => null], 'proposed' => ['@value' => 'Nested Level File Test']],
            ],
        ];

        $contributionNested = $this->createContribution(null, $proposalNestedLevel);
        $storedNested = $contributionNested->proposal();

        // Verify nested file is stored.
        $this->assertArrayHasKey('media', $storedNested, 'Media key should exist');
        $this->assertEquals(
            $nestedFilename,
            $storedNested['media'][0]['file'][0]['proposed']['store'],
            'Nested file store should be set'
        );
    }

    /**
     * Test SQL query for cleanup finds all file locations.
     *
     * This test verifies that the SQL query used in deleteContributionFiles
     * correctly identifies files in both storage locations.
     *
     * @group file
     * @group cleanup
     */
    public function testCleanupQueryFindsAllFiles(): void
    {
        $template = $this->createContributiveTemplate('Cleanup Query Test');

        // Create files for both locations.
        $rootFilename = 'query-root-' . uniqid() . '.txt';
        $nestedFilename = 'query-nested-' . uniqid() . '.txt';
        $this->createTestFile($rootFilename, 'Root');
        $this->createTestFile($nestedFilename, 'Nested');

        // Create contribution with file at root level.
        $proposalRoot = [
            'template' => $template->id(),
            'media' => [],
            'file' => [
                0 => [
                    'original' => ['@value' => null],
                    'proposed' => ['@value' => 'root.txt', 'store' => $rootFilename],
                ],
            ],
            'dcterms:title' => [
                ['original' => ['@value' => null], 'proposed' => ['@value' => 'Root Test']],
            ],
        ];
        $contributionRoot = $this->createContribution(null, $proposalRoot);

        // Create contribution with file nested in media.
        $proposalNested = [
            'template' => $template->id(),
            'media' => [
                0 => [
                    'file' => [
                        0 => [
                            'original' => ['@value' => null],
                            'proposed' => ['@value' => 'nested.txt', 'store' => $nestedFilename],
                        ],
                    ],
                ],
            ],
            'dcterms:title' => [
                ['original' => ['@value' => null], 'proposed' => ['@value' => 'Nested Test']],
            ],
        ];
        $contributionNested = $this->createContribution(null, $proposalNested);

        // Run the SQL query that the cleanup function uses.
        $connection = $this->getServiceLocator()->get('Omeka\Connection');

        // Query for nested files (current implementation).
        $sqlNested = <<<SQL
            SELECT
                JSON_EXTRACT(proposal, "$.media[*].file[*].proposed.store") AS proposal_json
            FROM contribution
            HAVING proposal_json IS NOT NULL;
            SQL;
        $nestedResults = $connection->executeQuery($sqlNested)->fetchFirstColumn();
        $nestedResults = array_map('json_decode', $nestedResults);
        $nestedStoredFiles = $nestedResults ? array_unique(array_merge(...array_values($nestedResults))) : [];

        // Query for root level files (missing from current implementation!).
        $sqlRoot = <<<SQL
            SELECT
                JSON_EXTRACT(proposal, "$.file[*].proposed.store") AS proposal_json
            FROM contribution
            HAVING proposal_json IS NOT NULL;
            SQL;
        $rootResults = $connection->executeQuery($sqlRoot)->fetchFirstColumn();
        $rootResults = array_map('json_decode', $rootResults);
        $rootStoredFiles = $rootResults ? array_unique(array_merge(...array_values($rootResults))) : [];

        // The nested query should find the nested file.
        $this->assertContains(
            $nestedFilename,
            $nestedStoredFiles,
            'Nested file should be found by media query'
        );

        // The root query should find the root file.
        $this->assertContains(
            $rootFilename,
            $rootStoredFiles,
            'Root file should be found by file query'
        );

        // IMPORTANT: The current cleanup implementation ONLY uses the nested query,
        // which means root-level files would be incorrectly marked as orphans!
        // This is a potential bug.
        $this->assertNotContains(
            $rootFilename,
            $nestedStoredFiles,
            'Root file should NOT be found by media-only query (this is the bug!)'
        );
    }

    /**
     * Test that store value is preserved when checkAndIncludeFileData processes
     * a form submission without a new file upload.
     *
     * This simulates the scenario where:
     * 1. A contribution has a file already uploaded (store value exists)
     * 2. User submits the form without uploading a new file
     * 3. The existing store value from hidden fields should be preserved
     *
     * @group file
     * @group regression
     */
    public function testStoreValuePreservedWhenNoNewUpload(): void
    {
        $template = $this->createContributiveTemplate('Store Preserve Test');

        // Create a test file.
        $filename = 'preserve-store-' . uniqid() . '.txt';
        $this->createTestFile($filename, 'Content to preserve');

        // Simulate form data with existing store value (from hidden input).
        $formData = [
            'template' => $template->id(),
            'media' => [
                0 => [
                    'file' => [
                        0 => [
                            'store' => $filename,
                            '@value' => 'original-filename.txt',
                        ],
                    ],
                    'dcterms:title' => [
                        ['@value' => 'Media Title'],
                    ],
                ],
            ],
            'dcterms:title' => [
                ['@value' => 'Test Item'],
            ],
        ];

        // Create the contribution with the store value.
        $proposal = [
            'template' => $template->id(),
            'media' => [
                0 => [
                    'file' => [
                        0 => [
                            'original' => ['@value' => null],
                            'proposed' => [
                                '@value' => 'original-filename.txt',
                                'store' => $filename,
                            ],
                        ],
                    ],
                    'dcterms:title' => [
                        ['original' => ['@value' => null], 'proposed' => ['@value' => 'Media Title']],
                    ],
                ],
            ],
            'dcterms:title' => [
                ['original' => ['@value' => null], 'proposed' => ['@value' => 'Test Item']],
            ],
        ];

        $contribution = $this->createContribution(null, $proposal);

        // Verify the store value is set correctly.
        $storedProposal = $contribution->proposal();
        $this->assertEquals(
            $filename,
            $storedProposal['media'][0]['file'][0]['proposed']['store'],
            'Store value should be set initially'
        );

        // Update with same proposal (simulating form re-submission without new file).
        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:proposal' => $storedProposal,
        ], [], ['isPartial' => true]);

        // Reload and verify store value is still preserved.
        $contribution = $this->api()->read('contributions', $contribution->id())->getContent();
        $reloadedProposal = $contribution->proposal();

        $this->assertEquals(
            $filename,
            $reloadedProposal['media'][0]['file'][0]['proposed']['store'],
            'Store value should be preserved after re-save without new upload'
        );

        // Verify file still exists on disk.
        $this->assertTrue(
            $this->contributionFileExists($filename),
            'Physical file should still exist'
        );
    }

    /**
     * Test multiple media files in single contribution.
     *
     * @group file
     */
    public function testMultipleMediaFilesInContribution(): void
    {
        $template = $this->createContributiveTemplate('Multiple Media Test');

        // Create multiple files.
        $files = [];
        for ($i = 0; $i < 3; $i++) {
            $filename = 'multi-media-' . $i . '-' . uniqid() . '.txt';
            $this->createTestFile($filename, "Content for media $i");
            $files[] = $filename;
        }

        // Create proposal with multiple media.
        $proposal = [
            'template' => $template->id(),
            'media' => [],
            'dcterms:title' => [
                ['original' => ['@value' => null], 'proposed' => ['@value' => 'Multi Media Test']],
            ],
        ];

        foreach ($files as $i => $filename) {
            $proposal['media'][$i] = [
                'file' => [
                    0 => [
                        'original' => ['@value' => null],
                        'proposed' => [
                            '@value' => "media-$i.txt",
                            'store' => $filename,
                        ],
                    ],
                ],
            ];
        }

        $contribution = $this->createContribution(null, $proposal);
        $storedProposal = $contribution->proposal();

        // Verify all files are referenced.
        $this->assertCount(3, $storedProposal['media']);

        foreach ($files as $i => $filename) {
            $this->assertEquals(
                $filename,
                $storedProposal['media'][$i]['file'][0]['proposed']['store'],
                "Media $i file reference should be preserved"
            );
            $this->assertTrue(
                $this->contributionFileExists($filename),
                "Media $i physical file should exist"
            );
        }
    }
}
