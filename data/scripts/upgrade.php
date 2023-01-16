<?php declare(strict_types=1);

namespace Contribute;

use Omeka\Module\Exception\ModuleCannotInstallException;
use Omeka\Stdlib\Message;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
// $config = require dirname(dirname(__DIR__)) . '/config/module.config.php';
$settings = $services->get('Omeka\Settings');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
// $entityManager = $services->get('Omeka\EntityManager');

if (version_compare($oldVersion, '3.0.13', '<')) {
    $sqls = '';
    $keys = [
        'FK_EA351E1589329D25',
    ];
    $sm = $connection->getSchemaManager();
    $foreignKeys = $sm->listTableForeignKeys('contribution');
    foreach ($foreignKeys as $foreignKey) {
        if ($foreignKey && in_array(strtoupper($foreignKey->getName()), $keys)) {
            $sqls .= "ALTER TABLE `contribution` DROP FOREIGN KEY {$foreignKey->getName()};\n";
        }
    }

    $sqls .= <<<'SQL'
ALTER TABLE `contribution`
CHANGE `resource_id` `resource_id` int(11) NULL AFTER `id`,
ADD `owner_id` int(11) NULL AFTER `resource_id`;

ALTER TABLE `contribution`
ADD FOREIGN KEY (`FK_EA351E1589329D25`) REFERENCES `resource` (`id`) ON DELETE SET NULL;

ALTER TABLE `contribution`
ADD CONSTRAINT `FK_EA351E157E3C61F9` FOREIGN KEY (`owner_id`) REFERENCES `user` (`id`) ON DELETE SET NULL;
SQL;
    // Use single statements for execution.
    // See core commit #2689ce92f.
    foreach (array_filter(array_map('trim', explode(";\n", $sqls))) as $sql) {
        $connection->executeStatement($sql);
    }
}

if (version_compare($oldVersion, '3.3.0.13', '<')) {
    $module = $services->get('Omeka\ModuleManager')->getModule('Generic');
    if ($module && version_compare($module->getIni('version') ?? '', '3.3.28', '<')) {
        $translator = $services->get('MvcTranslator');
        $message = new \Omeka\Stdlib\Message(
            $translator->translate('This module requires the module "%s", version %s or above.'), // @translate
            'Generic', '3.3.28'
        );
        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
    }

    $this->checkDependencies();

    // @link https://www.doctrine-project.org/projects/doctrine-dbal/en/2.6/reference/types.html#array-types
    $sql = <<<'SQL'
ALTER TABLE `contribution`
CHANGE `proposal` `proposal` LONGTEXT NOT NULL COMMENT '(DC2Type:json)';
SQL;
    $connection->executeStatement($sql);

    $contributeTemplateData = $settings->get('contribute_resource_template_data', []);
    $byTemplate = [];
    foreach ($contributeTemplateData as $action => $templateData) {
        if (!in_array($action, ['editable', 'fillable'])) {
            continue;
        }
        foreach ($templateData as $templateId => $terms) {
            if (!empty($terms)) {
                foreach ($terms as $term) {
                    $byTemplate[$templateId][$term][$action] = true;
                }
            }
        }
    }

    $qb = $connection->createQueryBuilder();
    $qb
        ->select(
            'DISTINCT property.id AS id',
            'CONCAT(vocabulary.prefix, ":", property.local_name) AS term',
            // Only the two first selects are needed, but some databases
            // require "order by" or "group by" value to be in the select.
            'vocabulary.id'
        )
        ->from('property', 'property')
        ->innerJoin('property', 'vocabulary', 'vocabulary', 'property.vocabulary_id = vocabulary.id')
        ->orderBy('vocabulary.id', 'asc')
        ->addOrderBy('property.id', 'asc')
        ->addGroupBy('property.id')
    ;
    $properties = $connection->executeQuery($qb)->fetchAllKeyValue();

    foreach ($byTemplate as $templateId => $data) {
        $template = $api->searchOne('resource_templates', ['id' => $templateId])->getContent();
        if (!$template) {
            continue;
        }
        // Force full json serialization.
        $json = json_decode(json_encode($template), true);
        $isUpdated = false;
        foreach ($json['o:resource_template_property'] ?? [] as $key => $rtp) {
            $term = $properties[$rtp['o:property']['o:id']];
            if (isset($data[$term])) {
                if (empty($rtp['o:data'][0])) {
                    $json['o:resource_template_property'][$key]['o:data'][0] = $data[$term];
                } else {
                    $json['o:resource_template_property'][$key]['o:data'][0] += $data[$term];
                }
                $isUpdated = true;
            }
        }
        if ($isUpdated) {
            $api->update('resource_templates', $templateId, $json);
        }
    }

    $settings->delete('contribute_resource_template_data');

    $settings->set('contribute_template_default', $settings->get('contribute_template_editable'));
    $settings->delete('contribute_template_editable');
}

if (version_compare($oldVersion, '3.3.0.14', '<')) {
    $settings->set('contribute_mode', $settings->get('contribute_without_token') ? 'user' : 'user_token');
    $settings->delete('contribute_without_token');
}

if (version_compare($oldVersion, '3.3.0.16', '<')) {
    $removed = [
        'Editable mode' => $settings->get('contribute_properties_editable_mode'),
        'Editable properties' => $settings->get('contribute_properties_editable'),
        'Fillable mode' => $settings->get('contribute_properties_fillable_mode'),
        'Fillable properties' => $settings->get('contribute_properties_fillable'),
        'Data types' => $settings->get('contribute_properties_datatype'),
        'Property queries' => $settings->get('contribute_property_queries'),
    ];

    $messenger = $services->get('ControllerPluginManager')->get('messenger');
    $message = new Message(
        'At least one configured template is required to contribute. Default options were removed. Edit the resource template directly.' // @translate
    );
    $messenger->addWarning($message);
    $message = new Message(
        'For information, the removed options to reuse in a template, eventually with module Advanced Resource Template, are: %s.', // @translate
        json_encode($removed, 448)
    );
    $messenger->addWarning($message);
    $services->get('Omeka\Logger')->warn($message);

    $templateId = $settings->get('contribute_template_default') ?: $api
        ->searchOne('resource_templates', ['label' => 'Contribution'], ['returnScalar' => 'id'])->getContent();
    $settings->set('contribute_templates', $templateId ? [$templateId] : []);
    $settings->delete('contribute_template_default');

    $settings->delete('contribute_properties_editable_mode');
    $settings->delete('contribute_properties_editable');
    $settings->delete('contribute_properties_fillable_mode');
    $settings->delete('contribute_properties_fillable');
    $settings->delete('contribute_properties_datatype');
    $settings->delete('contribute_property_queries');
}

if (version_compare($oldVersion, '3.3.0.17', '<')) {
    $settings->set('contribute_templates_media', []);

    $config = $services->get('Config');
    $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
    if (!$this->checkDestinationDir($basePath . '/contribution')) {
        $message = new Message(
            'The directory "%s" is not writeable.', // @translate
            $basePath . '/contribution'
        );
        throw new ModuleCannotInstallException((string) $message);
    }

    $sqls = <<<'SQL'
ALTER TABLE `contribution`
ADD `patch` TINYINT(1) NOT NULL AFTER `email`,
ADD `submitted` TINYINT(1) NOT NULL AFTER `patch`,
CHANGE `resource_id` `resource_id` INT DEFAULT NULL,
CHANGE `owner_id` `owner_id` INT DEFAULT NULL,
CHANGE `token_id` `token_id` INT DEFAULT NULL,
CHANGE `email` `email` VARCHAR(190) DEFAULT NULL,
CHANGE `modified` `modified` DATETIME DEFAULT NULL;

ALTER TABLE `contribution_token`
CHANGE `email` `email` VARCHAR(190) DEFAULT NULL,
CHANGE `expire` `expire` DATETIME DEFAULT NULL,
CHANGE `accessed` `accessed` DATETIME DEFAULT NULL;

UPDATE `contribution`
SET `patch` = 1
WHERE `resource_id` IS NOT NULL;

UPDATE `contribution`
SET `submitted` = 1;
SQL;
    // Use single statements for execution.
    // See core commit #2689ce92f.
    foreach (array_filter(array_map('trim', explode(";\n", $sqls))) as $sql) {
        $connection->executeStatement($sql);
    }

    $settings->set('contribute_notify_recipients', $settings->get('contribute_notify'));
    $settings->delete('contribute_notify');

    $message = new Message(
        'It’s now possible for the user to select a resource template.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new Message(
        'It’s now possible to create a template with a sub-template for one or more media.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new Message(
        'It’s now possible to create a template with file, custom vocab, value suggest or numeric fields.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new Message(
        'It’s now possible to edit a contribution until it is submitted.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new Message(
        'This version does not allow to correct resources. The feature will be reincluded in version 3.3.0.18.' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.3.0.17.3', '<')) {
    $message = new Message(
        'It’s now possible for admin to search contributions.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.3.0.18', '<')) {
    $settings->set('contribute_allow_update', 'submission');

    $message = new Message(
        'It’s now possible to correct and fill existing resources.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new Message(
        'A new option was added to allow to update a contribution until validation.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new Message(
        'The events "view.add.before/after" are used in place of "view.edit.before/after" in template contribution/add.' // @translate
    );
    $messenger->addWarning($message);
    $message = new Message(
        'Warning: the variable "$resource" is now the edited resource in the theme and no more the contribution. Check your theme if you edited templates, mainly "show" and "edit".' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.4.0.20', '<')) {
    $message = new Message(
        'It’s now possible to submit a contribution in one step.' // @translate );
    );
    $messenger->addSuccess($message);
}
