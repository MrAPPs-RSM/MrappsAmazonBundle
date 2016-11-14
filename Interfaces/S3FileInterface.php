<?php

namespace Mrapps\AmazonBundle\Interfaces;

interface S3FileInterface
{
    public function getRemoteRelativePath();

    public function getLocalPath();
}
