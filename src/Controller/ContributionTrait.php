<?php declare(strict_types=1);

namespace Contribute\Controller;

use Contribute\Api\Representation\ContributionRepresentation;
use Laminas\View\Model\JsonModel;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Stdlib\ErrorStore;
use Omeka\Stdlib\Message;

/**
 * @todo Move ContributionTrait to controller plugins.
 */
trait ContributionTrait
{
    /**
     * Check values of the exiting resource with the proposal and get api data.
     *
     * @todo Factorize with \Contribute\Site\ContributionController::prepareProposal()
     * @todo Factorize with \Contribute\View\Helper\ContributionFields
     * @todo Factorize with \Contribute\Api\Representation\ContributionRepresentation::proposalNormalizeForValidation()
     *
     * @todo Simplify when the status "is patch" or "new resource" (at least remove all original data).
     *
     * @param ContributionRepresentation $contribution
     * @param string|null $term Validate only a specific term.
     * @param int|null $proposedKey Validate only a specific key.
     * @return array Data to be used for api. Files for media are in key file.
     */
    protected function validateContribution(
        ContributionRepresentation $contribution,
        ?string $term = null,
        $proposedKey = null,
        ?bool $isSubTemplate = false,
        ?int $indexProposalMedia = null
    ): ?array {
        // The contribution requires a resource template in allowed templates.
        $contributive = $contribution->contributiveData();
        if (!$contributive->isContributive()) {
            return null;
        }

        // Right to update the resource is already checked.
        // There is always a resource template.
        if ($isSubTemplate) {
            $contributive = $contributive->contributiveMedia();
            // TODO Currently, only new media are managed as sub-resource: contribution for new resource, not contribution for existing item with media at the same time.
            $resource = null;
            $existingValues = [];
        } else {
            $resource = $contribution->resource();
            $existingValues = $resource ? $resource->values() : [];
        }

        $resourceTemplate = $contributive->template();
        $proposal = $contribution->proposalNormalizeForValidation($indexProposalMedia);
        $hasProposedKey = !is_null($proposedKey);

        $propertyIds = $this->propertyIdsByTerms();
        $customVocabBaseTypes = $this->viewHelpers()->get('customVocabBaseType')();

        // TODO How to update only one property to avoid to update unmodified terms? Not possible with core resource hydration. Simple optimization anyway.

        $data = [
            'o:resource_template' => null,
            'o:resource_class' => null,
            'o:media' => [],
            'file' => [],
        ];
        if ($resourceTemplate) {
            $resourceClass = $resourceTemplate->resourceClass();
            $data['o:resource_template'] = ['o:id' => $resourceTemplate->id()];
            $data['o:resource_class'] = $resourceClass ? ['o:id' => $resourceClass->id()] : null;
        }

        // File is specific: for media only, one value only, not updatable,
        // not a property and not in resource template.
        if (isset($proposal['file'][0]['proposed']['@value']) && $proposal['file'][0]['proposed']['@value'] !== '') {
            $data['o:ingester'] = 'contribution';
            $data['o:source'] = $proposal['file'][0]['proposed']['@value'];
            $data['store'] = $proposal['file'][0]['proposed']['store'] ?? null;
        }

        // Clean data for the special keys.
        $proposalMedias = $isSubTemplate ? [] : ($proposal['media'] ?? []);
        unset($proposal['template'], $proposal['media']);

        foreach ($existingValues as $term => $propertyData) {
            // Keep all existing values.
            $data[$term] = array_map(function ($v) {
                return $v->jsonSerialize();
            }, $propertyData['values']);
            if (!$contributive->isTermContributive($term)) {
                continue;
            }
            /** @var \Omeka\Api\Representation\ValueRepresentation $existingValue */
            foreach ($propertyData['values'] as $existingValue) {
                if (!isset($proposal[$term])) {
                    continue;
                }
                if (!$contributive->isTermDatatype($term, $existingValue->type())) {
                    continue;
                }

                // Values have no id and the order key is not saved, so the
                // check should be redone.
                $existingVal = $existingValue->value();
                $existingUri = $existingValue->uri();
                $existingResourceId = $existingValue->valueResource() ? $existingValue->valueResource()->id() : null;
                foreach ($proposal[$term] as $key => $proposition) {
                    if ($hasProposedKey && $proposedKey != $key) {
                        continue;
                    }
                    if ($proposition['validated']) {
                        continue;
                    }
                    if (!in_array($proposition['process'], ['remove', 'update'])) {
                        continue;
                    }

                    $isUri = array_key_exists('@uri', $proposition['original']);
                    $isResource = array_key_exists('@resource', $proposition['original']);
                    $isValue = array_key_exists('@value', $proposition['original']);

                    if ($isUri) {
                        if ($proposition['original']['@uri'] === $existingUri) {
                            switch ($proposition['process']) {
                                case 'remove':
                                    unset($data[$term][$key]);
                                    break;
                                case 'update':
                                    $data[$term][$key]['@id'] = $proposition['proposed']['@uri'];
                                    $data[$term][$key]['o:label'] = $proposition['proposed']['@label'];
                                    break;
                            }
                            break;
                        }
                    } elseif ($isResource) {
                        if ($proposition['original']['@resource'] === $existingResourceId) {
                            switch ($proposition['process']) {
                                case 'remove':
                                    unset($data[$term][$key]);
                                    break;
                                case 'update':
                                    $data[$term][$key]['value_resource_id'] = $proposition['proposed']['@resource'];
                                    break;
                            }
                            break;
                        }
                    } elseif ($isValue) {
                        if ($proposition['original']['@value'] === $existingVal) {
                            switch ($proposition['process']) {
                                case 'remove':
                                    unset($data[$term][$key]);
                                    break;
                                case 'update':
                                    $data[$term][$key]['@value'] = $proposition['proposed']['@value'];
                                    break;
                            }
                            break;
                        }
                    }
                }
            }
        }

        // Convert last remaining propositions into array.
        // Only process "append" should remain.
        foreach ($proposal as $term => $propositions) {
            if (!$contributive->isTermContributive($term)) {
                continue;
            }
            $propertyId = $propertyIds[$term] ?? null;
            if (!$propertyId) {
                continue;
            }

            $typeTemplate = null;
            if ($resourceTemplate) {
                $resourceTemplateProperty = $resourceTemplate->resourceTemplateProperty($propertyId);
                if ($resourceTemplateProperty) {
                    $typeTemplate = $resourceTemplateProperty->dataType();
                }
            }

            $baseType = null;
            $uriLabels = [];
            if (substr((string) $typeTemplate, 0, 12) === 'customvocab:') {
                $customVocabId = (int) substr($typeTemplate, 12);
                $baseType = $customVocabBaseTypes[$customVocabId] ?? 'literal';
                $uriLabels = $this->customVocabUriLabels($customVocabId);
            }

            foreach ($propositions as $key => $proposition) {
                if ($hasProposedKey && $proposedKey != $key) {
                    continue;
                }
                if ($proposition['validated']) {
                    continue;
                }
                if ($proposition['process'] !== 'append') {
                    continue;
                }

                if ($typeTemplate) {
                    $type = $typeTemplate;
                } elseif (array_key_exists('@uri', $proposition['original'])) {
                    $type = 'uri';
                } elseif (array_key_exists('@resource', $proposition['original'])) {
                    $type = 'resource';
                } elseif (array_key_exists('@value', $proposition['original'])) {
                    $type = 'literal';
                } else {
                    $type = 'unknown';
                }

                $typeColon = strtok($type, ':');
                switch ($type) {
                    case 'literal':
                    case 'boolean':
                    case 'html':
                    case 'xml':
                    case $typeColon === 'numeric':
                    case $typeColon === 'customvocab' && $baseType === 'literal':
                        $data[$term][] = [
                            'type' => $type,
                            'property_id' => $propertyId,
                            '@value' => $proposition['proposed']['@value'],
                            'is_public' => true,
                            // '@language' => null,
                        ];
                        break;
                    case $typeColon === 'resource':
                    case $typeColon === 'customvocab' && $baseType === 'resource':
                        $data[$term][] = [
                            'type' => $type,
                            'property_id' => $propertyId,
                            'o:label' => null,
                            'value_resource_id' => $proposition['proposed']['@resource'],
                            '@id' => null,
                            'is_public' => true,
                            '@language' => null,
                        ];
                        break;
                    case $typeColon === 'customvocab' && $baseType === 'uri':
                        $proposition['proposed']['@label'] = $uriLabels[$proposition['proposed']['@uri'] ?? ''] ?? '';
                        // No break.
                    case 'uri':
                    case $typeColon === 'valuesuggest':
                    case $typeColon === 'valuesuggestall':
                        $data[$term][] = [
                            'type' => $type,
                            'property_id' => $propertyId,
                            'o:label' => $proposition['proposed']['@label'],
                            '@id' => $proposition['proposed']['@uri'],
                            'is_public' => true,
                        ];
                        break;
                    default:
                        // Nothing.
                        continue 2;
                }
            }
        }

        if (!$isSubTemplate) {
            foreach ($proposalMedias ? array_keys($proposalMedias) : [] as $indexProposalMedia) {
                $indexProposalMedia = (int) $indexProposalMedia;
                // TODO Currently, only new media are managed as sub-resource: contribution for new resource, not contribution for existing item with media at the same time.
                $data['o:media'][$indexProposalMedia] = $this->validateContribution($contribution, $term, $proposedKey, true, $indexProposalMedia);
                unset($data['o:media'][$indexProposalMedia]['o:media']);
                unset($data['o:media'][$indexProposalMedia]['file']);
            }
        }

        return $data;
    }

    /**
     * Create or update a resource from data.
     */
    protected function validateOrCreateOrUpdate(
        ContributionRepresentation $contribution,
        array $resourceData,
        ErrorStore $errorStore,
        bool $reviewed = false,
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
        // @see validateContribution()
        unset($resourceData['file']);

        // TODO This is a new contribution, so a new item for now.
        $resourceName = $contributionResource ? $contributionResource->resourceName() : 'items';

        // Validate only: the simplest way is to skip flushing.
        // Nevertheless, a simple contributor has no right to create a resource.
        // So skip rights before and after.
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
            $apiOptions = ['flushEntityManager' => false, 'validateOnly' => true, 'isContribution' => true];
        } else {
            $apiOptions = [];
        }

        try {
            if ($contributionResource) {
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
                foreach ($errorStore->getErrors() as $messages) {
                    foreach ($messages as $message) {
                        if (is_array($message)) {
                            foreach ($message as $msg) {
                                $messenger->addError($this->translate($msg));
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
            $message = new Message(
                'Unable to store the resource of the contribution: %s', // @translate
                $e->getMessage()
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
        $data['o:resource'] = $validateOnly || !$contributionResource ? null : ['o:id' => $contributionResource->id()];
        $data['o-module-contribute:reviewed'] = $reviewed;
        $response = $this->api()
            ->update('contributions', $contribution->id(), $data, [], ['isPartial' => true]);

        return $contributionResource;
    }

    /**
     * Get the list of uris and labels of a specific custom vocab.
     *
     * @see \CustomVocab\DataType\CustomVocab::getUriForm()
     */
    protected function customVocabUriLabels(int $customVocabId): array
    {
        static $uriLabels = [];
        if (!isset($uriLabels[$customVocabId])) {
            $uris = $this->api()->searchOne('custom_vocabs', ['id' => $customVocabId], ['returnScalar' => 'uris'])->getContent();
            $uris = array_map('trim', preg_split("/\r\n|\n|\r/", (string) $uris));
            $matches = [];
            $values = [];
            foreach ($uris as $uri) {
                if (preg_match('/^(\S+) (.+)$/', $uri, $matches)) {
                    $values[$matches[1]] = $matches[2];
                } elseif (preg_match('/^(.+)/', $uri, $matches)) {
                    $values[$matches[1]] = '';
                }
            }
            $uriLabels[$customVocabId] = $values;
        }
        return $uriLabels[$customVocabId];
    }

    protected function jsonErrorUnauthorized($message = null, $errors = null): JsonModel
    {
        return $this->returnError($message ?? $this->translate('Unauthorized access.'), 'error', $errors); // @translate
    }

    protected function jsonErrorNotFound($message = null, $errors = null): JsonModel
    {
        return $this->returnError($message && $this->translate('Resource not found.'), 'error', $errors); // @translate
    }

    protected function jsonErrorUpdate($message = null, $errors = null): JsonModel
    {
        return $this->returnError($message ?? $this->translate('An internal error occurred.'), 'error', $errors); // @translate
    }

    protected function returnError($message, string $statusCode = 'error', $errors = null): JsonModel
    {
        $result = [
            'status' => $statusCode,
            'message' => $message,
        ];
        if (is_array($errors) && count($errors)) {
            $result['data'] = $errors;
        } elseif (is_object($errors) && $errors instanceof ErrorStore && $errors->hasErrors()) {
            $result['data'] = $errors->getErrors();
        }
        return new JsonModel($result);
    }
}
