<?php

namespace Mrapps\AmazonBundle\Handler;

use Symfony\Component\DependencyInjection\Container;
use Doctrine\ORM\EntityManager;
use Aws\S3\S3Client;


class S3Handler
{
    private $container;
    private $em;
    
    public function __construct(Container $container, EntityManager $em)
    {
        $this->container = $container;
        $this->em = $em;
    }
    
    private function getParams() {
        
        return array(
            'access' => ($this->container->hasParameter('mrapps_amazon.parameters.access')) ? $this->container->getParameter('mrapps_amazon.parameters.access') : '',
            'secret' => ($this->container->hasParameter('mrapps_amazon.parameters.secret')) ? $this->container->getParameter('mrapps_amazon.parameters.secret') : '',
            'region' => ($this->container->hasParameter('mrapps_amazon.parameters.region')) ? $this->container->getParameter('mrapps_amazon.parameters.region') : '',
            'bucket' => ($this->container->hasParameter('mrapps_amazon.parameters.default_bucket')) ? $this->container->getParameter('mrapps_amazon.parameters.default_bucket') : '',
        );
    }
    
    private function getClient() {
        
        $params = $this->getParams();
        
        return S3Client::factory(array(
            'key' => $params['access'],
            'secret' => $params['secret'],
            'region' => $params['region'],
        ));
    }
    
    public function objectExists($key, $bucket = '') {
        
        $key = trim($key);
        $bucket = trim($bucket);
        if(strlen($key) > 0) {
            $params = $this->getParams();
            $client = $this->getClient();
            
            if(strlen($bucket) == 0) $bucket = $params['bucket'];

            return $client->doesObjectExist($bucket, $key);
        }
        
        return false;
        
    }
    
    public function createObject($key = '', $content = '', $acl = 'public-read') {
        
        $params = $this->getParams();
        $client = $this->getClient();
        
        try {
            $result = $client->putObject(array(
                'Bucket' => $params['bucket'],
                'Key'    => $key,
                'Body'   => $content,
                'ACL'    => $acl,
            ))->toArray();
            
            $client->waitUntilObjectExists(array('Bucket' => $params['bucket'], 'Key' => $key));
            
            //Aggiornamento etag Database
            $etag = $this->getEtagForKey($key);
            $this->em->getRepository('MrappsAmazonBundle:S3Object')->setEtag($key, $etag);
            
        } catch (\Exception $ex) {
            $result = array();
        }
        
        return $result;
    }
    
    public function copyObject($source, $dest, $sourceBucket = '', $destBucket = '') {
        
        $params = $this->getParams();
        $client = $this->getClient();
        
        if(strlen($sourceBucket) == 0) $sourceBucket = $params['bucket'];
        if(strlen($destBucket) == 0) $destBucket = $params['bucket'];
        
        try {
            if($this->objectExists($source, $sourceBucket)) {
                
                $copySource = sprintf("%s/%s", $sourceBucket, $source);
                $result = $client->copyObject(array(
                    'Bucket' => $destBucket,
                    'CopySource' => $copySource,
                    'Key' => $dest,
                ))->toArray();
                
                $client->waitUntilObjectExists(array('Bucket' => $destBucket, 'Key' => $dest));
                
                //Aggiornamento etag Database
                $etag = $this->getEtagForKey($dest);
                $this->em->getRepository('MrappsAmazonBundle:S3Object')->setEtag($dest, $etag);
            }
            
        } catch (\Exception $ex) {
            $result = array();
        }
        
        return $result;
    }
    
    public function getObjectContent($key = '', $bucket = '') {
        
        $key = trim($key);
        $bucket = trim($bucket);
        if(strlen($key) > 0) {
            
            $params = $this->getParams();
            $client = $this->getClient();
            
            if(strlen($bucket) == 0) $bucket = $params['bucket'];
            
            if($this->objectExists($key, $bucket)) {
                
                $result = $client->getObject(array(
                    'Bucket' => $bucket,
                    'Key'    => $key,
                ));
                
                return (isset($result['Body'])) ? $result['Body'].'' : '';
            }
        }
        
        return '';
    }
    
    public function getObjectUrl($key = '', $force = false) {
        
        $key = trim($key);
        if(strlen($key) > 0) {
            $params = $this->getParams();
            $client = $this->getClient();

            return ((bool)$force || $client->doesObjectExist($params['bucket'], $key)) ? $client->getObjectUrl($params['bucket'], $key) : '';
        }
        
        return '';
    }
    
    public function getEtagForKey($key = '') {
        
        $key = trim($key);
        if(strlen($key) > 0) {
            $params = $this->getParams();
            $client = $this->getClient();
            
            try {
                $result = $client->headObject(array(
                    'Bucket' => $params['bucket'],
                    'Key' => $key,
                ))->toArray();
            } catch (\Exception $ex) {
                $result = array();
            }
            
            return (isset($result['ETag'])) ? str_replace('"', '', $result['ETag']) : '';
        }
        
        return '';
    }
    
    public function getEtagForObject($key = '') {
        
        $etag = '';
        $key = trim($key);
        if(strlen($key) > 0) {
            
            $etag = $this->em->getRepository('MrappsAmazonBundle:S3Object')->getEtag($key);
            if(strlen($etag) == 0) {
                $etag = $this->getEtagForKey($key);
                if(strlen($etag) > 0) {
                    $this->em->getRepository('MrappsAmazonBundle:S3Object')->setEtag($key, $etag);
                }
            }
        }
        
        return $etag;
    }
    
    public function downloadObject($key = '', $savePath = '', $returnCompleteResponse = false) {
        
        $key = trim($key);
        $savePath = trim($savePath);
        
        if(strlen($key) > 0 && strlen($savePath) > 0) {
            
            $params = $this->getParams();
            $client = $this->getClient();
            
            if($this->objectExists($key)) {
                
                try {
                
                    $response = $client->getObject(array(
                        'Bucket' => $params['bucket'],
                        'Key' => $key,
                        'SaveAs' => $savePath,
                    ));

                    return ($returnCompleteResponse) ? $response : true;

                } catch (\Exception $ex) {
                    return ($returnCompleteResponse) ? null : false;
                }
            }
        }
        
        return ($returnCompleteResponse) ? null : false;
    }
    
    public function uploadObject($key = '', $filePath = '', $acl = 'public-read') {
        
        $filePath = trim($filePath);
        $key = trim($key);
        
        return (strlen($filePath) > 0 && strlen($key) > 0 && file_exists($filePath)) ? $this->createObject($key, file_get_contents($filePath), $acl) : array();
    }
    
    public function listObjectsInBucket($bucket = '') {
        
        $params = $this->getParams();
        $client = $this->getClient();
        
        $bucket = trim($bucket);
        if(strlen($bucket) == 0) $bucket = $params['bucket'];
        
        $output = array();
        
        try {

            $marker = null;
            do {
                
                $input = array('Bucket' => $bucket);
                if($marker !== null) $input['Marker'] = $marker;
                
                $lastKey = '';
                $response = $client->listObjects($input)->toArray();
                if(isset($response['Contents'])) {
                    
                    foreach ($response['Contents'] as $r) {
                        $output[] = array(
                            'Key' => $r['Key'],
                            'ETag' => str_replace('"', '', $r['ETag']),
                        );
                        $lastKey = $r['Key'];
                    }
                }
                
                if(isset($response['IsTruncated']) && $response['IsTruncated'] && strlen($lastKey) > 0) {
                    $marker = $lastKey;
                    $continueLoop = true;
                }else {
                    $continueLoop = false;
                }
                
            }while($continueLoop);

        } catch (\Exception $ex) {
        }
        
        return $output;
    }
}