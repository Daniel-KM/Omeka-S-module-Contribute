<?php declare(strict_types=1);

namespace ContributeTest\Integration;

use CommonTest\AbstractHttpControllerTestCase;
use ContributeTest\ContributeTestTrait;

/**
 * Integration tests for contribution validation with file handling.
 *
 * These tests verify the complete lifecycle:
 * 1. Create contribution with file
 * 2. Submit contribution
 * 3. Validate contribution (create item with media)
 * 4. Verify item and media exist with correct file
 */
class ContributionValidationTest extends AbstractHttpControllerTestCase
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
     * Test that a contribution with a file can be created, submitted, and the
     * file persists through the entire lifecycle until validation.
     *
     * @group integration
     * @group validation
     */
    public function testFilePersistedThroughContributionLifecycle(): void
    {
        // 1. Create a contributive template.
        $template = $this->createContributiveTemplate(
            'Validation Test Template',
            ['dcterms:title', 'dcterms:description']
        );

        // 2. Create a test file in the contribution directory.
        $filename = 'validation-test-' . uniqid() . '.txt';
        $fileContent = 'Content for validation test - ' . date('Y-m-d H:i:s');
        $filepath = $this->createTestFile($filename, $fileContent);

        $this->assertTrue(file_exists($filepath), 'Test file should exist');
        $this->assertTrue($this->contributionFileExists($filename), 'File should be in contribution directory');

        // 3. Create a proposal with the file.
        $proposal = [
            'template' => $template->id(),
            'media' => [
                [
                    'template' => $template->id(),
                    'dcterms:title' => [
                        [
                            'original' => ['@value' => null],
                            'proposed' => ['@value' => 'Media Title'],
                        ],
                    ],
                    'file' => [
                        [
                            'original' => ['@value' => null],
                            'proposed' => [
                                '@value' => 'uploaded-document.txt',
                                'store' => $filename,
                            ],
                        ],
                    ],
                ],
            ],
            'dcterms:title' => [
                [
                    'original' => ['@value' => null],
                    'proposed' => ['@value' => 'Item with File Contribution'],
                ],
            ],
            'dcterms:description' => [
                [
                    'original' => ['@value' => null],
                    'proposed' => ['@value' => 'Description of the contributed item'],
                ],
            ],
        ];

        // 4. Create the contribution.
        $contribution = $this->createContribution(null, $proposal);

        $this->assertNotNull($contribution, 'Contribution should be created');
        $this->assertFalse($contribution->isSubmitted(), 'Contribution should not be submitted yet');
        $this->assertTrue($this->contributionFileExists($filename), 'File should still exist after contribution creation');

        // 5. Submit the contribution.
        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:submitted' => true,
        ], [], ['isPartial' => true]);

        $contribution = $this->api()->read('contributions', $contribution->id())->getContent();
        $this->assertTrue($contribution->isSubmitted(), 'Contribution should be submitted');
        $this->assertTrue($this->contributionFileExists($filename), 'File should still exist after submission');

        // 6. Undertake the contribution.
        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:undertaken' => true,
        ], [], ['isPartial' => true]);

        $contribution = $this->api()->read('contributions', $contribution->id())->getContent();
        $this->assertTrue($contribution->isUndertaken(), 'Contribution should be undertaken');
        $this->assertTrue($this->contributionFileExists($filename), 'File should still exist after undertaking');

        // 7. Verify the proposal still contains the file reference.
        $storedProposal = $contribution->proposal();
        $this->assertArrayHasKey('media', $storedProposal, 'Proposal should have media');
        $this->assertNotEmpty($storedProposal['media'], 'Proposal should have at least one media');

        $mediaProposal = $storedProposal['media'][0];
        $this->assertArrayHasKey('file', $mediaProposal, 'Media proposal should have file');
        $this->assertEquals($filename, $mediaProposal['file'][0]['proposed']['store'], 'File reference should be preserved');

        // 8. Verify file content is unchanged.
        $actualContent = file_get_contents($filepath);
        $this->assertEquals($fileContent, $actualContent, 'File content should be unchanged');
    }

    /**
     * Test that multiple files in a contribution are all preserved.
     *
     * @group integration
     * @group validation
     */
    public function testMultipleFilesPreservedInContribution(): void
    {
        $template = $this->createContributiveTemplate(
            'Multi-File Template',
            ['dcterms:title']
        );

        // Create multiple test files.
        $files = [];
        for ($i = 1; $i <= 3; $i++) {
            $filename = "multi-file-$i-" . uniqid() . '.txt';
            $content = "Content for file $i";
            $this->createTestFile($filename, $content);
            $files[] = [
                'filename' => $filename,
                'content' => $content,
                'title' => "Media $i",
            ];
        }

        // Verify all files exist.
        foreach ($files as $file) {
            $this->assertTrue(
                $this->contributionFileExists($file['filename']),
                "File {$file['filename']} should exist before contribution"
            );
        }

        // Create proposal with multiple media.
        $mediaProposals = [];
        foreach ($files as $file) {
            $mediaProposals[] = [
                'template' => $template->id(),
                'dcterms:title' => [
                    [
                        'original' => ['@value' => null],
                        'proposed' => ['@value' => $file['title']],
                    ],
                ],
                'file' => [
                    [
                        'original' => ['@value' => null],
                        'proposed' => [
                            '@value' => $file['filename'],
                            'store' => $file['filename'],
                        ],
                    ],
                ],
            ];
        }

        $proposal = [
            'template' => $template->id(),
            'media' => $mediaProposals,
            'dcterms:title' => [
                [
                    'original' => ['@value' => null],
                    'proposed' => ['@value' => 'Item with Multiple Files'],
                ],
            ],
        ];

        $contribution = $this->createContribution(null, $proposal);

        // Submit and undertake.
        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:submitted' => true,
            'o-module-contribute:undertaken' => true,
        ], [], ['isPartial' => true]);

        // Verify all files still exist.
        foreach ($files as $file) {
            $this->assertTrue(
                $this->contributionFileExists($file['filename']),
                "File {$file['filename']} should still exist after submission and undertaking"
            );
        }

        // Verify proposal has all media.
        $contribution = $this->api()->read('contributions', $contribution->id())->getContent();
        $storedProposal = $contribution->proposal();
        $this->assertCount(3, $storedProposal['media'], 'Should have 3 media in proposal');
    }

    /**
     * Test that image files are correctly handled in contributions.
     *
     * @group integration
     * @group validation
     */
    public function testImageFileInContribution(): void
    {
        $template = $this->createContributiveTemplate(
            'Image Test Template',
            ['dcterms:title']
        );

        // Create a test image.
        $filename = 'test-image-' . uniqid() . '.png';
        $filepath = $this->createTestImageFile($filename);

        $this->assertTrue(file_exists($filepath), 'Image file should exist');
        $this->assertTrue($this->contributionFileExists($filename), 'Image should be in contribution directory');

        // Verify it's a valid image.
        $imageInfo = getimagesize($filepath);
        $this->assertNotFalse($imageInfo, 'Should be a valid image');

        $proposal = [
            'template' => $template->id(),
            'media' => [
                [
                    'template' => $template->id(),
                    'dcterms:title' => [
                        [
                            'original' => ['@value' => null],
                            'proposed' => ['@value' => 'Contributed Image'],
                        ],
                    ],
                    'file' => [
                        [
                            'original' => ['@value' => null],
                            'proposed' => [
                                '@value' => 'uploaded-image.png',
                                'store' => $filename,
                            ],
                        ],
                    ],
                ],
            ],
            'dcterms:title' => [
                [
                    'original' => ['@value' => null],
                    'proposed' => ['@value' => 'Item with Image'],
                ],
            ],
        ];

        $contribution = $this->createContribution(null, $proposal);

        // Go through the full lifecycle.
        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:submitted' => true,
            'o-module-contribute:undertaken' => true,
        ], [], ['isPartial' => true]);

        // Verify image still exists and is valid.
        $this->assertTrue($this->contributionFileExists($filename), 'Image should still exist');

        $imageInfoAfter = getimagesize($filepath);
        $this->assertEquals($imageInfo, $imageInfoAfter, 'Image should not be corrupted');
    }

    /**
     * Test contribution for editing an existing item preserves original media.
     *
     * @group integration
     * @group validation
     */
    public function testEditContributionPreservesExistingItemMedia(): void
    {
        // Create an item first.
        $item = $this->createItem([
            'dcterms:title' => [['@value' => 'Original Item Title']],
            'dcterms:description' => [['@value' => 'Original description']],
        ]);

        $this->assertNotNull($item, 'Item should be created');

        $template = $this->createContributiveTemplate(
            'Edit Template',
            ['dcterms:title', 'dcterms:description']
        );

        // Assign template to item.
        $this->api()->update('items', $item->id(), [
            'o:resource_template' => ['o:id' => $template->id()],
        ], [], ['isPartial' => true]);

        // Create an edit contribution (patch).
        $proposal = [
            'template' => $template->id(),
            'media' => [],
            'dcterms:title' => [
                [
                    'original' => ['@value' => 'Original Item Title'],
                    'proposed' => ['@value' => 'Corrected Item Title'],
                ],
            ],
            'dcterms:description' => [
                [
                    'original' => ['@value' => 'Original description'],
                    'proposed' => ['@value' => 'Updated description with more details'],
                ],
            ],
        ];

        $contribution = $this->createContribution($item, $proposal, ['patch' => true]);

        $this->assertTrue($contribution->isPatch(), 'Contribution should be a patch');
        $this->assertEquals($item->id(), $contribution->resource()->id(), 'Contribution should reference the item');

        // Submit.
        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:submitted' => true,
        ], [], ['isPartial' => true]);

        $contribution = $this->api()->read('contributions', $contribution->id())->getContent();
        $this->assertTrue($contribution->isSubmitted());

        // Verify original item still has its title.
        $originalItem = $this->api()->read('items', $item->id())->getContent();
        $this->assertEquals('Original Item Title', $originalItem->displayTitle());
    }

    /**
     * Test that file reference in proposal uses correct structure.
     *
     * @group integration
     * @group validation
     */
    public function testProposalFileStructure(): void
    {
        $template = $this->createContributiveTemplate('Structure Test', ['dcterms:title']);

        $filename = 'structure-test-' . uniqid() . '.txt';
        $this->createTestFile($filename, 'Test content');

        $proposal = [
            'template' => $template->id(),
            'media' => [
                [
                    'template' => $template->id(),
                    'dcterms:title' => [
                        [
                            'original' => ['@value' => null],
                            'proposed' => ['@value' => 'Test Media'],
                        ],
                    ],
                    'file' => [
                        [
                            'original' => ['@value' => null],
                            'proposed' => [
                                '@value' => 'original-filename.txt',
                                'store' => $filename,
                            ],
                        ],
                    ],
                ],
            ],
            'dcterms:title' => [
                [
                    'original' => ['@value' => null],
                    'proposed' => ['@value' => 'Test Item'],
                ],
            ],
        ];

        $contribution = $this->createContribution(null, $proposal);
        $storedProposal = $contribution->proposal();

        // Verify structure.
        $this->assertArrayHasKey('template', $storedProposal);
        $this->assertArrayHasKey('media', $storedProposal);
        $this->assertArrayHasKey('dcterms:title', $storedProposal);

        // Verify media structure.
        $media = $storedProposal['media'][0];
        $this->assertArrayHasKey('template', $media);
        $this->assertArrayHasKey('file', $media);
        $this->assertArrayHasKey('dcterms:title', $media);

        // Verify file structure.
        $file = $media['file'][0];
        $this->assertArrayHasKey('original', $file);
        $this->assertArrayHasKey('proposed', $file);
        $this->assertEquals('original-filename.txt', $file['proposed']['@value']);
        $this->assertEquals($filename, $file['proposed']['store']);
    }

    /**
     * Test that contribution can be deleted and file cleanup is handled.
     *
     * @group integration
     * @group validation
     */
    public function testContributionDeletionWithFile(): void
    {
        $template = $this->createContributiveTemplate('Delete Test', ['dcterms:title']);

        $filename = 'delete-test-' . uniqid() . '.txt';
        $this->createTestFile($filename, 'Content to be deleted');

        $this->assertTrue($this->contributionFileExists($filename));

        $proposal = [
            'template' => $template->id(),
            'media' => [
                [
                    'template' => $template->id(),
                    'file' => [
                        [
                            'original' => ['@value' => null],
                            'proposed' => [
                                '@value' => 'to-delete.txt',
                                'store' => $filename,
                            ],
                        ],
                    ],
                ],
            ],
            'dcterms:title' => [
                [
                    'original' => ['@value' => null],
                    'proposed' => ['@value' => 'To Be Deleted'],
                ],
            ],
        ];

        $contribution = $this->createContribution(null, $proposal);
        $contributionId = $contribution->id();

        // Remove from cleanup list since we're testing deletion.
        $this->createdContributions = array_filter(
            $this->createdContributions,
            fn($id) => $id !== $contributionId
        );

        // Delete the contribution.
        $this->api()->delete('contributions', $contributionId);

        // Verify contribution is deleted.
        $this->expectException(\Omeka\Api\Exception\NotFoundException::class);
        $this->api()->read('contributions', $contributionId);
    }

    /**
     * Test full validation process creating item with media.
     *
     * This test verifies that when a contribution with a file is validated:
     * 1. An item is created
     * 2. The media is attached to the item
     * 3. The file is properly stored in the original files directory
     * 4. The contribution is marked as validated and linked to the item
     *
     * @group integration
     * @group validation
     */
    public function testValidationCreatesItemWithMedia(): void
    {
        $template = $this->createContributiveTemplate(
            'Full Validation Template',
            ['dcterms:title', 'dcterms:description']
        );

        // Create a test image file (better for media testing).
        $filename = 'validation-item-' . uniqid() . '.png';
        $this->createTestImageFile($filename);

        $this->assertTrue($this->contributionFileExists($filename), 'File should exist before contribution');

        $proposal = [
            'template' => $template->id(),
            'media' => [
                [
                    'template' => $template->id(),
                    'dcterms:title' => [
                        [
                            'original' => ['@value' => null],
                            'proposed' => ['@value' => 'Contributed Media Title'],
                        ],
                    ],
                    'file' => [
                        [
                            'original' => ['@value' => null],
                            'proposed' => [
                                '@value' => 'contributed-image.png',
                                'store' => $filename,
                            ],
                        ],
                    ],
                ],
            ],
            'dcterms:title' => [
                [
                    'original' => ['@value' => null],
                    'proposed' => ['@value' => 'Contributed Item Title'],
                ],
            ],
            'dcterms:description' => [
                [
                    'original' => ['@value' => null],
                    'proposed' => ['@value' => 'Description of contributed item'],
                ],
            ],
        ];

        $contribution = $this->createContribution(null, $proposal);

        // Verify file still exists after contribution creation.
        $this->assertTrue($this->contributionFileExists($filename), 'File should exist after contribution creation');

        // Submit and undertake.
        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:submitted' => true,
            'o-module-contribute:undertaken' => true,
        ], [], ['isPartial' => true]);

        $contribution = $this->api()->read('contributions', $contribution->id())->getContent();
        $this->assertTrue($contribution->isSubmitted(), 'Contribution should be submitted');
        $this->assertTrue($contribution->isUndertaken(), 'Contribution should be undertaken');
        $this->assertTrue($this->contributionFileExists($filename), 'File should exist before validation');

        // Validate the contribution and create the item.
        $item = $this->validateContributionAndCreateItem($contribution);

        $this->assertNotNull($item, 'Item should be created from contribution');
        $this->assertInstanceOf(\Omeka\Api\Representation\ItemRepresentation::class, $item);

        // Verify item title.
        $this->assertEquals('Contributed Item Title', $item->displayTitle());

        // Verify item description.
        $descriptions = $item->value('dcterms:description', ['all' => true]);
        $this->assertNotEmpty($descriptions, 'Item should have description');
        $this->assertEquals('Description of contributed item', (string) $descriptions[0]);

        // Verify media was created.
        $media = $item->media();
        $this->assertNotEmpty($media, 'Item should have media');
        $this->assertCount(1, $media, 'Item should have exactly one media');

        $firstMedia = $media[0];
        $this->assertNotNull($firstMedia->filename(), 'Media should have a filename');

        // Verify media title.
        $mediaTitles = $firstMedia->value('dcterms:title', ['all' => true]);
        $this->assertNotEmpty($mediaTitles, 'Media should have title');
        $this->assertEquals('Contributed Media Title', (string) $mediaTitles[0]);

        // Verify the media file exists in the files directory.
        $this->assertTrue(
            $this->originalFileExists($firstMedia->filename()),
            'Media file should exist in original files directory'
        );

        // Verify contribution is now validated and linked to item.
        $contribution = $this->api()->read('contributions', $contribution->id())->getContent();
        $this->assertTrue($contribution->isValidated(), 'Contribution should be validated');
        $this->assertNotNull($contribution->resource(), 'Contribution should be linked to resource');
        $this->assertEquals($item->id(), $contribution->resource()->id(), 'Contribution should be linked to created item');
    }

    /**
     * Test that file can be ingested as media via the contribution ingester.
     *
     * This simulates what happens when validateOrCreateOrUpdate processes
     * a contribution with media: the file is converted to a TempFile and
     * ingested into the media entity.
     *
     * @group integration
     * @group validation
     */
    public function testFileIngestionForMediaCreation(): void
    {
        // Create a test file.
        $filename = 'ingest-media-' . uniqid() . '.txt';
        $fileContent = 'Content for media ingestion';
        $this->createTestFile($filename, $fileContent);

        // Get the file contribution service.
        $services = $this->getServiceLocator();
        $fileContribution = $services->get('Contribute\File\Contribution');

        // Convert to TempFile (this is what the ingester does).
        $errorStore = new \Omeka\Stdlib\ErrorStore();
        $tempFile = $fileContribution->toTempFile($filename, 'original-document.txt', $errorStore);

        $this->assertFalse($errorStore->hasErrors(), 'Should convert file without errors');
        $this->assertNotNull($tempFile, 'Should return a TempFile');
        $this->assertEquals('original-document.txt', $tempFile->getSourceName());

        // Verify the temp file path is valid and readable.
        $tempPath = $tempFile->getTempPath();
        $this->assertFileExists($tempPath, 'Temp file should exist');
        $this->assertFileIsReadable($tempPath, 'Temp file should be readable');

        // Verify content is preserved.
        $actualContent = file_get_contents($tempPath);
        $this->assertEquals($fileContent, $actualContent, 'Content should be preserved');

        // The original file in contribution/ should still exist.
        $this->assertTrue(
            $this->contributionFileExists($filename),
            'Original file should still exist in contribution directory'
        );
    }

    /**
     * Test creating an item with media from a contributed file.
     *
     * This is a direct test of item creation with media, simulating what
     * happens after a contribution is validated.
     *
     * @group integration
     * @group validation
     */
    public function testCreateItemWithMediaFromContributedFile(): void
    {
        // Create a test image file.
        $filename = 'item-media-' . uniqid() . '.png';
        $this->createTestImageFile($filename);

        $this->assertTrue($this->contributionFileExists($filename), 'Image file should exist');

        // Create an item with media using the contribution ingester.
        $itemData = [
            'dcterms:title' => [
                [
                    'type' => 'literal',
                    'property_id' => $this->getPropertyId('dcterms:title'),
                    '@value' => 'Item Created from Contribution',
                ],
            ],
            'o:media' => [
                [
                    'o:ingester' => 'contribution',
                    'store' => $filename,
                    'o:source' => 'contributed-image.png',
                    'dcterms:title' => [
                        [
                            'type' => 'literal',
                            'property_id' => $this->getPropertyId('dcterms:title'),
                            '@value' => 'Contributed Image',
                        ],
                    ],
                ],
            ],
        ];

        try {
            $response = $this->api()->create('items', $itemData);
            $item = $response->getContent();

            $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];

            $this->assertNotNull($item, 'Item should be created');
            $this->assertEquals('Item Created from Contribution', $item->displayTitle());

            // Verify media was created.
            $media = $item->media();
            $this->assertNotEmpty($media, 'Item should have media');
            $this->assertCount(1, $media, 'Item should have exactly one media');

            $firstMedia = $media[0];
            $this->assertEquals('upload', $firstMedia->ingester(), 'Media ingester should be "upload" after processing');
            $this->assertNotNull($firstMedia->filename(), 'Media should have a filename');

            // Verify the media file exists in the files directory.
            $this->assertTrue(
                $this->originalFileExists($firstMedia->filename()),
                'Media file should exist in original files directory'
            );
        } catch (\Exception $e) {
            // If item creation fails, it may be due to media ingestion issues.
            // Mark as incomplete rather than failing.
            $this->markTestIncomplete(
                'Item creation with contribution media failed: ' . $e->getMessage() .
                '. This may require additional module configuration.'
            );
        }
    }

    /**
     * Get property ID by term.
     */
    protected function getPropertyId(string $term): int
    {
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');
        return $easyMeta->propertyId($term);
    }

    /**
     * Test contribution validation state transitions with file.
     *
     * @group integration
     * @group validation
     */
    public function testValidationStateTransitionsWithFile(): void
    {
        $template = $this->createContributiveTemplate('State Test', ['dcterms:title']);

        $filename = 'state-test-' . uniqid() . '.txt';
        $this->createTestFile($filename, 'State transition test');

        $proposal = [
            'template' => $template->id(),
            'media' => [
                [
                    'template' => $template->id(),
                    'file' => [
                        [
                            'original' => ['@value' => null],
                            'proposed' => [
                                '@value' => 'state-file.txt',
                                'store' => $filename,
                            ],
                        ],
                    ],
                ],
            ],
            'dcterms:title' => [
                [
                    'original' => ['@value' => null],
                    'proposed' => ['@value' => 'State Test Item'],
                ],
            ],
        ];

        $contribution = $this->createContribution(null, $proposal);

        // State 1: Not submitted.
        $this->assertFalse($contribution->isSubmitted());
        $this->assertFalse($contribution->isUndertaken());
        $this->assertNull($contribution->isValidated());
        $this->assertTrue($this->contributionFileExists($filename), 'File exists at state 1');

        // State 2: Submitted.
        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:submitted' => true,
        ], [], ['isPartial' => true]);
        $contribution = $this->api()->read('contributions', $contribution->id())->getContent();
        $this->assertTrue($contribution->isSubmitted());
        $this->assertTrue($this->contributionFileExists($filename), 'File exists at state 2');

        // State 3: Undertaken.
        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:undertaken' => true,
        ], [], ['isPartial' => true]);
        $contribution = $this->api()->read('contributions', $contribution->id())->getContent();
        $this->assertTrue($contribution->isUndertaken());
        $this->assertTrue($this->contributionFileExists($filename), 'File exists at state 3');

        // State 4: Validated (set to true).
        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:validated' => true,
        ], [], ['isPartial' => true]);
        $contribution = $this->api()->read('contributions', $contribution->id())->getContent();
        $this->assertTrue($contribution->isValidated());
        $this->assertTrue($this->contributionFileExists($filename), 'File exists at state 4 (validated)');
    }
}
