<?php declare(strict_types=1);
namespace Contribute;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Api\Manager $api
 */
$settings = $services->get('Omeka\Settings');
// $config = require dirname(dirname(__DIR__)) . '/config/module.config.php';
$connection = $services->get('Omeka\Connection');
// $entityManager = $services->get('Omeka\EntityManager');
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
// $space = strtolower(__NAMESPACE__);

if (version_compare($oldVersion, '3.0.13', '<')) {
    $sql = <<<'SQL'
ALTER TABLE `contribution`
CHANGE `resource_id` `resource_id` int(11) NULL AFTER `id`,
ADD `owner_id` int(11) NULL AFTER `resource_id`;

ALTER TABLE `contribution`
DROP FOREIGN KEY `FK_EA351E1589329D25`,
ADD FOREIGN KEY (`FK_EA351E1589329D25`) REFERENCES `resource` (`id`) ON DELETE SET NULL;

ALTER TABLE `contribution`
ADD CONSTRAINT `FK_EA351E157E3C61F9` FOREIGN KEY (`owner_id`) REFERENCES `user` (`id`) ON DELETE SET NULL;
SQL;
    // Use single statements for execution.
    // See core commit #2689ce92f.
    $sqls = array_filter(array_map('trim', explode(";\n", $sql)));
    foreach ($sqls as $sql) {
        $connection->exec($sql);
    }
}

if (version_compare($oldVersion, '3.3.13.0', '<')) {
    // @link https://www.doctrine-project.org/projects/doctrine-dbal/en/2.6/reference/types.html#array-types
    $sql = <<<'SQL'
ALTER TABLE `contribution`
CHANGE `proposal` `proposal` LONGTEXT NOT NULL COMMENT '(DC2Type:json)';
SQL;
    $connection->exec($sql);
}
