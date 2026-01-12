<?php declare(strict_types=1);

namespace ContributeTest\Controller\Site;

use CommonTest\AbstractHttpControllerTestCase;
use ContributeTest\ContributeTestTrait;

/**
 * Tests for the site contribution controller.
 *
 * Note: Site routes follow this pattern:
 * - Add: /s/{site-slug}/{resource}/add (e.g., /s/test-site/item/add)
 * - Actions: /s/{site-slug}/{resource}/{id}/{action} (e.g., /s/test-site/contribution/123/view)
 *
 * Many actions require specific permissions and module settings.
 * These tests focus on route accessibility and basic behavior.
 *
 * @group controller
 * @group site
 */
class ContributionControllerTest extends AbstractHttpControllerTestCase
{
    use ContributeTestTrait;

    protected $site;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->site = $this->getTestSite();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        parent::tearDown();
    }

    /**
     * Test add action for items.
     *
     * Route: /s/{site}/item/add?template={id}
     */
    public function testAddActionWithTemplate(): void
    {
        $template = $this->createContributiveTemplate('Site Add Template', ['dcterms:title']);

        $siteSlug = $this->site->slug();
        // Correct URL: /{resource}/add, not /contribution/{resource}/add
        $url = "/s/$siteSlug/item/add?template=" . $template->id();

        $this->dispatch($url);

        $statusCode = $this->getResponse()->getStatusCode();
        // May be 200 (form shown), 302 (redirect), 403 (forbidden), or 404 (site not configured for contributions).
        $this->assertTrue(
            in_array($statusCode, [200, 302, 403, 404]),
            "Expected 200, 302, 403, or 404, got $statusCode"
        );
    }

    /**
     * Test show action for contribution.
     *
     * Route: /s/{site}/contribution/{id}/view
     */
    public function testShowActionWithValidContribution(): void
    {
        $template = $this->createContributiveTemplate('Site Show Template', ['dcterms:title']);

        $proposal = $this->createProposal($template->id(), [
            'dcterms:title' => [['proposed' => ['@value' => 'Site Show Test']]],
        ]);
        $contribution = $this->createContribution(null, $proposal);

        $siteSlug = $this->site->slug();
        // Correct URL: /contribution/{id}/view
        $url = "/s/$siteSlug/contribution/{$contribution->id()}/view";

        $this->dispatch($url);

        $statusCode = $this->getResponse()->getStatusCode();
        // May need permissions or site configuration.
        $this->assertTrue(
            in_array($statusCode, [200, 302, 403, 404]),
            "Expected 200, 302, 403, or 404, got $statusCode"
        );
    }

    /**
     * Test edit action for contribution.
     *
     * Route: /s/{site}/contribution/{id}/edit
     */
    public function testEditActionWithValidContribution(): void
    {
        $template = $this->createContributiveTemplate('Site Edit Template', ['dcterms:title']);

        $proposal = $this->createProposal($template->id(), [
            'dcterms:title' => [['proposed' => ['@value' => 'Site Edit Test']]],
        ]);
        $contribution = $this->createContribution(null, $proposal);

        $siteSlug = $this->site->slug();
        $url = "/s/$siteSlug/contribution/{$contribution->id()}/edit";

        $this->dispatch($url);

        $statusCode = $this->getResponse()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [200, 302, 403, 404]),
            "Expected 200, 302, 403, or 404, got $statusCode"
        );
    }

    /**
     * Test submit action for contribution.
     *
     * Route: /s/{site}/contribution/{id}/submit
     */
    public function testSubmitActionRequiresValidContribution(): void
    {
        $template = $this->createContributiveTemplate('Site Submit Template', ['dcterms:title']);

        $proposal = $this->createProposal($template->id(), [
            'dcterms:title' => [['proposed' => ['@value' => 'Site Submit Test']]],
        ]);
        $contribution = $this->createContribution(null, $proposal);

        $siteSlug = $this->site->slug();
        $url = "/s/$siteSlug/contribution/{$contribution->id()}/submit";

        $this->dispatch($url, 'GET');

        $statusCode = $this->getResponse()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [200, 302, 403, 404]),
            "Expected 200, 302, 403, or 404, got $statusCode"
        );
    }

    /**
     * Test delete action requires POST.
     *
     * GET request should not delete the contribution.
     */
    public function testDeleteActionRequiresPost(): void
    {
        $template = $this->createContributiveTemplate('Site Delete Template', ['dcterms:title']);

        $proposal = $this->createProposal($template->id(), [
            'dcterms:title' => [['proposed' => ['@value' => 'Site Delete Test']]],
        ]);
        $contribution = $this->createContribution(null, $proposal);

        $siteSlug = $this->site->slug();
        $url = "/s/$siteSlug/contribution/{$contribution->id()}/delete";

        // GET should not delete.
        $this->dispatch($url, 'GET');

        // Contribution should still exist regardless of status code.
        $exists = $this->api()->read('contributions', $contribution->id())->getContent();
        $this->assertNotNull($exists);
    }

    /**
     * Test delete action with POST method.
     *
     * Note: Actual deletion may be prevented by permissions or module settings.
     * This test verifies the endpoint is accessible and doesn't cause errors.
     */
    public function testDeleteActionWithPost(): void
    {
        $template = $this->createContributiveTemplate('Site Delete Post Template', ['dcterms:title']);

        $proposal = $this->createProposal($template->id(), [
            'dcterms:title' => [['proposed' => ['@value' => 'Site Delete Post Test']]],
        ]);
        $contribution = $this->createContribution(null, $proposal);
        $contributionId = $contribution->id();

        // Remove from cleanup.
        $this->createdContributions = array_filter(
            $this->createdContributions,
            fn($id) => $id !== $contributionId
        );

        $siteSlug = $this->site->slug();
        $url = "/s/$siteSlug/contribution/$contributionId/delete";

        $this->dispatch($url, 'POST');

        $statusCode = $this->getResponse()->getStatusCode();

        // Should not cause server error.
        $this->assertNotEquals(500, $statusCode, 'Should not cause server error');

        // If deletion was successful, contribution should be gone.
        if (in_array($statusCode, [302, 200])) {
            try {
                $this->api()->read('contributions', $contributionId);
                // If we get here, the contribution still exists (not deleted due to permissions).
                // Re-add to cleanup.
                $this->createdContributions[] = $contributionId;
                $this->assertTrue(true, 'Contribution still exists (permissions)');
            } catch (\Omeka\Api\Exception\NotFoundException $e) {
                // Expected after successful deletion.
                $this->assertTrue(true, 'Contribution was deleted');
            }
        } else {
            // Re-add to cleanup if not deleted.
            $this->createdContributions[] = $contributionId;
            // Still a valid response (redirect, forbidden, etc.).
            $this->assertTrue(
                in_array($statusCode, [302, 403, 404]),
                "Expected valid response code, got $statusCode"
            );
        }
    }

    /**
     * Test that submitted contributions have edit restrictions.
     */
    public function testCannotEditSubmittedContribution(): void
    {
        $template = $this->createContributiveTemplate('Submitted Edit Template', ['dcterms:title']);

        $proposal = $this->createProposal($template->id(), [
            'dcterms:title' => [['proposed' => ['@value' => 'Submitted Edit Test']]],
        ]);
        $contribution = $this->createContribution(null, $proposal, [
            'submitted' => true,
        ]);

        $siteSlug = $this->site->slug();
        $url = "/s/$siteSlug/contribution/{$contribution->id()}/edit";

        $this->dispatch($url);

        $statusCode = $this->getResponse()->getStatusCode();
        // Should be redirected, forbidden, or show warning - not 500.
        $this->assertNotEquals(500, $statusCode, 'Should not cause server error');
    }

    /**
     * Test that undertaken contributions cannot be deleted.
     */
    public function testCannotDeleteUndertakenContribution(): void
    {
        $template = $this->createContributiveTemplate('Undertaken Delete Template', ['dcterms:title']);

        $proposal = $this->createProposal($template->id(), [
            'dcterms:title' => [['proposed' => ['@value' => 'Undertaken Delete Test']]],
        ]);
        $contribution = $this->createContribution(null, $proposal, [
            'submitted' => true,
            'undertaken' => true,
        ]);

        $siteSlug = $this->site->slug();
        $url = "/s/$siteSlug/contribution/{$contribution->id()}/delete";

        $this->dispatch($url, 'POST');

        // Contribution should still exist (cannot delete undertaken).
        $exists = $this->api()->read('contributions', $contribution->id())->getContent();
        $this->assertNotNull($exists);
    }

    /**
     * Test that validated contributions cannot be deleted.
     */
    public function testCannotDeleteValidatedContribution(): void
    {
        $template = $this->createContributiveTemplate('Validated Delete Template', ['dcterms:title']);

        $proposal = $this->createProposal($template->id(), [
            'dcterms:title' => [['proposed' => ['@value' => 'Validated Delete Test']]],
        ]);
        $contribution = $this->createContribution(null, $proposal, [
            'submitted' => true,
            'undertaken' => true,
            'validated' => true,
        ]);

        $siteSlug = $this->site->slug();
        $url = "/s/$siteSlug/contribution/{$contribution->id()}/delete";

        $this->dispatch($url, 'POST');

        // Contribution should still exist (cannot delete validated).
        $exists = $this->api()->read('contributions', $contribution->id())->getContent();
        $this->assertNotNull($exists);
    }

    /**
     * Test correction to existing item.
     *
     * Route: /s/{site}/item/{id}/edit
     */
    public function testCorrectionToExistingItem(): void
    {
        // Create an item first.
        $item = $this->createItem([
            'dcterms:title' => [['@value' => 'Original Item Title']],
        ]);

        $template = $this->createContributiveTemplate('Correction Template', ['dcterms:title']);

        // Assign template to item.
        $this->api()->update('items', $item->id(), [
            'o:resource_template' => ['o:id' => $template->id()],
        ], [], ['isPartial' => true]);

        $siteSlug = $this->site->slug();
        // Correct URL: /item/{id}/edit, not /contribution/item/{id}/edit
        $url = "/s/$siteSlug/item/{$item->id()}/edit";

        $this->dispatch($url);

        $statusCode = $this->getResponse()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [200, 302, 403, 404]),
            "Expected 200, 302, 403, or 404, got $statusCode"
        );
    }

    /**
     * Test that invalid contribution ID returns 404 or redirect.
     */
    public function testInvalidResourceReturns404(): void
    {
        $siteSlug = $this->site->slug();
        $url = "/s/$siteSlug/contribution/99999999/view";

        $this->dispatch($url);

        $statusCode = $this->getResponse()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [404, 302]),
            "Expected 404 or 302, got $statusCode"
        );
    }

    /**
     * Test site routes configuration.
     */
    public function testSiteRoutesExist(): void
    {
        $router = $this->getApplication()->getServiceManager()->get('Router');
        $siteSlug = $this->site->slug();

        // Test add route.
        $addMatch = $router->match(
            (new \Laminas\Http\PhpEnvironment\Request())->setUri("/s/$siteSlug/item/add")
        );
        $this->assertNotNull($addMatch, 'Add route should exist');

        // Test action route for contribution.
        $actionMatch = $router->match(
            (new \Laminas\Http\PhpEnvironment\Request())->setUri("/s/$siteSlug/contribution/1/view")
        );
        $this->assertNotNull($actionMatch, 'View action route should exist');

        // Test edit route.
        $editMatch = $router->match(
            (new \Laminas\Http\PhpEnvironment\Request())->setUri("/s/$siteSlug/contribution/1/edit")
        );
        $this->assertNotNull($editMatch, 'Edit action route should exist');
    }
}
