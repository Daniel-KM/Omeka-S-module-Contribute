<?php declare(strict_types=1);

namespace Contribute;

use Common\Stdlib\PsrMessage;
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
 * @var \Common\Stdlib\EasyMeta $easyMeta
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
// $config = require dirname(dirname(__DIR__)) . '/config/module.config.php';
$settings = $services->get('Omeka\Settings');
$easyMeta = $services->get('Common\EasyMeta');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
// $entityManager = $services->get('Omeka\EntityManager');

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.65')) {
    $message = new Message(
        'The module %1$s should be upgraded to version %2$s or later.', // @translate
        'Common', '3.4.65'
    );
    throw new ModuleCannotInstallException((string) $message);
}

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('AdvancedResourceTemplate', '3.4.36')) {
    $message = new Message(
        'The module {module} should be upgraded to version {version} or later.', // @translate
        ['module' => 'AdvancedResourceTemplate', 'version' => '3.4.36']
    );
    throw new ModuleCannotInstallException((string) $message);
}

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
        ADD `owner_id` int(11) NULL AFTER `resource_id`
        ;
        ALTER TABLE `contribution`
        ADD FOREIGN KEY (`FK_EA351E1589329D25`) REFERENCES `resource` (`id`) ON DELETE SET NULL
        ;
        ALTER TABLE `contribution`
        ADD CONSTRAINT `FK_EA351E157E3C61F9` FOREIGN KEY (`owner_id`) REFERENCES `user` (`id`) ON DELETE SET NULL
        ;
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
        $message = new PsrMessage(
            'This module requires the module "{module}", version {version} or above.', // @translate
            ['module' => 'Generic', 'version' => '3.3.28']
        );
        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
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

    $propertyTerms = $easyMeta->propertyTerms();
    foreach ($byTemplate as $templateId => $data) {
        $template = $api->searchOne('resource_templates', ['id' => $templateId])->getContent();
        if (!$template) {
            continue;
        }
        // Force full json serialization.
        $json = json_decode(json_encode($template), true);
        $isUpdated = false;
        foreach ($json['o:resource_template_property'] ?? [] as $key => $rtp) {
            $term = $propertyTerms[$rtp['o:property']['o:id']] ?? null;
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
    $message = new PsrMessage(
        'At least one configured template is required to contribute. Default options were removed. Edit the resource template directly.' // @translate
    );
    $messenger->addWarning($message);
    $message = new PsrMessage(
        'For information, the removed options to reuse in a template, eventually with module Advanced Resource Template, are: {json}.', // @translate
        ['json' => json_encode($removed, 448)]
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
        $message = new PsrMessage(
            'The directory "{directory}" is not writeable.', // @translate
            ['directory' => $basePath . '/contribution']
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
        CHANGE `modified` `modified` DATETIME DEFAULT NULL
        ;
        ALTER TABLE `contribution_token`
        CHANGE `email` `email` VARCHAR(190) DEFAULT NULL,
        CHANGE `expire` `expire` DATETIME DEFAULT NULL,
        CHANGE `accessed` `accessed` DATETIME DEFAULT NULL
        ;
        UPDATE `contribution`
        SET `patch` = 1
        WHERE `resource_id` IS NOT NULL
        ;
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

    $message = new PsrMessage(
        'It’s now possible for the user to select a resource template.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new PsrMessage(
        'It’s now possible to create a template with a sub-template for one or more media.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new PsrMessage(
        'It’s now possible to create a template with file, custom vocab, value suggest or numeric fields.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new PsrMessage(
        'It’s now possible to edit a contribution until it is submitted.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new PsrMessage(
        'This version does not allow to correct resources. The feature will be reincluded in version 3.3.0.18.' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.3.0.17.3', '<')) {
    $message = new PsrMessage(
        'It’s now possible for admin to search contributions.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.3.0.18', '<')) {
    $settings->set('contribute_allow_update', 'submission');

    $message = new PsrMessage(
        'It’s now possible to correct and fill existing resources.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new PsrMessage(
        'A new option was added to allow to update a contribution until validation.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new PsrMessage(
        'The events "view.add.before/after" are used in place of "view.edit.before/after" in template contribution/add.' // @translate
    );
    $messenger->addWarning($message);
    $message = new PsrMessage(
        'Warning: the variable "$resource" is now the edited resource in the theme and no more the contribution. Check your theme if you edited templates, mainly "show" and "edit".' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.4.0.20', '<')) {
    $message = new PsrMessage(
        'It’s now possible to submit a contribution in one step.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.25', '<')) {
    $message = new PsrMessage(
        'It’s now possible to allow contribution only for selected authenticated users or via a regex on email.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.29', '<')) {
    $qb = $connection->createQueryBuilder()
        ->select('id', 'data')
        ->from('resource_template_data', 'resource_template_data')
        ->orderBy('id', 'asc');
    $templateDatas = $connection->executeQuery($qb)->fetchAllKeyValue();
    foreach ($templateDatas as $id => $templateData) {
        $templateData = json_decode($templateData, true) ?: [];
        if (array_key_exists('contribute_template_media', $templateData)
            && !array_key_exists('contribute_templates_media', $templateData)
        ) {
            $templateData['contribute_templates_media'] = $templateData['contribute_template_media']
                ? [$templateData['contribute_template_media']]
                : [];
            unset($templateData['contribute_template_media']);
        }
        $sql = 'UPDATE `resource_template_data` SET `data` = ? WHERE `id` = ?;';
        $connection->executeStatement($sql, [json_encode($templateData, 320), $id]);
    }

    $message = new PsrMessage(
        'It’s now possible to set a minimum number of files.' // @translate
    );
    $messenger->addSuccess($message);

    /*
    $message = new PsrMessage(
        'It’s now possible to allow a contribution with multiple media templates.' // @translate
    );
    $messenger->addSuccess($message);
    */
}
