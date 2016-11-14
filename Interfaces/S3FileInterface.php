<?php

namespace Mrapps\AmazonBundle\Interfaces;

interface S3FileInterface
{
    public function getAmazonS3Key();

    public function getAmazonS3FileUrl();
}
