<?php declare(strict_types=1);

namespace Contribute\View\Helper;

use Laminas\View\Helper\AbstractHelper;

class CustomVocabBaseType extends AbstractHelper
{
    /**
     * @var ?array
     */
    protected $customVocabBaseTypes;

    public function __construct(?array $customVocabBaseTypes)
    {
        $this->customVocabBaseTypes = $customVocabBaseTypes;
    }

    /**
     * Get the sub type of a customvocab ("literal", "resource", "uri").
     *
     * @return array|string|null
     */
    public function __invoke($customVocabId = null)
    {
        if (is_null($this->customVocabBaseTypes)) {
            return is_null($customVocabId) ? [] : null;
        }
        return is_null($customVocabId)
            ? $this->customVocabBaseTypes
            : ($this->customVocabBaseTypes[(int) $customVocabId] ?? null);
    }
}
