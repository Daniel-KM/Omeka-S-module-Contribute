<?php declare(strict_types=1);

namespace ContributeTest\Media;

use CommonTest\AbstractHttpControllerTestCase;
use Contribute\Media\Ingester\Contribution as ContributionIngester;
use ContributeTest\ContributeTestTrait;
use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\Stdlib\ErrorStore;

/**
 * Tests for the Contribution media ingester.
 *
 * This ensures that files uploaded during contribution are properly
 * ingested as media when the contribution is validated.
 */
class ContributionIngesterTest extends AbstractHttpControllerTestCase
{
    use ContributeTestTrait;

    /**
     * @var ContributionIngester
     */
    protected $ingester;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();

        $services = $this->getServiceLocator();
        $mediaIngesters = $services->get('Omeka\Media\Ingester\Manager');
        $this->ingester = $mediaIngesters->get('contribution');
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        parent::tearDown();
    }

    /**
     * @group media
     */
    public function testIngesterLabel(): void
    {
        $this->assertEquals('Contribution', $this->ingester->getLabel());
    }

    /**
     * @group media
     */
    public function testIngesterRenderer(): void
    {
        $this->assertEquals('file', $this->ingester->getRenderer());
    }

    /**
     * @group media
     */
    public function testIngestWithMissingStore(): void
    {
        $media = new Media();
        $request = new Request('create', 'media');
        $request->setContent([
            // No 'store' key.
        ]);
        $errorStore = new ErrorStore();

        $this->ingester->ingest($media, $request, $errorStore);

        $this->assertTrue($errorStore->hasErrors());
        $errors = $errorStore->getErrors();
        $this->assertArrayHasKey('error', $errors);
    }

    /**
     * @group media
     */
    public function testIngestWithInvalidStore(): void
    {
        $media = new Media();
        $request = new Request('create', 'media');
        $request->setContent([
            'store' => 'nonexistent-file.txt',
        ]);
        $errorStore = new ErrorStore();

        $this->ingester->ingest($media, $request, $errorStore);

        $this->assertTrue($errorStore->hasErrors());
    }

    /**
     * @group media
     * @group integration
     */
    public function testIngestWithValidFile(): void
    {
        // Create a test file.
        $filename = 'ingester-test-' . uniqid() . '.txt';
        $content = 'Ingester test content';
        $this->createTestFile($filename, $content);

        // Verify file exists.
        $this->assertTrue($this->contributionFileExists($filename));

        $media = new Media();
        $request = new Request('create', 'media');
        $request->setContent([
            'store' => $filename,
            'o:source' => 'uploaded-file.txt',
        ]);
        $errorStore = new ErrorStore();

        $this->ingester->ingest($media, $request, $errorStore);

        // Should not have errors for valid file.
        $this->assertFalse($errorStore->hasErrors(), 'Should not have errors for valid file');

        // Media should have upload ingester set.
        $this->assertEquals('upload', $media->getIngester());

        // Source name is set on the media if not already set (during full hydration).
        // Since we're not doing a full hydration, we just check the file was processed.
        $this->assertNotNull($media->getFilename(), 'Media should have a filename after ingestion');
    }

    /**
     * @group media
     * @group integration
     */
    public function testIngestWithImageFile(): void
    {
        $filename = 'ingester-image-' . uniqid() . '.png';
        $this->createTestImageFile($filename);

        $this->assertTrue($this->contributionFileExists($filename));

        $media = new Media();
        $request = new Request('create', 'media');
        $request->setContent([
            'store' => $filename,
            'o:source' => 'test-image.png',
        ]);
        $errorStore = new ErrorStore();

        $this->ingester->ingest($media, $request, $errorStore);

        $this->assertFalse($errorStore->hasErrors());
        $this->assertEquals('upload', $media->getIngester());
    }

    /**
     * @group media
     */
    public function testIngestWithoutSourceName(): void
    {
        $filename = 'ingester-nosource-' . uniqid() . '.txt';
        $this->createTestFile($filename, 'No source test');

        $media = new Media();
        $request = new Request('create', 'media');
        $request->setContent([
            'store' => $filename,
            // No 'o:source'.
        ]);
        $errorStore = new ErrorStore();

        $this->ingester->ingest($media, $request, $errorStore);

        $this->assertFalse($errorStore->hasErrors());
        // Source should be derived from temp file.
    }

    /**
     * @group media
     * @group integration
     */
    public function testFileNotDeletedAfterIngest(): void
    {
        $filename = 'ingester-nodelete-' . uniqid() . '.txt';
        $this->createTestFile($filename, 'File should not be deleted');

        $media = new Media();
        $request = new Request('create', 'media');
        $request->setContent([
            'store' => $filename,
            'o:source' => 'nodelete.txt',
        ]);
        $errorStore = new ErrorStore();

        $this->ingester->ingest($media, $request, $errorStore);

        // The ingester uses deleteTempFile = false to preserve the file.
        // The file in contribution/ should still exist until explicitly cleaned.
        $this->assertTrue(
            $this->contributionFileExists($filename),
            'File should still exist in contribution directory after ingest'
        );
    }

    /**
     * @group media
     */
    public function testFormOutput(): void
    {
        $view = $this->getServiceLocator()->get('ViewRenderer');
        $form = $this->ingester->form($view);

        $this->assertIsString($form);
        $this->assertStringContainsString('internal', strtolower($form));
    }

    /**
     * @group media
     */
    public function testDirectoryTraversalBlocked(): void
    {
        $media = new Media();
        $request = new Request('create', 'media');
        $request->setContent([
            'store' => '../../../etc/passwd',
        ]);
        $errorStore = new ErrorStore();

        $this->ingester->ingest($media, $request, $errorStore);

        // Should fail due to path validation.
        $this->assertTrue($errorStore->hasErrors());
    }

    /**
     * @group media
     */
    public function testEmptyStoreValue(): void
    {
        $media = new Media();
        $request = new Request('create', 'media');
        $request->setContent([
            'store' => '',
        ]);
        $errorStore = new ErrorStore();

        $this->ingester->ingest($media, $request, $errorStore);

        $this->assertTrue($errorStore->hasErrors());
    }
}
