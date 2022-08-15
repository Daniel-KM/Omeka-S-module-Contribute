<?php declare(strict_types=1);

namespace Contribute\File;

use Laminas\Log\Logger;
use Omeka\File\Store\StoreInterface;
use Omeka\File\TempFile;
use Omeka\File\TempFileFactory;
use Omeka\Stdlib\ErrorStore;
use Omeka\Stdlib\Message;

/**
 * File contribution service (store).
 */
class Contribution
{
    /**
     * @var TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var StoreInterface
     */
    protected $store;

    /**
     * @var string
     */
    protected $basePath;

    public function __construct(
        TempFileFactory $tempFileFactory,
        Logger $logger,
        StoreInterface $store,
        string $basePath
    ) {
        $this->tempFileFactory = $tempFileFactory;
        $this->logger = $logger;
        $this->store = $store;
        $this->basePath = $basePath;
    }

    /**
     * Move a file from a stored file inside "/contribution".
     *
     * Pass the $errorStore object if an error should raise an API validation
     * error.
     *
     * @todo Manage direct move for store.
     * @todo Manage remote storage (extends url downloader?).
     *
     * @param string $filename Filename relative to the store base ("/contribution").
     * @param string $sourceName The original source name
     * @param ?ErrorStore $errorStore
     */
    public function toTempFile($filename, ?string $sourceName = null, ?ErrorStore $errorStore = null): ?TempFile
    {
        $filename = (string) $filename;
        if (!strlen($filename)) {
            $message = new Message(
                'The filename set in store is empty.' // @translate
            );
            $this->logger->err((string) $message);
            if ($errorStore) {
                $errorStore->addError('store', $message);
            }
            return null;
        }

        $filepath = $this->basePath . '/contribution/' . $filename;

        $fileinfo = new \SplFileInfo($filepath);
        $realPath = $this->verifyFile($fileinfo, $errorStore);
        if (is_null($realPath)) {
            return null;
        }

        $tempFile = $this->tempFileFactory->build();
        $tempFile->setTempPath($realPath);

        if ($sourceName) {
            $tempFile->setSourceName($sourceName);
        }

        return $tempFile;
    }

    /**
     * Verify the passed file.
     *
     * Working off the "real" base directory and "real" filepath: both must
     * exist and have sufficient permissions; the filepath must begin with the
     * base directory path to avoid problems with symlinks; the base directory
     * must be server-writable to delete the file; and the file must be a
     * readable regular file.
     *
     * @see \FileSideload\Media\Ingester\Sideload::verifyFile()
     *
     * @param \SplFileInfo $fileinfo
     * @return string|false The real file path or null if the file is invalid.
     */
    public function verifyFile(\SplFileInfo $fileinfo, ?ErrorStore $errorStore = null): ?string
    {
        if (false === $this->basePath) {
            return null;
        }
        $realPath = $fileinfo->getRealPath();
        if ($realPath === false) {
            if ($errorStore) {
                $message = new Message(
                    'The filename is not a real path.' // @translate
                );
                $errorStore->addError('store', $message);
            }
            return null;
        }
        if (strpos($realPath, $this->basePath) !== 0) {
            if ($errorStore) {
                $message = new Message(
                    'The filename is not in the base directory.' // @translate
                );
                $errorStore->addError('store', $message);
            }
            return null;
        }
        if (!$fileinfo->getPathInfo()->isWritable()) {
            if ($errorStore) {
                $message = new Message(
                    'The file is not writeable.' // @translate
                );
                $errorStore->addError('store', $message);
            }
            return null;
        }
        if (!$fileinfo->isFile()) {
            if ($errorStore) {
                $message = new Message(
                    'The file is not a real file.' // @translate
                );
                $errorStore->addError('store', $message);
            }
            return null;
        }
        if (!$fileinfo->isReadable()) {
            if ($errorStore) {
                $message = new Message(
                    'The file is not readable.' // @translate
                );
                $errorStore->addError('store', $message);
            }
            return null;
        }
        return $realPath;
    }
}
