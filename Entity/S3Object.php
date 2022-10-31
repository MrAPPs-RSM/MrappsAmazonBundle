<?php

namespace Mrapps\AmazonBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * S3Object
 *
 * @ORM\Table(name="mrapps_amazon_s3_objects")
 * @ORM\Entity(repositoryClass="Mrapps\AmazonBundle\Entity\S3ObjectRepository")
 */
class S3Object
{
    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;
    
    
    /**
     * @ORM\Column(name="s3_key", type="string", length=1000, nullable=false)
     */
    protected $s3Key;
    
    
    /**
     * @ORM\Column(name="etag", type="string", length=200, nullable=false)
     */
    protected $etag;
    
    /**
     * @ORM\Column(name="updated_at", type="datetime", nullable=true)
     */
    protected $updatedAt;


    /**
     * Get id
     *
     * @return integer 
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set s3Key
     *
     * @param string $s3Key
     * @return S3Object
     */
    public function setS3Key($s3Key)
    {
        $this->s3Key = $s3Key;

        return $this;
    }

    /**
     * Get s3Key
     *
     * @return string 
     */
    public function getS3Key()
    {
        return $this->s3Key;
    }

    /**
     * Set etag
     *
     * @param string $etag
     * @return S3Object
     */
    public function setEtag($etag)
    {
        $this->etag = $etag;

        return $this;
    }

    /**
     * Get etag
     *
     * @return string 
     */
    public function getEtag()
    {
        return $this->etag;
    }
    
    /**
     * Set updated at
     *
     * @param \DateTime $updatedAt
     *
     * @return S3Object
     */
    public function setUpdatedAt($updatedAt)
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
    
    /**
     * Get updated at
     *
     * @return \DateTime
     */
    public function getUpdatedAt()
    {
        return $this->updatedAt;
    }
}
