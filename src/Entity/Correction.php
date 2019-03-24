<?php

/*
 * Copyright Daniel Berthereau 2019
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

namespace Correction\Entity;

use DateTime;
use Omeka\Entity\AbstractEntity;
use Omeka\Entity\Resource;

/**
 * @Entity
 * @Table(
 *     indexes={
 *         @Index(
 *             name="email_idx",
 *             columns={"email"}
 *         ),
 *         @Index(
 *             name="modified_idx",
 *             columns={"modified"}
 *         )
 *     }
 * )
 */
class Correction extends AbstractEntity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    protected $id;

    /**
     * Corrections are not annotations in this module, so they are deleted.
     *
     * @var \Omeka\Entity\Resource
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
     * @todo Allow to keep history of all corrections (ManyToOne)?
     *
     * @var \Correction\Entity\CorrectionToken
     * @OneToOne(
     *     targetEntity="CorrectionToken"
     * )
     * @JoinColumn(
     *     nullable=true,
     *     onDelete="SET NULL"
     * )
     */
    protected $token;

    /**
     * The email is saved in the case the token is deleted, so it will be still
     * possible to get the propositions of a user.
     *
     * @var string
     * @Column(type="string", length=255, nullable=true)
     */
    protected $email;

    /**
     * @var bool
     * @Column(type="boolean", nullable=false)
     */
    protected $reviewed = false;

    /**
     * @var array
     * @Column(type="json_array")
     */
    protected $proposal;

    /**
     * @var DateTime
     * @Column(type="datetime")
     */
    protected $created;

    /**
     * @var DateTime
     * @Column(type="datetime", nullable=true)
     */
    protected $modified;

    public function getId()
    {
        return $this->id;
    }

    public function setResource(Resource $resource)
    {
        $this->resource = $resource;
        return $this;
    }

    public function getResource()
    {
        return $this->resource;
    }

    public function setToken(CorrectionToken $token = null)
    {
        $this->token = $token;
        return $this;
    }

    public function getToken()
    {
        return $this->token;
    }

    public function setEmail($email = null)
    {
        $this->email = $email;
        return $this;
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function setReviewed($reviewed)
    {
        $this->reviewed = (bool) $reviewed;
        return $this;
    }

    public function getReviewed()
    {
        return (bool) $this->reviewed;
    }

    public function setProposal($proposal)
    {
        $this->proposal = $proposal;
        return $this;
    }

    public function getProposal()
    {
        return $this->proposal;
    }

    public function setCreated(DateTime $dateTime)
    {
        $this->created = $dateTime;
        return $this;
    }

    public function getCreated()
    {
        return $this->created;
    }

    public function setModified(DateTime $dateTime = null)
    {
        $this->modified = $dateTime;
        return $this;
    }

    public function getModified()
    {
        return $this->modified;
    }
}
