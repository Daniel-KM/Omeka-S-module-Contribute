<?php declare(strict_types=1);

namespace Contribute\Service\ControllerPlugin;

use Contribute\Mvc\Controller\Plugin\PropertyIdsByTerms;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class PropertyIdsByTermsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');
        $qb = $connection->createQueryBuilder();
        $qb
            ->select(
                'CONCAT(vocabulary.prefix, ":", property.local_name) AS term',
                'property.id AS id'
            )
            ->from('property', 'property')
            ->innerJoin('property', 'vocabulary', 'vocabulary', 'property.vocabulary_id = vocabulary.id')
            ->addGroupBy('property.id')
        ;
        $properties = array_map('intval', $connection->executeQuery($qb)->fetchAllKeyValue());
        return new PropertyIdsByTerms($properties);
    }
}
