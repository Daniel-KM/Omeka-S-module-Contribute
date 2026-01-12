<?php declare(strict_types=1);

namespace ContributeTest\Api;

use CommonTest\AbstractHttpControllerTestCase;
use ContributeTest\ContributeTestTrait;

class ContributionAdapterTest extends AbstractHttpControllerTestCase
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
     * @group api
     */
    public function testCreateContribution(): void
    {
        // Create a contributive template.
        $template = $this->createContributiveTemplate('Test Template', ['dcterms:title', 'dcterms:description']);

        // Create a proposal.
        $proposal = $this->createProposal($template->id(), [
            'dcterms:title' => [
                ['proposed' => ['@value' => 'Test Title']],
            ],
            'dcterms:description' => [
                ['proposed' => ['@value' => 'Test Description']],
            ],
        ]);

        // Create a contribution.
        $contribution = $this->createContribution(null, $proposal);

        $this->assertNotNull($contribution);
        $this->assertInstanceOf(\Contribute\Api\Representation\ContributionRepresentation::class, $contribution);
        $this->assertFalse($contribution->isSubmitted());
        $this->assertFalse($contribution->isUndertaken());
        $this->assertNull($contribution->isValidated());
    }

    /**
     * @group api
     */
    public function testCreateContributionForExistingItem(): void
    {
        // Create an item.
        $item = $this->createItem([
            'dcterms:title' => [['@value' => 'Original Title']],
        ]);

        // Create a contributive template and assign it to the item.
        $template = $this->createContributiveTemplate('Test Template', ['dcterms:title']);

        // Create a correction proposal.
        $proposal = $this->createProposal($template->id(), [
            'dcterms:title' => [
                [
                    'original' => ['@value' => 'Original Title'],
                    'proposed' => ['@value' => 'Corrected Title'],
                ],
            ],
        ]);

        // Create a contribution (patch).
        $contribution = $this->createContribution($item, $proposal, ['patch' => true]);

        $this->assertNotNull($contribution);
        $this->assertTrue($contribution->isPatch());
        $this->assertEquals($item->id(), $contribution->resource()->id());
    }

    /**
     * @group api
     */
    public function testContributionStatusTransitions(): void
    {
        $template = $this->createContributiveTemplate('Test Template');

        $proposal = $this->createProposal($template->id(), [
            'dcterms:title' => [['proposed' => ['@value' => 'Test']]],
        ]);

        $contribution = $this->createContribution(null, $proposal);

        // Initial state.
        $this->assertFalse($contribution->isSubmitted());
        $this->assertFalse($contribution->isUndertaken());
        $this->assertNull($contribution->isValidated());

        // Submit.
        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:submitted' => true,
        ], [], ['isPartial' => true]);

        $contribution = $this->api()->read('contributions', $contribution->id())->getContent();
        $this->assertTrue($contribution->isSubmitted());

        // Undertake.
        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:undertaken' => true,
        ], [], ['isPartial' => true]);

        $contribution = $this->api()->read('contributions', $contribution->id())->getContent();
        $this->assertTrue($contribution->isUndertaken());

        // Validate (true).
        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:validated' => true,
        ], [], ['isPartial' => true]);

        $contribution = $this->api()->read('contributions', $contribution->id())->getContent();
        $this->assertTrue($contribution->isValidated());
    }

    /**
     * @group api
     */
    public function testContributionThreeStateValidation(): void
    {
        $template = $this->createContributiveTemplate('Test Template');

        $proposal = $this->createProposal($template->id(), [
            'dcterms:title' => [['proposed' => ['@value' => 'Test']]],
        ]);

        $contribution = $this->createContribution(null, $proposal);

        // Initial state: null (undefined).
        $this->assertNull($contribution->isValidated());

        // Reject (false).
        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:validated' => false,
        ], [], ['isPartial' => true]);

        $contribution = $this->api()->read('contributions', $contribution->id())->getContent();
        $this->assertFalse($contribution->isValidated());

        // Reset to undefined (null).
        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:validated' => null,
        ], [], ['isPartial' => true]);

        $contribution = $this->api()->read('contributions', $contribution->id())->getContent();
        $this->assertNull($contribution->isValidated());

        // Validate (true).
        $this->api()->update('contributions', $contribution->id(), [
            'o-module-contribute:validated' => true,
        ], [], ['isPartial' => true]);

        $contribution = $this->api()->read('contributions', $contribution->id())->getContent();
        $this->assertTrue($contribution->isValidated());
    }

    /**
     * @group api
     */
    public function testSearchContributions(): void
    {
        $template = $this->createContributiveTemplate('Test Template');

        // Create multiple contributions with different states.
        $proposal = $this->createProposal($template->id(), [
            'dcterms:title' => [['proposed' => ['@value' => 'Test 1']]],
        ]);
        $contribution1 = $this->createContribution(null, $proposal, ['submitted' => false]);

        $proposal = $this->createProposal($template->id(), [
            'dcterms:title' => [['proposed' => ['@value' => 'Test 2']]],
        ]);
        $contribution2 = $this->createContribution(null, $proposal, ['submitted' => true]);

        $proposal = $this->createProposal($template->id(), [
            'dcterms:title' => [['proposed' => ['@value' => 'Test 3']]],
        ]);
        $contribution3 = $this->createContribution(null, $proposal, ['submitted' => true, 'undertaken' => true]);

        // Search all.
        $results = $this->api()->search('contributions')->getContent();
        $this->assertGreaterThanOrEqual(3, count($results));

        // Search submitted only.
        $results = $this->api()->search('contributions', ['submitted' => true])->getContent();
        $ids = array_map(fn($c) => $c->id(), $results);
        $this->assertContains($contribution2->id(), $ids);
        $this->assertContains($contribution3->id(), $ids);
        $this->assertNotContains($contribution1->id(), $ids);

        // Search undertaken.
        $results = $this->api()->search('contributions', ['undertaken' => true])->getContent();
        $ids = array_map(fn($c) => $c->id(), $results);
        $this->assertContains($contribution3->id(), $ids);
        $this->assertNotContains($contribution1->id(), $ids);
        $this->assertNotContains($contribution2->id(), $ids);
    }

    /**
     * @group api
     */
    public function testDeleteContribution(): void
    {
        $template = $this->createContributiveTemplate('Test Template');

        $proposal = $this->createProposal($template->id(), [
            'dcterms:title' => [['proposed' => ['@value' => 'To Delete']]],
        ]);

        $contribution = $this->createContribution(null, $proposal);
        $contributionId = $contribution->id();

        // Remove from cleanup list since we're testing delete.
        $this->createdContributions = array_filter(
            $this->createdContributions,
            fn($id) => $id !== $contributionId
        );

        // Delete.
        $this->api()->delete('contributions', $contributionId);

        // Verify it's gone.
        $this->expectException(\Omeka\Api\Exception\NotFoundException::class);
        $this->api()->read('contributions', $contributionId);
    }

    /**
     * Test that proposal data is correctly structured.
     *
     * Note: proposalToResourceData() requires controller context with logger.
     * This test verifies the proposal structure instead.
     *
     * @group api
     */
    public function testProposalDataStructure(): void
    {
        $template = $this->createContributiveTemplate('Test Template', ['dcterms:title', 'dcterms:description']);

        $proposal = $this->createProposal($template->id(), [
            'dcterms:title' => [
                ['proposed' => ['@value' => 'My Title']],
            ],
            'dcterms:description' => [
                ['proposed' => ['@value' => 'My Description']],
            ],
        ]);

        $contribution = $this->createContribution(null, $proposal);

        // Verify the proposal is stored correctly.
        $storedProposal = $contribution->proposal();

        $this->assertIsArray($storedProposal);
        $this->assertArrayHasKey('template', $storedProposal);
        $this->assertEquals($template->id(), $storedProposal['template']);

        $this->assertArrayHasKey('dcterms:title', $storedProposal);
        $this->assertArrayHasKey('dcterms:description', $storedProposal);

        // Check values in proposal structure.
        $this->assertEquals('My Title', $storedProposal['dcterms:title'][0]['proposed']['@value']);
        $this->assertEquals('My Description', $storedProposal['dcterms:description'][0]['proposed']['@value']);
    }
}
