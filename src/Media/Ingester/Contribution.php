<?php declare(strict_types=1);

namespace Contribute\Media\Ingester;

use Contribute\File\Contribution as FileContribution;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;

class Contribution implements IngesterInterface
{
    /**
     * @var \Contribute\File\Contribution
     */
    protected $fileContribution;

    public function __construct(FileContribution $fileContribution)
    {
        $this->fileContribution = $fileContribution;
    }

    public function getLabel()
    {
        return 'Contribution'; // @translate
    }

    public function getRenderer()
    {
        return 'file';
    }

    /**
     * Ingest from a contribution stored in "/contribution".
     *
     * {@inheritDoc}
     */
    public function ingest(Media $media, Request $request, ErrorStore $errorStore): void
    {
        $data = $request->getContent();
        if (!isset($data['store'])) {
            $errorStore->addError('error', 'The store for file is not set.'); // @translate
            return;
        }

        $sourceName = $data['o:source'] ?? null;

        $tempFile = $this->fileContribution->toTempFile($data['store'], $sourceName, $errorStore);
        if (!$tempFile) {
            return;
        }

        // Keep standard ingester name to simplify management: this is only an
        // internal intermediate temporary ingester.
        $media->setIngester('upload');

        if (!array_key_exists('o:source', $data)) {
            $media->setSource($tempFile->getSourceName());
        }

        $storeOriginal = true;
        $storeThumbnails = true;
        // Keep temp files to avoid losses when contribution is validated.
        // TODO The file will be removed later (after hydration: see Module).
        $deleteTempFile = false;
        $hydrateFileMetadataOnStoreOriginalFalse = true;
        $tempFile->mediaIngestFile(
            $media,
            $request,
            $errorStore,
            $storeOriginal,
            $storeThumbnails,
            $deleteTempFile,
            $hydrateFileMetadataOnStoreOriginalFalse
        );
    }

    public function form(PhpRenderer $view, array $options = [])
    {
        return $view->translate('Used only for internal contribution process.'); // @translate
    }
}
