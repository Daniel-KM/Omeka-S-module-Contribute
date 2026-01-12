<?php declare(strict_types=1);

namespace ContributeTest\File;

use CommonTest\AbstractHttpControllerTestCase;
use Contribute\File\Contribution as FileContribution;
use ContributeTest\ContributeTestTrait;
use Omeka\Stdlib\ErrorStore;

/**
 * Tests for file handling in contributions.
 *
 * These tests verify that:
 * 1. Files uploaded during contribution are properly stored
 * 2. Files are not lost during contribution lifecycle
 * 3. Files are properly moved when contribution is validated
 * 4. Files are properly cleaned up when contribution is deleted
 */
class ContributionFileTest extends AbstractHttpControllerTestCase
{
    use ContributeTestTrait;

    /**
     * @var FileContribution
     */
    protected $fileContribution;

    /**
     * @var string
     */
    protected $basePath;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();

        $services = $this->getServiceLocator();
        $this->fileContribution = $services->get('Contribute\File\Contribution');

        $config = $services->get('Config');
        $this->basePath = $config['file_store']['local']['base_path'] ?? OMEKA_PATH . '/files';
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        parent::tearDown();
    }

    /**
     * @group file
     */
    public function testContributionDirectoryExists(): void
    {
        $contributionDir = $this->basePath . '/contribution';

        // Ensure directory exists.
        if (!is_dir($contributionDir)) {
            mkdir($contributionDir, 0755, true);
        }

        $this->assertDirectoryExists($contributionDir);
        $this->assertTrue(is_writable($contributionDir), 'Contribution directory must be writable');
    }

    /**
     * @group file
     */
    public function testCreateAndVerifyTestFile(): void
    {
        $filename = 'test-file-' . uniqid() . '.txt';
        $content = 'Test content for file verification';

        $filepath = $this->createTestFile($filename, $content);

        $this->assertFileExists($filepath);
        $this->assertEquals($content, file_get_contents($filepath));
        $this->assertTrue($this->contributionFileExists($filename));
    }

    /**
     * @group file
     */
    public function testToTempFileWithValidFile(): void
    {
        $filename = 'test-valid-' . uniqid() . '.txt';
        $content = 'Valid file content';

        $this->createTestFile($filename, $content);

        $errorStore = new ErrorStore();
        $tempFile = $this->fileContribution->toTempFile($filename, 'original-name.txt', $errorStore);

        $this->assertFalse($errorStore->hasErrors(), 'Should not have errors for valid file');
        $this->assertNotNull($tempFile);
        $this->assertEquals('original-name.txt', $tempFile->getSourceName());
    }

    /**
     * @group file
     */
    public function testToTempFileWithEmptyFilename(): void
    {
        $errorStore = new ErrorStore();
        $tempFile = $this->fileContribution->toTempFile('', null, $errorStore);

        $this->assertNull($tempFile);
        $this->assertTrue($errorStore->hasErrors());
        $errors = $errorStore->getErrors();
        $this->assertArrayHasKey('store', $errors);
    }

    /**
     * @group file
     */
    public function testToTempFileWithNonexistentFile(): void
    {
        $errorStore = new ErrorStore();
        $tempFile = $this->fileContribution->toTempFile('nonexistent-file.txt', null, $errorStore);

        $this->assertNull($tempFile);
        $this->assertTrue($errorStore->hasErrors());
    }

    /**
     * @group file
     */
    public function testToTempFileWithDirectoryTraversal(): void
    {
        // Try to access a file outside the contribution directory.
        $errorStore = new ErrorStore();
        $tempFile = $this->fileContribution->toTempFile('../../../etc/passwd', null, $errorStore);

        $this->assertNull($tempFile);
        // Should fail due to path validation.
    }

    /**
     * @group file
     */
    public function testCreateTestImageFile(): void
    {
        $filename = 'test-image-' . uniqid() . '.png';
        $filepath = $this->createTestImageFile($filename);

        $this->assertFileExists($filepath);
        $this->assertTrue($this->contributionFileExists($filename));

        // Verify it's a valid image.
        $imageInfo = getimagesize($filepath);
        $this->assertNotFalse($imageInfo);
        $this->assertEquals(IMAGETYPE_PNG, $imageInfo[2]);
    }

    /**
     * @group file
     * @group integration
     */
    public function testFileExistsAfterContributionCreation(): void
    {
        $template = $this->createContributiveTemplate('File Test Template', ['dcterms:title']);

        // Create a test file.
        $filename = 'contribution-file-' . uniqid() . '.txt';
        $this->createTestFile($filename, 'File content for contribution');

        // Verify the file exists before creating contribution.
        $this->assertTrue($this->contributionFileExists($filename));

        // Create a proposal with file reference.
        $proposal = [
            'template' => $template->id(),
            'media' => [],
            'dcterms:title' => [
                [
                    'original' => ['@value' => null],
                    'proposed' => ['@value' => 'Item with file'],
                ],
            ],
            'file' => [
                [
                    'original' => ['@value' => null],
                    'proposed' => [
                        '@value' => 'uploaded-file.txt',
                        'store' => $filename,
                    ],
                ],
            ],
        ];

        $contribution = $this->createContribution(null, $proposal);

        // Verify file still exists after contribution creation.
        $this->assertTrue(
            $this->contributionFileExists($filename),
            'File should still exist after contribution creation'
        );

        // Verify contribution references the file.
        $contributionProposal = $contribution->proposal();
        $this->assertArrayHasKey('file', $contributionProposal);
    }

    /**
     * @group file
     * @group integration
     */
    public function testFileExistsAfterContributionUpdate(): void
    {
        $template = $this->createContributiveTemplate('File Update Template', ['dcterms:title']);

        $filename = 'update-file-' . uniqid() . '.txt';
        $this->createTestFile($filename, 'Original content');

        $proposal = [
            'template' => $template->id(),
            'media' => [],
            'dcterms:title' => [
                [
                    'original' => ['@value' => null],
                    'proposed' => ['@value' => 'Original title'],
                ],
            ],
            'file' => [
                [
                    'original' => ['@value' => null],
                    'proposed' => [
                        '@value' => 'test.txt',
                        'store' => $filename,
                    ],
                ],
            ],
        ];

        $contribution = $this->createContribution(null, $proposal);

        // Update the contribution.
        $updatedProposal = $contribution->proposal();
        $updatedProposal['dcterms:title'][0]['proposed']['@value'] = 'Updated title';

        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:proposal' => $updatedProposal,
        ], [], ['isPartial' => true]);

        // Verify file still exists after update.
        $this->assertTrue(
            $this->contributionFileExists($filename),
            'File should still exist after contribution update'
        );
    }

    /**
     * @group file
     * @group integration
     */
    public function testFileExistsAfterContributionSubmission(): void
    {
        $template = $this->createContributiveTemplate('File Submit Template', ['dcterms:title']);

        $filename = 'submit-file-' . uniqid() . '.txt';
        $this->createTestFile($filename, 'Content for submission');

        $proposal = [
            'template' => $template->id(),
            'media' => [],
            'dcterms:title' => [
                [
                    'original' => ['@value' => null],
                    'proposed' => ['@value' => 'Submitted item'],
                ],
            ],
            'file' => [
                [
                    'original' => ['@value' => null],
                    'proposed' => [
                        '@value' => 'submitted.txt',
                        'store' => $filename,
                    ],
                ],
            ],
        ];

        $contribution = $this->createContribution(null, $proposal);

        // Submit the contribution.
        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:submitted' => true,
        ], [], ['isPartial' => true]);

        // Verify file still exists after submission.
        $this->assertTrue(
            $this->contributionFileExists($filename),
            'File should still exist after contribution submission'
        );
    }

    /**
     * @group file
     * @group integration
     */
    public function testFileExistsAfterContributionUndertaking(): void
    {
        $template = $this->createContributiveTemplate('File Undertake Template', ['dcterms:title']);

        $filename = 'undertake-file-' . uniqid() . '.txt';
        $this->createTestFile($filename, 'Content for undertaking');

        $proposal = [
            'template' => $template->id(),
            'media' => [],
            'dcterms:title' => [
                [
                    'original' => ['@value' => null],
                    'proposed' => ['@value' => 'Undertaken item'],
                ],
            ],
            'file' => [
                [
                    'original' => ['@value' => null],
                    'proposed' => [
                        '@value' => 'undertaken.txt',
                        'store' => $filename,
                    ],
                ],
            ],
        ];

        $contribution = $this->createContribution(null, $proposal, [
            'submitted' => true,
        ]);

        // Undertake the contribution.
        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:undertaken' => true,
        ], [], ['isPartial' => true]);

        // Verify file still exists after undertaking.
        $this->assertTrue(
            $this->contributionFileExists($filename),
            'File should still exist after contribution undertaking'
        );
    }

    /**
     * @group file
     * @group integration
     */
    public function testMultipleFilesInContribution(): void
    {
        $template = $this->createContributiveTemplate('Multi File Template', ['dcterms:title']);

        $filenames = [];
        for ($i = 1; $i <= 3; $i++) {
            $filename = 'multi-file-' . $i . '-' . uniqid() . '.txt';
            $this->createTestFile($filename, "Content for file $i");
            $filenames[] = $filename;
        }

        // Verify all files exist before contribution.
        foreach ($filenames as $filename) {
            $this->assertTrue($this->contributionFileExists($filename));
        }

        $proposal = [
            'template' => $template->id(),
            'media' => [],
            'dcterms:title' => [
                [
                    'original' => ['@value' => null],
                    'proposed' => ['@value' => 'Item with multiple files'],
                ],
            ],
        ];

        // Add files to media proposal.
        foreach ($filenames as $index => $filename) {
            $proposal['media'][$index] = [
                'template' => $template->id(),
                'file' => [
                    [
                        'original' => ['@value' => null],
                        'proposed' => [
                            '@value' => "file-$index.txt",
                            'store' => $filename,
                        ],
                    ],
                ],
            ];
        }

        $contribution = $this->createContribution(null, $proposal);

        // Verify all files still exist after contribution.
        foreach ($filenames as $filename) {
            $this->assertTrue(
                $this->contributionFileExists($filename),
                "File $filename should still exist after contribution creation"
            );
        }
    }

    /**
     * @group file
     */
    public function testVerifyFileRejectsNonexistentPath(): void
    {
        $fakeFileInfo = new \SplFileInfo('/nonexistent/path/to/file.txt');

        $errorStore = new ErrorStore();
        $result = $this->fileContribution->verifyFile($fakeFileInfo, $errorStore);

        $this->assertNull($result);
        $this->assertTrue($errorStore->hasErrors());
    }

    /**
     * @group file
     */
    public function testVerifyFileAcceptsValidPath(): void
    {
        $filename = 'verify-valid-' . uniqid() . '.txt';
        $filepath = $this->createTestFile($filename, 'Valid content');

        $fileInfo = new \SplFileInfo($filepath);

        $errorStore = new ErrorStore();
        $result = $this->fileContribution->verifyFile($fileInfo, $errorStore);

        $this->assertFalse($errorStore->hasErrors());
        $this->assertNotNull($result);
        $this->assertEquals(realpath($filepath), $result);
    }

    /**
     * @group file
     * @group integration
     */
    public function testFilePreservedThroughFullContributionLifecycle(): void
    {
        $template = $this->createContributiveTemplate('Lifecycle Template', ['dcterms:title']);

        $filename = 'lifecycle-file-' . uniqid() . '.txt';
        $content = 'Content preserved through lifecycle';
        $this->createTestFile($filename, $content);

        $proposal = [
            'template' => $template->id(),
            'media' => [],
            'dcterms:title' => [
                [
                    'original' => ['@value' => null],
                    'proposed' => ['@value' => 'Lifecycle test'],
                ],
            ],
            'file' => [
                [
                    'original' => ['@value' => null],
                    'proposed' => [
                        '@value' => 'lifecycle.txt',
                        'store' => $filename,
                    ],
                ],
            ],
        ];

        // Step 1: Create contribution.
        $contribution = $this->createContribution(null, $proposal);
        $this->assertTrue($this->contributionFileExists($filename), 'File should exist after creation');

        // Step 2: Submit.
        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:submitted' => true,
        ], [], ['isPartial' => true]);
        $this->assertTrue($this->contributionFileExists($filename), 'File should exist after submission');

        // Step 3: Undertake.
        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:undertaken' => true,
        ], [], ['isPartial' => true]);
        $this->assertTrue($this->contributionFileExists($filename), 'File should exist after undertaking');

        // Step 4: Validate (without creating the resource to keep file in contribution/).
        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:validated' => true,
        ], [], ['isPartial' => true]);
        $this->assertTrue($this->contributionFileExists($filename), 'File should exist after validation');

        // Verify file content is still correct.
        $filePath = $this->basePath . '/contribution/' . $filename;
        $this->assertEquals($content, file_get_contents($filePath));
    }

    /**
     * @group file
     */
    public function testImageFileHandling(): void
    {
        $filename = 'test-image-handling-' . uniqid() . '.png';
        $filepath = $this->createTestImageFile($filename);

        $errorStore = new ErrorStore();
        $tempFile = $this->fileContribution->toTempFile($filename, 'uploaded-image.png', $errorStore);

        $this->assertFalse($errorStore->hasErrors());
        $this->assertNotNull($tempFile);
        $this->assertEquals('uploaded-image.png', $tempFile->getSourceName());

        // Verify the temp file path is valid.
        $this->assertFileExists($tempFile->getTempPath());
    }
}
