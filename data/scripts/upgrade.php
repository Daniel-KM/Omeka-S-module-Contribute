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
        $connection->exec($sql);
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

    $this->checkDependency();

    // @link https://www.doctrine-project.org/projects/doctrine-dbal/en/2.6/reference/types.html#array-types
    $sql = <<<'SQL'
ALTER TABLE `contribution`
CHANGE `proposal` `proposal` LONGTEXT NOT NULL COMMENT '(DC2Type:json)';
SQL;
    $connection->exec($sql);

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
        ->select([
            'DISTINCT property.id AS id',
            'CONCAT(vocabulary.prefix, ":", property.local_name) AS term',
            // Only the two first selects are needed, but some databases
            // require "order by" or "group by" value to be in the select.
            'vocabulary.id',
            'property.id',
        ])
        ->from('property', 'property')
        ->innerJoin('property', 'vocabulary', 'vocabulary', 'property.vocabulary_id = vocabulary.id')
        ->orderBy('vocabulary.id', 'asc')
        ->addOrderBy('property.id', 'asc')
        ->addGroupBy('property.id')
    ;
    $stmt = $connection->executeQuery($qb);
    // Fetch by key pair is not supported by doctrine 2.0.
    $properties = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $properties = array_column($properties, 'term', 'id');

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
