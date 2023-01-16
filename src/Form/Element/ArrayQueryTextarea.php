<?php declare(strict_types=1);

namespace Contribute\Form\Element;

use Omeka\Form\Element\ArrayTextarea;

class ArrayQueryTextarea extends ArrayTextarea
{
    public function setValue($value)
    {
        $this->value = $this->arrayQueryToString($value);
        return $this;
    }

    public function getInputSpecification(): array
    {
        return [
            'name' => $this->getName(),
            'required' => false,
            'allow_empty' => true,
            'filters' => [
                [
                    'name' => \Laminas\Filter\Callback::class,
                    'options' => [
                        'callback' => [$this, 'stringToArrayQuery'],
                    ],
                ],
            ],
        ];
    }

    public function arrayQueryToString($array): string
    {
        if (is_string($array)) {
            return $array;
        }
        foreach ($array as &$query) {
            $query = urldecode((string) http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        }
        return parent::arrayToString(array_filter($array));
    }

    public function stringToArrayQuery($string): array
    {
        if (is_array($string)) {
            return $string;
        }
        $array = $this->asKeyValue
            ? $this->stringToKeyValues($string)
            : $this->stringToList($string);
        $query = [];
        foreach ($array as &$q) {
            parse_str($q, $query);
            unset($query['page'], $query['per_page'], $query['limit'], $query['offset'], $query['submit']);
            $q = array_filter($query, function ($v) {
                if (is_array($v)) {
                    // TODO Filter other useless values (properties, numeric, created...).
                    return count($v);
                }
                return strlen(trim((string) $v));
            });
        }
        return array_filter($array);
    }
}
