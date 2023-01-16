<?php declare(strict_types=1);

namespace Contribute;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Omeka\Module\Manager $moduleManager
 * @var \Omeka\Settings\Settings $settings
 */
$connection = $services->get('Omeka\Connection');
$settings = $services->get('Omeka\Settings');
$moduleManager = $services->get('Omeka\ModuleManager');
$correctionModule = $moduleManager->getModule('Correction');
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
$messenger = $plugins->get('messenger');

if (!$correctionModule) {
    return;
}

// Check if Correction was really installed.
try {
    $connection->fetchAll('SELECT id FROM correction LIMIT 1;');
} catch (\Exception $e) {
    return;
}

// Apply all upgrades.
$oldVersion = $correctionModule->getIni('version');
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
        $connection->executeStatement($sql);
    }

    $this->installAllResources();

    $resourceTemplate = $api->read('resource_templates', ['label' => 'Correction'])->getContent();
    $templateData = $settings->get('correction_resource_template_data', []);
    $templateData['corrigible'][(string) $resourceTemplate->id()] = ['dcterms:title', 'dcterms:description'];
    $templateData['fillable'][(string) $resourceTemplate->id()] = ['dcterms:title', 'dcterms:description'];
    $settings->set('correction_resource_template_data', $templateData);
    $settings->set('correction_template_editable', $resourceTemplate->id());
}

// Copy data. The tables are created during install.
$settings->set('contribute_notify', $settings->get('correction_notify'));
$settings->set('contribute_template_editable', $settings->get('correction_template_editable'));
$settings->set('contribute_properties_editable_mode', $settings->get('correction_properties_corrigible_mode'));
$settings->set('contribute_properties_editable', $settings->get('correction_properties_corrigible'));
$settings->set('contribute_properties_fillable_mode', $settings->get('correction_properties_fillable_mode'));
$settings->set('contribute_properties_fillable', $settings->get('correction_properties_fillable'));
$settings->set('contribute_properties_datatype', $settings->get('contribute_properties_datatype'));
$settings->set('contribute_property_queries', $settings->get('correction_property_queries'));
$settings->set('contribute_without_token', $settings->get('correction_without_token'));
$settings->set('contribute_token_duration', $settings->get('correction_token_duration'));
$settings->set('contribute_resource_template_data', $settings->get('correction_resource_template_data'));

$sql = <<<SQL
INSERT contribution (id, resource_id, token_id, email, reviewed, proposal, created, modified)
SELECT id, resource_id, token_id, email, reviewed, proposal, created, modified FROM correction;

INSERT contribution_token (id, resource_id, token, email, expire, created, accessed)
SELECT id, resource_id, token, email, expire, created, accessed FROM correction_token;

# Uninstall the module Correction.
ALTER TABLE correction DROP FOREIGN KEY FK_A29DA1B841DEE7B9;
ALTER TABLE correction DROP FOREIGN KEY FK_A29DA1B889329D25;
ALTER TABLE correction_token DROP FOREIGN KEY FK_FB07DAEE89329D25;
DROP TABLE correction;
DROP TABLE correction_token;

DELETE FROM setting WHERE id LIKE 'correction_%';

DELETE FROM module WHERE id = "Correction";
SQL;

$sqls = array_filter(array_map('trim', explode(";\n", $sql)));
foreach ($sqls as $sql) {
    $connection->executeStatement($sql);
}

// Convert the settings.

// Upgrade to the last version.
require_once __DIR__ . '/upgrade.php';

$message = new \Omeka\Stdlib\Message(
    'The module Correction was upgraded by module Contribute and uninstalled.' // @translate
);
$messenger->addWarning($message);
