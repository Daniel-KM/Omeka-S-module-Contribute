<?php declare(strict_types=1);

/*
 * Copyright Daniel Berthereau 2019-2026
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software.  You can use, modify and/ or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software's author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user's attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software's suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace Contribute\Entity;

use DateTime;
use Omeka\Entity\AbstractEntity;

/**
 * Index of the files referenced by the proposal of a contribution.
 *
 * The proposal remains the source of truth. This table is a plain index of the
 * stored files, synchronized on each save, used to clean the directory
 * "files/contribution" safely and to check the integrity of the stored files.
 *
 * @Entity
 * @Table(
 *     indexes={
 *         @Index(
 *             name="contribution_file_store_idx",
 *             columns={"store"}
 *         )
 *     }
 * )
 */
class ContributionFile extends AbstractEntity
{
    /**
     * @var int
     *
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    protected $id;

    /**
     * @var \Contribute\Entity\Contribution
     *
     * @ManyToOne(
     *     targetEntity="Contribution",
     *     inversedBy="files"
     * )
     * @JoinColumn(
     *     nullable=false,
     *     onDelete="CASCADE"
     * )
     */
    protected $contribution;

    /**
     * The stored filename, relative to "files/contribution".
     *
     * @var string
     *
     * @Column(
     *     type="string",
     *     length=190
     * )
     */
    protected $store;

    /**
     * The original name of the file uploaded by the contributor.
     *
     * @var string
     *
     * @Column(
     *     type="string",
     *     length=1000,
     *     nullable=true
     * )
     */
    protected $sourceName;

    /**
     * @var int
     *
     * @Column(
     *     type="bigint",
     *     nullable=true
     * )
     */
    protected $size;

    /**
     * @var string
     *
     * @Column(
     *     type="string",
     *     length=64,
     *     nullable=true,
     *     options={
     *         "fixed": true
     *     }
     * )
     */
    protected $sha256;

    /**
     * @var DateTime
     *
     * @Column(
     *     type="datetime"
     * )
     */
    protected $created;

    public function getId()
    {
        return $this->id;
    }

    public function setContribution(Contribution $contribution): self
    {
        $this->contribution = $contribution;
        return $this;
    }

    public function getContribution(): Contribution
    {
        return $this->contribution;
    }

    public function setStore(string $store): self
    {
        $this->store = $store;
        return $this;
    }

    public function getStore(): string
    {
        return $this->store;
    }

    public function setSourceName(?string $sourceName): self
    {
        $this->sourceName = $sourceName;
        return $this;
    }

    public function getSourceName(): ?string
    {
        return $this->sourceName;
    }

    public function setSize(?int $size): self
    {
        $this->size = $size;
        return $this;
    }

    public function getSize(): ?int
    {
        return $this->size === null ? null : (int) $this->size;
    }

    public function setSha256(?string $sha256): self
    {
        $this->sha256 = $sha256;
        return $this;
    }

    public function getSha256(): ?string
    {
        return $this->sha256;
    }

    public function setCreated(DateTime $created): self
    {
        $this->created = $created;
        return $this;
    }

    public function getCreated(): DateTime
    {
        return $this->created;
    }
}
