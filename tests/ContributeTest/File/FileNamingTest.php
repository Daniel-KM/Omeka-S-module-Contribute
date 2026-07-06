<?php declare(strict_types=1);

namespace ContributeTest\File;

use CommonTest\AbstractHttpControllerTestCase;
use ContributeTest\ContributeTestTrait;

/**
 * Tests for the preservation of the random store name across the steps of the
 * contribution form.
 *
 * The store is the random name generated at upload; it is the only reference to
 * the stored file and must never be lost nor changed when the form is saved
 * again without a new upload.
 *
 * @group file
 * @group naming
 */
class FileNamingTest extends AbstractHttpControllerTestCase
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
     * Create a template that the ContributiveData plugin considers
     * contributive, that is with editable/fillable data on its properties
     * (AdvancedResourceTemplate data), not only in the legacy settings.
     */
    protected function createDataContributiveTemplate(string $label): int
    {
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');
        $templateData = [
            'o:label' => $label,
            'o:data' => ['contribute_template_contributable' => 'global'],
            'o:resource_template_property' => [
                [
                    'o:property' => ['o:id' => $easyMeta->propertyId('dcterms:title')],
                    'o:data' => [['editable' => true, 'fillable' => true]],
                ],
            ],
        ];
        $template = $this->api()->create('resource_templates', $templateData)->getContent();
        $this->createdTemplates[] = $template->id();
        return $template->id();
    }

    protected function prepareProposal(array $proposal): ?array
    {
        $controller = $this->getApplication()->getServiceManager()
            ->get('ControllerManager')->get('Contribute\Controller\Site\Contribution');
        $method = new \ReflectionMethod($controller, 'prepareProposal');
        $method->setAccessible(true);
        return $method->invoke($controller, $proposal);
    }

    /**
     * The store must be kept when the form is saved again without a new upload,
     * even when the source name ("@value") is empty.
     */
    public function testStoreKeptWhenSourceNameEmpty(): void
    {
        $templateId = $this->createDataContributiveTemplate('Naming Empty Value');

        $result = $this->prepareProposal([
            'template' => $templateId,
            'file' => [
                0 => ['store' => 'abc123def456.pdf', '@value' => ''],
            ],
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('file', $result, 'The file must be kept even with an empty source name');
        $this->assertSame(
            'abc123def456.pdf',
            $result['file'][0]['proposed']['store'] ?? null,
            'The random store name must be preserved'
        );
    }

    /**
     * The store must be kept when both the store and the source name are set.
     */
    public function testStoreKeptWithSourceName(): void
    {
        $templateId = $this->createDataContributiveTemplate('Naming With Value');

        $result = $this->prepareProposal([
            'template' => $templateId,
            'file' => [
                0 => ['store' => 'abc123def456.pdf', '@value' => 'rapport.pdf'],
            ],
        ]);

        $this->assertSame('abc123def456.pdf', $result['file'][0]['proposed']['store'] ?? null);
        $this->assertSame('rapport.pdf', $result['file'][0]['proposed']['@value'] ?? null);
    }

    /**
     * A tampered store containing a path must not be kept as a store, to avoid
     * a path traversal in the file operations and the download link.
     */
    public function testTamperedStoreWithPathIsRejected(): void
    {
        $templateId = $this->createDataContributiveTemplate('Naming Traversal');

        $result = $this->prepareProposal([
            'template' => $templateId,
            'file' => [
                0 => ['store' => '../../secret.pdf', '@value' => 'x.pdf'],
            ],
        ]);

        $this->assertArrayHasKey('file', $result);
        $this->assertArrayNotHasKey(
            'store',
            $result['file'][0]['proposed'],
            'A store with a path must not be kept as a store'
        );
    }
}
