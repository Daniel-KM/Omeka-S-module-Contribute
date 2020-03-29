<?php
namespace Correction;

/**
 * @var Module $this
 * @var \Zend\ServiceManager\ServiceLocatorInterface $serviceLocator
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Api\Manager $api
 */
$services = $serviceLocator;
// $settings = $services->get('Omeka\Settings');
// $config = require dirname(dirname(__DIR__)) . '/config/module.config.php';
$connection = $services->get('Omeka\Connection');
// $entityManager = $services->get('Omeka\EntityManager');
// $plugins = $services->get('ControllerPluginManager');
// $api = $plugins->get('api');
// $space = strtolower(__NAMESPACE__);

if (version_compare($oldVersion, '3.0.10', '<')) {
    $sql = <<<'SQL'
ALTER TABLE correction_token
    CHANGE email email VARCHAR(190) DEFAULT NULL,
    CHANGE expire expire DATETIME DEFAULT NULL,
    CHANGE accessed accessed DATETIME DEFAULT NULL;
DROP INDEX token_idx ON correction_token;
CREATE INDEX correction_token_idx ON correction_token (token);
DROP INDEX expire_idx ON correction_token;
CREATE INDEX correction_expire_idx ON correction_token (expire);

ALTER TABLE correction
    CHANGE token_id token_id INT DEFAULT NULL,
    CHANGE email email VARCHAR(190) DEFAULT NULL,
    CHANGE modified modified DATETIME DEFAULT NULL;
DROP INDEX email_idx ON correction;
CREATE INDEX correction_email_idx ON correction (email);
DROP INDEX modified_idx ON correction;
CREATE INDEX correction_modified_idx ON correction (modified);
SQL;

    // Use single statements for execution.
    // See core commit #2689ce92f.
    $sqls = array_filter(array_map('trim', explode(";\n", $sql)));
    foreach ($sqls as $sql) {
        $connection->exec($sql);
    }
}
