<?php declare(strict_types=1);

namespace Contribute\View\Helper;

use Laminas\View\Helper\AbstractHelper;

class CustomVocabSubType extends AbstractHelper
{
    /**
     * @var ?array
     */
    protected $customVocabSubTypes;

    public function __construct(?array $customVocabSubTypes)
    {
        $this->customVocabSubTypes = $customVocabSubTypes;
    }

    /**
     * Get the sub type of a customvocab ("literal", "resource", "uri").
     *
     * @return array|string|null
     */
    public function __invoke($customVocabId = null)
    {
        return is_null($customVocabId)
            ? $this->customVocabSubTypes
            : ($this->customVocabSubTypes[(int) $customVocabId] ?? null);
    }
}
