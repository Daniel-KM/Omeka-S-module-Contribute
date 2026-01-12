<?php declare(strict_types=1);

namespace ContributeTest;

use Contribute\Api\Representation\ContributionRepresentation;
use Contribute\Entity\Contribution;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ResourceTemplateRepresentation;
use Omeka\Entity\Job;

/**
 * Shared test helpers for Contribute module tests.
 */
trait ContributeTestTrait
{
    /**
     * @var ServiceLocatorInterface
     */
    protected $services;

    /**
     * @var array IDs of items created during tests (for cleanup).
     */
    protected array $createdResources = [];

    /**
     * @var array IDs of contributions created during tests (for cleanup).
     */
    protected array $createdContributions = [];

    /**
     * @var array IDs of resource templates created during tests (for cleanup).
     */
    protected array $createdTemplates = [];

    /**
     * @var array Paths of temporary files created during tests (for cleanup).
     */
    protected array $createdTempFiles = [];

    /**
     * @var bool Whether admin is logged in.
     */
    protected bool $isLoggedIn = false;

    /**
     * Get the service locator.
     */
    protected function getServiceLocator(): ServiceLocatorInterface
    {
        if (isset($this->application) && $this->application !== null) {
            return $this->application->getServiceManager();
        }
        return $this->getApplication()->getServiceManager();
    }

    /**
     * Reset the cached service locator.
     */
    protected function resetServiceLocator(): void
    {
        $this->services = null;
    }

    /**
     * Get the API manager.
     */
    protected function api(): ApiManager
    {
        if ($this->isLoggedIn) {
            $this->ensureLoggedIn();
        }
        return $this->getServiceLocator()->get('Omeka\ApiManager');
    }

    /**
     * Get the entity manager.
     */
    public function getEntityManager(): \Doctrine\ORM\EntityManager
    {
        return $this->getServiceLocator()->get('Omeka\EntityManager');
    }

    /**
     * Login as admin user.
     */
    protected function loginAdmin(): void
    {
        $this->isLoggedIn = true;
        $this->ensureLoggedIn();
    }

    /**
     * Ensure admin is logged in on the current application instance.
     */
    protected function ensureLoggedIn(): void
    {
        $services = $this->getServiceLocator();
        $auth = $services->get('Omeka\AuthenticationService');

        if ($auth->hasIdentity()) {
            return;
        }

        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();
    }

    /**
     * Logout current user.
     */
    protected function logout(): void
    {
        $this->isLoggedIn = false;
        $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
        $auth->clearIdentity();
    }

    /**
     * Create a test item.
     *
     * @param array $data Item data with property terms as keys.
     * @return ItemRepresentation
     */
    protected function createItem(array $data): ItemRepresentation
    {
        $itemData = [];
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');

        foreach ($data as $term => $values) {
            if (strpos($term, ':') === false) {
                $itemData[$term] = $values;
                continue;
            }

            $propertyId = $easyMeta->propertyId($term);
            if (!$propertyId) {
                continue;
            }

            $itemData[$term] = [];
            foreach ($values as $value) {
                $valueData = [
                    'type' => $value['type'] ?? 'literal',
                    'property_id' => $propertyId,
                ];
                if (isset($value['@value'])) {
                    $valueData['@value'] = $value['@value'];
                }
                if (isset($value['@id'])) {
                    $valueData['@id'] = $value['@id'];
                }
                if (isset($value['o:label'])) {
                    $valueData['o:label'] = $value['o:label'];
                }
                $itemData[$term][] = $valueData;
            }
        }

        $response = $this->api()->create('items', $itemData);
        $item = $response->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];

        return $item;
    }

    /**
     * Create a contributive resource template for testing.
     *
     * @param string $label Template label
     * @param array $properties Properties to include (terms)
     * @param array $options Additional options for the template
     * @return ResourceTemplateRepresentation
     */
    protected function createContributiveTemplate(
        string $label,
        array $properties = ['dcterms:title', 'dcterms:description'],
        array $options = []
    ): ResourceTemplateRepresentation {
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');

        $templateData = [
            'o:label' => $label,
            'o:resource_template_property' => [],
        ];

        foreach ($properties as $index => $term) {
            $propertyId = $easyMeta->propertyId($term);
            if ($propertyId) {
                $templateData['o:resource_template_property'][] = [
                    'o:property' => ['o:id' => $propertyId],
                    'o:alternate_label' => null,
                    'o:alternate_comment' => null,
                    'o:is_required' => false,
                    'o:is_private' => false,
                    'o:data_type' => [],
                ];
            }
        }

        $response = $this->api()->create('resource_templates', $templateData);
        $template = $response->getContent();
        $this->createdTemplates[] = $template->id();

        // Configure the template as contributive in module settings.
        $this->enableContributionForTemplate($template->id(), $options);

        return $template;
    }

    /**
     * Enable contribution for a template in module settings.
     */
    protected function enableContributionForTemplate(int $templateId, array $options = []): void
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $contributeConfig = $settings->get('contribute_config') ?: [];

        // Set template as contributable.
        $contributeConfig['contribute_template_contributable'][$templateId] = true;

        // Set editable and fillable properties.
        $contributeConfig['contribute_template_editable'][$templateId] = $options['editable'] ?? [];
        $contributeConfig['contribute_template_fillable'][$templateId] = $options['fillable'] ?? [];

        $settings->set('contribute_config', $contributeConfig);
    }

    /**
     * Create a contribution for testing.
     *
     * @param ItemRepresentation|null $resource The resource to contribute to (null for new resource).
     * @param array $proposal The proposal data.
     * @param array $options Additional options (owner_id, email, submitted, validated, etc.).
     * @return ContributionRepresentation
     */
    protected function createContribution(
        ?ItemRepresentation $resource,
        array $proposal,
        array $options = []
    ): ContributionRepresentation {
        $user = $this->getServiceLocator()->get('Omeka\AuthenticationService')->getIdentity();

        $data = [
            'o:resource' => $resource ? ['o:id' => $resource->id()] : null,
            'o:owner' => isset($options['owner_id'])
                ? ['o:id' => $options['owner_id']]
                : ($user ? ['o:id' => $user->getId()] : null),
            'o:email' => $options['email'] ?? ($user ? $user->getEmail() : null),
            'o-module-contribute:patch' => $options['patch'] ?? ($resource !== null),
            'o-module-contribute:submitted' => $options['submitted'] ?? false,
            'o-module-contribute:undertaken' => $options['undertaken'] ?? false,
            'o-module-contribute:validated' => $options['validated'] ?? null,
            'o-module-contribute:proposal' => $proposal,
        ];

        $response = $this->api()->create('contributions', $data);
        $contribution = $response->getContent();
        $this->createdContributions[] = $contribution->id();

        return $contribution;
    }

    /**
     * Create a proposal array for a contribution.
     *
     * @param int $templateId Template ID
     * @param array $values Array of property values ['term' => ['original' => ..., 'proposed' => ...]]
     * @return array
     */
    protected function createProposal(int $templateId, array $values): array
    {
        $proposal = [
            'template' => $templateId,
            'media' => [],
        ];

        foreach ($values as $term => $termValues) {
            $proposal[$term] = [];
            foreach ($termValues as $value) {
                $proposal[$term][] = [
                    'original' => $value['original'] ?? ['@value' => null],
                    'proposed' => $value['proposed'] ?? ['@value' => null],
                ];
            }
        }

        return $proposal;
    }

    /**
     * Create a temporary test file for upload testing.
     *
     * @param string $filename The filename to create.
     * @param string $content The file content.
     * @return string The full path to the created file.
     */
    protected function createTestFile(string $filename, string $content = 'Test file content'): string
    {
        $basePath = $this->getServiceLocator()->get('Config')['file_store']['local']['base_path'] ?? null;
        if (!$basePath) {
            $basePath = OMEKA_PATH . '/files';
        }

        $contributionDir = $basePath . '/contribution';
        if (!is_dir($contributionDir)) {
            mkdir($contributionDir, 0755, true);
        }

        $filepath = $contributionDir . '/' . $filename;
        file_put_contents($filepath, $content);
        $this->createdTempFiles[] = $filepath;

        return $filepath;
    }

    /**
     * Create a test image file for upload testing.
     *
     * @param string $filename The filename to create.
     * @return string The full path to the created file.
     */
    protected function createTestImageFile(string $filename = 'test-image.png'): string
    {
        $basePath = $this->getServiceLocator()->get('Config')['file_store']['local']['base_path'] ?? null;
        if (!$basePath) {
            $basePath = OMEKA_PATH . '/files';
        }

        $contributionDir = $basePath . '/contribution';
        if (!is_dir($contributionDir)) {
            mkdir($contributionDir, 0755, true);
        }

        $filepath = $contributionDir . '/' . $filename;

        // Create a minimal valid PNG image (1x1 pixel).
        $image = imagecreatetruecolor(1, 1);
        imagepng($image, $filepath);
        imagedestroy($image);

        $this->createdTempFiles[] = $filepath;

        return $filepath;
    }

    /**
     * Simulate the validation process: convert contribution proposal to item data
     * and create the item with media.
     *
     * This method simulates what validateOrCreateOrUpdate() does in the controller,
     * but without requiring controller context.
     *
     * @param ContributionRepresentation $contribution The contribution to validate.
     * @return ItemRepresentation|null The created item, or null on failure.
     */
    protected function validateContributionAndCreateItem(ContributionRepresentation $contribution): ?ItemRepresentation
    {
        $proposal = $contribution->proposal();
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');

        // Build item data from proposal.
        $itemData = [];

        // Set template if available.
        if (!empty($proposal['template'])) {
            $itemData['o:resource_template'] = ['o:id' => $proposal['template']];
        }

        // Process property values.
        foreach ($proposal as $term => $values) {
            if (in_array($term, ['template', 'media', 'file'])) {
                continue;
            }

            $propertyId = $easyMeta->propertyId($term);
            if (!$propertyId) {
                continue;
            }

            $itemData[$term] = [];
            foreach ($values as $value) {
                $proposed = $value['proposed'] ?? null;
                if (!$proposed || !isset($proposed['@value'])) {
                    continue;
                }

                $valueData = [
                    'type' => 'literal',
                    'property_id' => $propertyId,
                    '@value' => $proposed['@value'],
                ];

                // Handle URI values.
                if (isset($proposed['@id'])) {
                    $valueData['type'] = 'uri';
                    $valueData['@id'] = $proposed['@id'];
                    if (isset($proposed['o:label'])) {
                        $valueData['o:label'] = $proposed['o:label'];
                    }
                }

                $itemData[$term][] = $valueData;
            }
        }

        // Process media.
        if (!empty($proposal['media'])) {
            $itemData['o:media'] = [];

            foreach ($proposal['media'] as $mediaProposal) {
                $mediaData = [];

                // Check for file.
                if (!empty($mediaProposal['file'][0]['proposed']['store'])) {
                    $fileProposed = $mediaProposal['file'][0]['proposed'];
                    $mediaData['o:ingester'] = 'contribution';
                    $mediaData['store'] = $fileProposed['store'];
                    $mediaData['o:source'] = $fileProposed['@value'] ?? $fileProposed['store'];
                }

                // Process media properties.
                foreach ($mediaProposal as $term => $values) {
                    if (in_array($term, ['template', 'file'])) {
                        continue;
                    }

                    $propertyId = $easyMeta->propertyId($term);
                    if (!$propertyId) {
                        continue;
                    }

                    $mediaData[$term] = [];
                    foreach ($values as $value) {
                        $proposed = $value['proposed'] ?? null;
                        if (!$proposed || !isset($proposed['@value'])) {
                            continue;
                        }

                        $mediaData[$term][] = [
                            'type' => 'literal',
                            'property_id' => $propertyId,
                            '@value' => $proposed['@value'],
                        ];
                    }
                }

                if (!empty($mediaData)) {
                    $itemData['o:media'][] = $mediaData;
                }
            }
        }

        // Set owner from contribution.
        $owner = $contribution->owner();
        if ($owner) {
            $itemData['o:owner'] = ['o:id' => $owner->id()];
        }

        // Create the item.
        try {
            $response = $this->api()->create('items', $itemData);
            $item = $response->getContent();

            // Track for cleanup.
            $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];

            // Update contribution to mark as validated and link to resource.
            $this->api()->update('contributions', $contribution->id(), [
                'o:resource' => ['o:id' => $item->id()],
                'o-module-contribute:validated' => true,
            ], [], ['isPartial' => true]);

            return $item;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if a file exists in the contribution directory.
     */
    protected function contributionFileExists(string $filename): bool
    {
        $basePath = $this->getServiceLocator()->get('Config')['file_store']['local']['base_path'] ?? null;
        if (!$basePath) {
            $basePath = OMEKA_PATH . '/files';
        }

        return file_exists($basePath . '/contribution/' . $filename);
    }

    /**
     * Check if a file exists in the original files directory.
     */
    protected function originalFileExists(string $filename): bool
    {
        $basePath = $this->getServiceLocator()->get('Config')['file_store']['local']['base_path'] ?? null;
        if (!$basePath) {
            $basePath = OMEKA_PATH . '/files';
        }

        return file_exists($basePath . '/original/' . $filename);
    }

    /**
     * Get the path to the fixtures directory.
     */
    protected function getFixturesPath(): string
    {
        return dirname(__DIR__) . '/ContributeTest/fixtures';
    }

    /**
     * Get a fixture file content.
     *
     * @param string $name Fixture filename.
     * @return string
     */
    protected function getFixture(string $name): string
    {
        $path = $this->getFixturesPath() . '/' . $name;
        if (!file_exists($path)) {
            throw new \RuntimeException("Fixture not found: $path");
        }
        return file_get_contents($path);
    }

    /**
     * Run a job synchronously for testing.
     *
     * @param string $jobClass Job class name.
     * @param array $args Job arguments.
     * @param bool $expectError If true, don't rethrow exceptions (for testing error cases).
     * @return Job
     */
    protected function runJob(string $jobClass, array $args, bool $expectError = false): Job
    {
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $auth = $services->get('Omeka\AuthenticationService');

        $job = new Job();
        $job->setStatus(Job::STATUS_STARTING);
        $job->setClass($jobClass);
        $job->setArgs($args);
        $job->setOwner($auth->getIdentity());

        $entityManager->persist($job);
        $entityManager->flush();

        $jobClass = $job->getClass();
        $jobInstance = new $jobClass($job, $services);
        $job->setStatus(Job::STATUS_IN_PROGRESS);
        $job->setStarted(new \DateTime('now'));
        $entityManager->flush();

        try {
            $jobInstance->perform();
            if ($job->getStatus() === Job::STATUS_IN_PROGRESS) {
                $job->setStatus(Job::STATUS_COMPLETED);
            }
        } catch (\Throwable $e) {
            $job->setStatus(Job::STATUS_ERROR);
            if (!$expectError) {
                throw $e;
            }
        }

        $job->setEnded(new \DateTime('now'));
        $entityManager->flush();

        return $job;
    }

    /**
     * Clean up created resources after test.
     */
    protected function cleanupResources(): void
    {
        // Delete created contributions first (they depend on resources).
        foreach ($this->createdContributions as $contributionId) {
            try {
                $this->api()->delete('contributions', $contributionId);
            } catch (\Exception $e) {
                // Ignore errors during cleanup.
            }
        }
        $this->createdContributions = [];

        // Delete created items.
        foreach ($this->createdResources as $resource) {
            try {
                $this->api()->delete($resource['type'], $resource['id']);
            } catch (\Exception $e) {
                // Ignore errors during cleanup.
            }
        }
        $this->createdResources = [];

        // Delete created templates.
        foreach ($this->createdTemplates as $templateId) {
            try {
                $this->api()->delete('resource_templates', $templateId);
            } catch (\Exception $e) {
                // Ignore errors during cleanup.
            }
        }
        $this->createdTemplates = [];

        // Delete created temp files.
        foreach ($this->createdTempFiles as $filepath) {
            if (file_exists($filepath)) {
                @unlink($filepath);
            }
        }
        $this->createdTempFiles = [];
    }

    /**
     * Get a site for testing.
     */
    protected function getTestSite()
    {
        $sites = $this->api()->search('sites', ['limit' => 1])->getContent();
        if (empty($sites)) {
            $response = $this->api()->create('sites', [
                'o:title' => 'Test Site',
                'o:slug' => 'test-site',
                'o:theme' => 'default',
            ]);
            return $response->getContent();
        }
        return reset($sites);
    }
}
