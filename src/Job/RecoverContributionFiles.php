<?php declare(strict_types=1);

namespace Contribute\Job;

use Common\Stdlib\PsrMessage;
use Contribute\Entity\Contribution;
use Omeka\Job\AbstractJob;

/**
 * Recover missing files of pending contributions and fill the new
 * contribution_file index. Introduced by the 3.4.39 upgrade; extracted to a
 * background job in a later fix so an install with a large files/original
 * directory does not time out the synchronous upgrade request.
 *
 * The three heavy operations that used to run inline in the upgrade script are
 * moved here:
 *   1. Recursive scan of files/original to detect orphan files.
 *   2. Recovery loop: match orphan files with the "store" entries listed in
 *      contributions whose files/contribution copy is missing.
 *   3. Cleanup loop: remove the remaining orphan files and their thumbnails.
 *   4. Index fill of contribution_file from the surviving proposals.
 *
 * Design notes:
 *   - The job is idempotent: rerunning it after a first successful pass is a
 *     near no-op (no orphan file is left, and syncContributionFiles is
 *     conditional on missing rows).
 *   - It runs under the identity of the admin who triggered the upgrade, so
 *     the messenger warnings survive as job log messages instead.
 *   - A synchronous fallback is intentionally NOT provided: shipping the job
 *     as the only path removes the timeout risk entirely.
 */
class RecoverContributionFiles extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $connection = $services->get('Omeka\Connection');
        $entityManager = $services->get('Omeka\EntityManager');
        $logger = $services->get('Omeka\Logger');
        $store = $services->get('Omeka\File\Store');

        if (!$store instanceof \Omeka\File\Store\Local) {
            $logger->warn(
                'The file store is not local: the recovery of the files of pending contributions and the cleaning of orphan files inside files/original cannot be processed automatically. Check them manually.' // @translate
            );
            return;
        }

        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $originalPath = $basePath . '/original';
        $contributionPath = $basePath . '/contribution';

        // Step 1: build the list of storage_ids referenced by valid medias AND,
        // if the DigitalObject module is installed, by valid digital objects.
        // Both entities share the files/original tree so both must be checked
        // before flagging a file as orphan — without this, DO files would be
        // wrongly deleted.
        $referenceds = $connection
            ->executeQuery('SELECT `storage_id` FROM `media` WHERE `storage_id` IS NOT NULL')
            ->fetchFirstColumn();
        $referenceds = array_fill_keys($referenceds, true);

        $hasDigitalObject = (bool) $connection
            ->executeQuery("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'digital_object' LIMIT 1")
            ->fetchOne();
        if ($hasDigitalObject) {
            $doStorageIds = $connection
                ->executeQuery('SELECT `storage_id` FROM `digital_object` WHERE `storage_id` IS NOT NULL')
                ->fetchFirstColumn();
            foreach ($doStorageIds as $storageId) {
                $referenceds[$storageId] = true;
            }
        }

        $orphans = [];
        if (file_exists($originalPath) && is_dir($originalPath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($originalPath, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileinfo) {
                if (!$fileinfo->isFile()) {
                    continue;
                }
                $relativePath = ltrim(substr($fileinfo->getPathname(), strlen($originalPath)), '/');
                $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
                $storageId = strlen($extension)
                    ? substr($relativePath, 0, -strlen($extension) - 1)
                    : $relativePath;
                if (!isset($referenceds[$storageId])) {
                    $orphans[$relativePath] = [
                        'path' => $fileinfo->getPathname(),
                        'storage_id' => $storageId,
                        'extension' => $extension,
                        'mtime' => $fileinfo->getMTime(),
                        'state' => null,
                    ];
                }
            }
        }

        // Exclude the orphan files that are exact copies (same size then same
        // hash) of a file still present inside files/contribution: they are
        // duplicates created by a previous submission of a file that is not
        // missing, so they should not be matched with a missing file. They are
        // marked "used" so they are removed with their thumbnails below in any
        // case, because their content is still available inside
        // files/contribution.
        $contributionSizes = [];
        if (file_exists($contributionPath) && is_dir($contributionPath)) {
            foreach (new \DirectoryIterator($contributionPath) as $fileinfo) {
                if ($fileinfo->isFile()) {
                    $contributionSizes[$fileinfo->getSize()][] = $fileinfo->getPathname();
                }
            }
        }
        foreach ($orphans as $key => $orphan) {
            $size = filesize($orphan['path']);
            $orphans[$key]['size'] = $size;
            foreach ($contributionSizes[$size] ?? [] as $contributionFile) {
                if (hash_file('sha256', $contributionFile) === hash_file('sha256', $orphan['path'])) {
                    $orphans[$key]['state'] = 'duplicate';
                    break;
                }
            }
        }

        // Step 2: recover the missing files of the pending contributions.
        $extractStores = function (array $proposal): array {
            $stores = [];
            foreach ($proposal['media'] ?? [] as $mediaFiles) {
                foreach ($mediaFiles['file'] ?? [] as $mediaFile) {
                    if (!empty($mediaFile['proposed']['store'])) {
                        $stores[] = (string) $mediaFile['proposed']['store'];
                    }
                }
            }
            foreach ($proposal['file'] ?? [] as $mediaFile) {
                if (!empty($mediaFile['proposed']['store'])) {
                    $stores[] = (string) $mediaFile['proposed']['store'];
                }
            }
            return $stores;
        };

        // Calibrate the time offset between file system times and database
        // datetimes empirically. See the original 3.4.39 upgrade for the
        // rationale of using the median.
        $timeOffset = 0;
        $medias = $connection
            ->executeQuery('SELECT `media`.`storage_id`, `media`.`extension`, `resource`.`created` FROM `media` INNER JOIN `resource` ON `resource`.`id` = `media`.`id` WHERE `media`.`storage_id` IS NOT NULL AND `media`.`has_original` = 1 ORDER BY `media`.`id` DESC LIMIT 200')
            ->fetchAllAssociative();
        $timeOffsets = [];
        foreach ($medias as $media) {
            $mediaPath = $originalPath . '/' . $media['storage_id']
                . (strlen((string) $media['extension']) ? '.' . $media['extension'] : '');
            if (file_exists($mediaPath)) {
                $timeOffsets[] = filemtime($mediaPath) - strtotime($media['created']);
            }
        }
        if ($timeOffsets) {
            sort($timeOffsets);
            $timeOffset = $timeOffsets[intdiv(count($timeOffsets), 2)];
        }

        $recovereds = 0;
        $contributionsToCheck = [];
        $contributionsLost = [];
        $contributions = $connection
            ->executeQuery('SELECT `id`, `proposal`, `created`, `modified` FROM `contribution` WHERE `resource_id` IS NULL AND `proposal` LIKE \'%"store"%\' ORDER BY `id` ASC')
            ->fetchAllAssociative();
        foreach ($contributions as $contribution) {
            if ($this->shouldStop()) {
                $logger->warn(
                    'Job stopped before completion. Recovery may be incomplete; relaunch it manually to resume.' // @translate
                );
                return;
            }
            $proposal = json_decode($contribution['proposal'], true) ?: [];
            $stores = $extractStores($proposal);
            $missings = [];
            foreach ($stores as $storeName) {
                if (strpos($storeName, '..') !== false || strpos($storeName, '/') === 0) {
                    continue;
                }
                if (!file_exists($contributionPath . '/' . $storeName)) {
                    $missings[] = $storeName;
                }
            }
            if (!$missings) {
                continue;
            }
            $reference = strtotime($contribution['modified'] ?? $contribution['created']) + $timeOffset;
            $isAmbiguous = false;
            foreach ($missings as $storeName) {
                $extension = strtolower(pathinfo($storeName, PATHINFO_EXTENSION));
                $candidates = array_filter($orphans, fn ($orphan) => $orphan['state'] === null
                    && $orphan['extension'] === $extension
                    && abs($orphan['mtime'] - $reference) <= 3600);
                if (!$candidates) {
                    $contributionsLost[$contribution['id']] = $contribution['id'];
                    continue;
                }
                if (count($candidates) > 1) {
                    $isAmbiguous = true;
                }
                uasort($candidates, fn ($a, $b) => $a['mtime'] <=> $b['mtime'] ?: strcmp($a['path'], $b['path']));
                $candidateKey = array_key_first($candidates);
                $candidate = $candidates[$candidateKey];
                if (!is_dir($contributionPath)) {
                    mkdir($contributionPath, 0775, true);
                }
                if (copy($candidate['path'], $contributionPath . '/' . $storeName)) {
                    $orphans[$candidateKey]['state'] = 'recovered';
                    ++$recovereds;
                } else {
                    $contributionsLost[$contribution['id']] = $contribution['id'];
                }
            }
            if ($isAmbiguous) {
                $contributionsToCheck[$contribution['id']] = $contribution['id'];
            }
        }

        if ($recovereds) {
            $logger->notice((new PsrMessage(
                '{count} files of pending contributions were recovered inside files/contribution from the orphan files of files/original.', // @translate
                ['count' => $recovereds]
            ))->getMessage(), ['count' => $recovereds]);
        }
        if ($contributionsToCheck) {
            $logger->warn((new PsrMessage(
                'The files of the contributions {ids} were recovered, but multiple files with the same extension were submitted together, so the association between files and medias may be wrong and should be checked manually.', // @translate
                ['ids' => implode(', ', $contributionsToCheck)]
            ))->getMessage(), ['ids' => implode(', ', $contributionsToCheck)]);
        }
        if ($contributionsLost) {
            $logger->warn((new PsrMessage(
                'Some files of the contributions {ids} are missing and could not be recovered. The contributors should be asked to submit their files again.', // @translate
                ['ids' => implode(', ', $contributionsLost)]
            ))->getMessage(), ['ids' => implode(', ', $contributionsLost)]);
        }

        // Step 3: remove the remaining orphan files and their thumbnails.
        //
        // Three distinct categories are removed here, tracked separately to
        // produce an unambiguous log message:
        //   - "duplicate": orphan whose sha256 matched a file still present in
        //     files/contribution, so its content is preserved there.
        //   - "recovered": orphan copied into files/contribution during the
        //     recovery step above, so the source becomes redundant.
        //   - "unmatched": orphan file whose modification time is after the
        //     first contribution but that could not be matched to any pending
        //     contribution. Presumed leftover of an interrupted submission.
        //     Files older than the first contribution are kept (they cannot
        //     have been created by the module).
        //
        // In every case, the file is not attached to any media (the whole
        // orphan detection at step 1 already excluded referenced storage_ids),
        // so the removal is safe for existing items.
        $minCreated = $connection
            ->executeQuery('SELECT MIN(`created`) FROM `contribution`')
            ->fetchOne();
        $removedDuplicates = 0;
        $removedRecovered = 0;
        $removedUnmatched = 0;
        if ($minCreated) {
            $minCreated = strtotime($minCreated) + $timeOffset;
            $thumbnailTypes = array_keys($config['thumbnails']['types'] ?? []) ?: ['large', 'medium', 'square'];
            foreach ($orphans as $orphan) {
                $category = null;
                if ($orphan['state'] === 'duplicate') {
                    $category = 'duplicate';
                } elseif ($orphan['state'] === 'recovered') {
                    $category = 'recovered';
                } elseif ($orphan['mtime'] >= $minCreated) {
                    $category = 'unmatched';
                }
                if ($category === null) {
                    continue;
                }
                if (@unlink($orphan['path'])) {
                    $category === 'duplicate' && ++$removedDuplicates;
                    $category === 'recovered' && ++$removedRecovered;
                    $category === 'unmatched' && ++$removedUnmatched;
                    foreach ($thumbnailTypes as $type) {
                        $thumbnailPath = $basePath . '/' . $type . '/' . $orphan['storage_id'] . '.jpg';
                        if (file_exists($thumbnailPath)) {
                            @unlink($thumbnailPath);
                        }
                    }
                }
            }
        }
        $totalRemoved = $removedDuplicates + $removedRecovered + $removedUnmatched;
        if ($totalRemoved) {
            $logger->notice((new PsrMessage(
                '{count} orphan files were removed from files/original with their thumbnails: {duplicates} were duplicates whose content is preserved in files/contribution, {recovered} were sources copied into files/contribution during the recovery, and {unmatched} were unmatched leftovers of interrupted submissions (post-first-contribution files that could not be linked to any pending contribution). No file attached to a media (item) was touched.', // @translate
                [
                    'count' => $totalRemoved,
                    'duplicates' => $removedDuplicates,
                    'recovered' => $removedRecovered,
                    'unmatched' => $removedUnmatched,
                ]
            ))->getMessage(), [
                'count' => $totalRemoved,
                'duplicates' => $removedDuplicates,
                'recovered' => $removedRecovered,
                'unmatched' => $removedUnmatched,
            ]);
        }

        // Step 4: fill the contribution_file index from the surviving
        // proposals. Load contributions as entities so ContributionFile
        // entities are managed by Doctrine and flushed in batches.
        $module = $services->get('Omeka\ModuleManager')->getModule('Contribute');
        // Access the Module instance to reuse syncContributionFiles(). The
        // Module Manager stores the module state metadata, not the Module.php
        // class instance; load it explicitly.
        require_once dirname(__DIR__, 2) . '/Module.php';
        $moduleClass = \Contribute\Module::class;
        /** @var \Contribute\Module $moduleInstance */
        $moduleInstance = new $moduleClass();
        $moduleInstance->setServiceLocator($services);

        $repository = $entityManager->getRepository(Contribution::class);
        $ids = $connection
            ->executeQuery('SELECT `id` FROM `contribution` WHERE `proposal` LIKE \'%"store"%\' ORDER BY `id` ASC')
            ->fetchFirstColumn();
        $count = 0;
        foreach ($ids as $id) {
            if ($this->shouldStop()) {
                $entityManager->flush();
                $entityManager->clear();
                $logger->warn(
                    'Job stopped before the contribution_file index was fully populated; relaunch it manually to resume.' // @translate
                );
                return;
            }
            $contribution = $repository->find($id);
            if (!$contribution) {
                continue;
            }
            $moduleInstance->syncContributionFiles($contribution);
            ++$count;
            if ($count % 100 === 0) {
                $entityManager->flush();
                $entityManager->clear();
                $repository = $entityManager->getRepository(Contribution::class);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
        if ($count) {
            $logger->notice((new PsrMessage(
                'The files of {count} contributions were indexed in the new table "contribution_file".', // @translate
                ['count' => $count]
            ))->getMessage(), ['count' => $count]);
        }
    }
}
