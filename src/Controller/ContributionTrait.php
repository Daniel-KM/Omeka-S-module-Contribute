<?php declare(strict_types=1);

namespace Contribute\Controller;

use AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation;
use Common\Stdlib\PsrMessage;
use Contribute\Api\Representation\ContributionRepresentation;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Stdlib\ErrorStore;

/**
 * @todo Move ContributionTrait to controller plugins.
 */
trait ContributionTrait
{
    /**
     * Create or update a resource from data.
     */
    protected function validateOrCreateOrUpdate(
        ContributionRepresentation $contribution,
        array $resourceData,
        ErrorStore $errorStore,
        // Undertaken and validated are for creating.
        // When updating, it may or may not be updated.
        // Values may be null for validated, bool or empty string for unchanged.
        $undertaken = '',
        // May be null
        $validated = '',
        bool $validateOnly = false,
        bool $useMessenger = false
    ): ?AbstractResourceEntityRepresentation {
        $contributionResource = $contribution->resource();

        // Nothing to update or create.
        if (!$resourceData) {
            return $contributionResource;
        }

        // Prepare the api to throw a validation exception with error store.
        /** @var \Omeka\Mvc\Controller\Plugin\Api $api */
        $api = $this->api(null, true);

        // Files are managed through media (already stored).
        // @see proposalToResourceData()
        unset($resourceData['file']);

        // TODO This is a new contribution, so a new item for now.
        $resourceName = $contributionResource ? $contributionResource->resourceName() : 'items';

        // Validate only: the simplest way is to skip flushing.
        // Nevertheless, a simple contributor has no right to create a resource.
        // So skip rights before and remove skip after.
        // But some other modules can persist it inadvertently (?)
        // So use api, and add an event to add an error to the error store and
        // check if it is the only one.
        // TODO Fix the modules that flush too much early.
        // TODO Improve the api manager with method or option "validateOnly"?
        // TODO Add a method to get the error store from the api without using exception.
        $isAllowed = null;
        if ($validateOnly) {
            // Flush before and clear after to avoid possible issues.
            $this->entityManager->flush();

            /** * @var \Omeka\Permissions\Acl $acl */
            $acl = $contribution->getServiceLocator()->get('Omeka\Acl');
            $classes = [
                'items' => 'Item',
                'item_sets' => 'ItemSet',
                'media' => 'Media',
            ];
            $class = $classes[$resourceName] ?? 'Item';
            $entityClass = 'Omeka\Entity\\' . $class;
            $action = $contributionResource ? 'update' : 'create';
            $isAllowed = $acl->userIsAllowed($entityClass, $action);
            if (!$isAllowed) {
                $user = $this->identity();
                $classes = [
                    \Omeka\Entity\Item::class,
                    \Omeka\Entity\Media::class,
                    \Omeka\Entity\ItemSet::class,
                    \Omeka\Api\Adapter\ItemAdapter::class,
                    \Omeka\Api\Adapter\MediaAdapter::class,
                    \Omeka\Api\Adapter\ItemSetAdapter::class,
                ];
                $acl->allow($user ? $user->getRole() : null, $classes, [$action, 'change-owner']);
            }
            $apiOptions = [
                'flushEntityManager' => false,
                'validateOnly' => true,
                'isContribution' => true,
            ];
        } else {
            $apiOptions = [];
        }

        try {
            if ($contributionResource) {
                // During an update of items, keep existing media in any cases.
                // TODO Move this check in proposalToResourceData(). Do it for item sets and sites too.
                // @link https://gitlab.com/Daniel-KM/Omeka-S-module-Contribute/-/issues/3
                if ($resourceName === 'items') {
                    unset(
                        $resourceData['o:media'],
                        $resourceData['o:primary_media'],
                        $resourceData['o:item_set'],
                        $resourceData['o:site']
                    );
                }
                $apiOptions['isPartial'] = true;
                $response = $api
                    ->update($resourceName, $contributionResource->id(), $resourceData, [], $apiOptions);
            } else {
                // The validator is not the contributor.
                // The validator will be added automatically for anonymous.
                $owner = $contribution->owner() ?: null;
                $resourceData['o:owner'] = $owner ? ['o:id' => $owner->id()] : null;
                $resourceData['o:is_public'] = false;
                $response = $api
                    ->create($resourceName, $resourceData, [], $apiOptions);
            }
        } catch (\Omeka\Api\Exception\ValidationException $e) {
            $this->entityManager->clear();
            $exceptionErrorStore = $e->getErrorStore();
            // Check if there is only one error in case of validation only.
            if ($validateOnly) {
                $errors = $exceptionErrorStore->getErrors();
                // Because validateOnly is the last error and added only when
                // there is no other one, it should be alone when no issue.
                if (!count($errors)
                    || (count($errors) === 1 && !empty($errors['validateOnly']))
                ) {
                    $errors = [];
                } else {
                    $errorStore->mergeErrors($exceptionErrorStore);
                    $errors = $errorStore->getErrors();
                }
            } else {
                $errorStore->mergeErrors($exceptionErrorStore);
                $errors = $errorStore->getErrors();
            }
            if ($useMessenger && $errors) {
                /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger */
                $messenger = $this->messenger();
                // Nested forms with medias create multiple levels of messages.
                // The module fixes core, but may be absent.
                foreach ($errorStore->getErrors() as $messages) {
                    foreach ($messages as $message) {
                        if (is_array($message)) {
                            foreach ($message as $msg) {
                                if (is_array($msg)) {
                                    foreach ($msg as $mg) {
                                        $messenger->addError($this->translate($mg));
                                    }
                                } else {
                                    $messenger->addError($this->translate($msg));
                                }
                            }
                        } else {
                            $messenger->addError($this->translate($message));
                        }
                    }
                }
            }
            if ($isAllowed === false) {
                $acl->deny($user ? $user->getRole() : null, $classes, [$action, 'change-owner']);
            }
            return null;
        } catch (\Exception $e) {
            $this->entityManager->clear();
            $message = new PsrMessage(
                'Unable to store the resource of the contribution: {message}', // @translate
                ['message' => $e->getMessage()]
            );
            $this->logger()->err($message);
            if ($useMessenger) {
                $this->messenger()->addError($message);
            } else {
                $errorStore->addError('store', $message);
            }
            if ($isAllowed === false) {
                $acl->deny($user ? $user->getRole() : null, $classes, [$action, 'change-owner']);
            }
            return null;
        }

        if ($isAllowed === false) {
            $acl->deny($user ? $user->getRole() : null, $classes, [$action, 'change-owner']);
        }

        // Normally, not possible here.
        if ($validateOnly) {
            $this->entityManager->clear();
            return null;
        }

        // The exception is thrown in the api, there is always a response.
        $contributionResource = $response->getContent();

        $data = [];
        $data['o:resource'] = $validateOnly || !$contributionResource ? null : [
            'o:id' => $contributionResource->id()
        ];
        if ($undertaken !== '') {
            $data['o-module-contribute:undertaken'] = $undertaken;
        }
        if ($validated !== '') {
            $data['o-module-contribute:validated'] = $validated;
        }
        $response = $this->api()
            ->update('contributions', $contribution->id(), $data, [], ['isPartial' => true]);

        return $contributionResource;
    }

    protected function settingTemplateOrMainOrConfig($resourceOrTemplateOrResourceIds, string $key, bool $includeConfig = false, ?string $resourceType = null)
    {
        if (!$resourceOrTemplateOrResourceIds) {
            return null;
        } elseif (is_array($resourceOrTemplateOrResourceIds)) {
            // Get the list of resource templates ids from the ids.
            // TODO For now, api does not allow to search mixed resources.
            // Here, all resource ids are the same resource type.
            $templateIds = $this->api()->search($resourceType ?? 'items',
                ['id' => $resourceOrTemplateOrResourceIds],
                ['returnScalar' => 'resourceTemplate']
            )->getContent();
            $templateIds = array_unique($templateIds);
            if ($templateIds) {
                // TODO For now, get the settings from the first template only. Else min/max/merge…?
                $templateId = reset($templateIds);
            } else {
                $templateId = null;
            }
        } elseif ($resourceOrTemplateOrResourceIds instanceof AbstractResourceEntityRepresentation
            || $resourceOrTemplateOrResourceIds instanceof ContributionRepresentation
        ) {
            $templateId = $resourceOrTemplateOrResourceIds->resourceTemplate();
            $templateId = $templateId ? $templateId->id() : null;
        } elseif ($resourceOrTemplateOrResourceIds instanceof ResourceTemplateRepresentation) {
            $templateId = $resourceOrTemplateOrResourceIds->id();
        } else {
            return null;
        }

        if ($templateId) {
            $settings = $this->settings();
            $contributeConfig = $settings->get('contribute_config') ?: [];
            if (array_key_exists($key, $contributeConfig) && array_key_exists($templateId, $contributeConfig[$key])) {
                $val = $contributeConfig[$key][$templateId];
                if ($val !== null && $val !== '' && $val !== []) {
                    return $val;
                }
            }
        }

        $val = $this->settings()->get($key);
        if ($val !== null && $val !== '' && $val !== []) {
            return $val;
        } elseif ($includeConfig) {
            $contributeConfig = dirname(__DIR__, 2) . '/config/module.config.php';
            return $contributeConfig['contribute']['settings'][$key] ?? null;
        } else {
            return $val;
        }
    }
}
