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
$settings = $services->get('Omeka\Settings');
// $config = require dirname(dirname(__DIR__)) . '/config/module.config.php';
$connection = $services->get('Omeka\Connection');
// $entityManager = $services->get('Omeka\EntityManager');
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
// $space = strtolower(__NAMESPACE__);

if (version_compare($oldVersion, '3.0.10', '<')) {
    $this->checkAllResourcesToInstall();

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

    $this->installAllResources();

    $resourceTemplate = $api->read('resource_templates', ['label' => 'Correction'])->getContent();
    $templateData = $settings->get('correction_resource_template_data', []);
    $templateData['corrigible'][(string) $resourceTemplate->id()] = ['dcterms:title', 'dcterms:description'];
    $templateData['fillable'][(string) $resourceTemplate->id()] = ['dcterms:title', 'dcterms:description'];
    $settings->set('correction_resource_template_data', $templateData);
    $settings->set('correction_template_editable', $resourceTemplate->id());
}

$translator = $serviceLocator->get('MvcTranslator');
$messenger = new \Omeka\Mvc\Controller\Plugin\Messenger;
$message = new \Omeka\Stdlib\Message(sprintf(
    $translator->translate('This module is deprecated and will not receive new improvements any more. The module %1$sContribute%2$s replaces it.'), // @translate
    '<a href="https://github.com/Daniel-KM/Omeka-S-module-Contribute" target="_blank">', '</a>'
));
$message->setEscapeHtml(false);
$messenger->addWarning($message);
$message = new \Omeka\Stdlib\Message(
    $translator->translate('The upgrade from this old module to the new one is automatic.') // @translate
);
$messenger->addWarning($message);
