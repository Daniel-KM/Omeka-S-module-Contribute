<?php declare(strict_types=1);

/*
 * Copyright Daniel Berthereau 2019-2023
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
use Omeka\Entity\Resource;

/**
 * @Entity
 * @Table(
 *     name="contribution_token",
 *     indexes={
 *         @Index(
 *             name="contribution_token_idx",
 *             columns={"token"}
 *         ),
 *         @Index(
 *             name="contribution_expire_idx",
 *             columns={"expire"}
 *         )
 *     }
 * )
 */
class Token extends AbstractEntity
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
     * @var \Omeka\Entity\Resource
     *
     * @ManyToOne(
     *     targetEntity="\Omeka\Entity\Resource"
     * )
     * @JoinColumn(
     *     nullable=false,
     *     onDelete="CASCADE"
     * )
     */
    protected $resource;

    /**
     * @var string
     *
     * @Column(
     *     type="string",
     *     length=40
     * )
     */
    protected $token;

    /**
     * @var string
     *
     * @Column(
     *     type="string",
     *     length=190,
     *     nullable=true
     * )
     */
    protected $email;

    /**
     * @var \DateTime
     *
     * @Column(
     *     type="datetime",
     *     nullable=true
     * )
     */
    protected $expire;

    /**
     * @var \DateTime
     *
     * @Column(
     *     type="datetime"
     * )
     */
    protected $created;

    /**
     * @var \DateTime
     *
     * @Column(
     *     type="datetime",
     *     nullable=true
     * )
     */
    protected $accessed;

    public function getId()
    {
        return $this->id;
    }

    public function setResource(Resource $resource): self
    {
        $this->resource = $resource;
        return $this;
    }

    public function getResource(): Resource
    {
        return $this->resource;
    }

    public function setToken(string $token): self
    {
        $this->token = $token;
        return $this;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setExpire(?DateTime $dateTime): self
    {
        $this->expire = $dateTime;
        return $this;
    }

    public function getExpire(): ?DateTime
    {
        return $this->expire;
    }

    public function setCreated(DateTime $dateTime): self
    {
        $this->created = $dateTime;
        return $this;
    }

    public function getCreated(): DateTime
    {
        return $this->created;
    }

    public function setAccessed(?DateTime $dateTime): self
    {
        $this->accessed = $dateTime;
        return $this;
    }

    public function getAccessed(): ?DateTime
    {
        return $this->accessed;
    }
}
