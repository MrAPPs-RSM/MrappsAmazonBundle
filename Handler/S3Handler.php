<?php

namespace Mrapps\AmazonBundle\Handler;

use Symfony\Component\DependencyInjection\Container;
use Doctrine\ORM\EntityManager;
use Aws\S3\S3Client;
use Symfony\Component\HttpFoundation\Request;


class S3Handler
{
    private $container;
    private $em;

    public function __construct(Container $container, EntityManager $em)
    {
        $this->container = $container;
        $this->em = $em;
    }

    private function getParams()
    {

        return array(
            'access' => ($this->container->hasParameter('mrapps_amazon.parameters.access')) ? $this->container->getParameter('mrapps_amazon.parameters.access') : '',
            'secret' => ($this->container->hasParameter('mrapps_amazon.parameters.secret')) ? $this->container->getParameter('mrapps_amazon.parameters.secret') : '',
            'region' => ($this->container->hasParameter('mrapps_amazon.parameters.region')) ? $this->container->getParameter('mrapps_amazon.parameters.region') : '',
            'bucket' => ($this->container->hasParameter('mrapps_amazon.parameters.default_bucket')) ? $this->container->getParameter('mrapps_amazon.parameters.default_bucket') : '',
        );
    }

    private function getClient()
    {

        $params = $this->getParams();

        return S3Client::factory(array(
            'key' => $params['access'],
            'secret' => $params['secret'],
            'region' => $params['region'],
        ));
    }

    public function objectExists($key, $bucket = '')
    {

        $key = trim($key);
        $bucket = trim($bucket);
        if (strlen($key) > 0) {
            $params = $this->getParams();
            $client = $this->getClient();

            if (strlen($bucket) == 0) $bucket = $params['bucket'];

            return $client->doesObjectExist($bucket, $key);
        }

        return false;

    }

    public function headObject($key, $bucket = '')
    {

        $key = trim($key);
        $bucket = trim($bucket);
        if (strlen($key) > 0) {
            $params = $this->getParams();
            $client = $this->getClient();

            if (strlen($bucket) == 0) $bucket = $params['bucket'];

            if ($this->objectExists($key, $bucket)) {
                return $client->headObject(array(
                    'Bucket' => $bucket,
                    'Key' => $key,
                ))->toArray();
            }
        }

        return null;
    }

    public function createObject($key = '', $content = '', $options = array())
    {

        $params = $this->getParams();
        $client = $this->getClient();

        try {

            //Fix opzioni in ingresso
            if (!is_array($options)) $options = array();
            if (!isset($options['ACL'])) $options['ACL'] = 'public-read';

            $result = $client->putObject(array_merge($options, array(
                'Bucket' => $params['bucket'],
                'Key' => $key,
                'Body' => $content,
            )))->toArray();

            $client->waitUntilObjectExists(array('Bucket' => $params['bucket'], 'Key' => $key));

            //Aggiornamento etag Database
            $etag = $this->getEtagForKey($key);
            $this->em->getRepository('MrappsAmazonBundle:S3Object')->setEtag($key, $etag);

        } catch (\Exception $ex) {
            $result = array();
        }

        return $result;
    }

    public function copyObject($source, $dest, $sourceBucket = '', $destBucket = '')
    {

        $params = $this->getParams();
        $client = $this->getClient();

        if (strlen($sourceBucket) == 0) $sourceBucket = $params['bucket'];
        if (strlen($destBucket) == 0) $destBucket = $params['bucket'];

        try {
            if ($this->objectExists($source, $sourceBucket)) {

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

    public function getObjectContent($key = '', $bucket = '')
    {

        $key = trim($key);
        $bucket = trim($bucket);
        if (strlen($key) > 0) {

            $params = $this->getParams();
            $client = $this->getClient();

            if (strlen($bucket) == 0) $bucket = $params['bucket'];

            if ($this->objectExists($key, $bucket)) {

                $result = $client->getObject(array(
                    'Bucket' => $bucket,
                    'Key' => $key,
                ));

                return (isset($result['Body'])) ? $result['Body'] . '' : '';
            }
        }

        return '';
    }

    /**
     * see the doc: http://docs.aws.amazon.com/aws-sdk-php/v2/api/class-Aws.S3.S3Client.html
     * $signedUrl = $client->getObjectUrl($bucket, 'data.txt', '+10 minutes');
     */
    public function getObjectUrlWithExpire($key, $expire, $force = false, $ignoreCdn = false)
    {
        return $this->getObjectUrl(
            $key,
            $force,
            $ignoreCdn,
            $expire
        );
    }

    public function getObjectUrl($key = '', $force = false, $ignoreCdn = false, $expire = null)
    {

        $key = trim($key);
        if (strlen($key) > 0) {
            $params = $this->getParams();
            $client = $this->getClient();

            //CDN params
            $enableCdn = ($this->container->hasParameter('mrapps_amazon.cdn.enable')) ? (bool)$this->container->getParameter('mrapps_amazon.cdn.enable') : false;
            $urlCdn = ($this->container->hasParameter('mrapps_amazon.cdn.url')) ? trim($this->container->getParameter('mrapps_amazon.cdn.url'), '/') : '';

            $cdnEnabled = ($enableCdn && !$ignoreCdn && strlen($urlCdn) > 0);
            if ($cdnEnabled) {
                return $urlCdn . '/' . $key;
            } else {
                return ((bool)$force || $client->doesObjectExist($params['bucket'], $key))
                    ? $client->getObjectUrl($params['bucket'], $key, $expire)
                    : '';
            }
        }

        return '';
    }

    public function getFilterUrl(Request $request = null, $key = '', $filter = '')
    {

        $key = trim($key);
        if (strlen($key) > 0) {
            $params = $this->getParams();

            $urlDomain = "https://s3-" . $params["region"] . ".amazonaws.com/" . $params["bucket"];

            //CDN params
            $enableCdn = ($this->container->hasParameter('mrapps_amazon.cdn.enable')) ? (bool)$this->container->getParameter('mrapps_amazon.cdn.enable') : false;
            $urlCdn = ($this->container->hasParameter('mrapps_amazon.cdn.url')) ? trim($this->container->getParameter('mrapps_amazon.cdn.url'), '/') : '';

            // RedirectResponse object
            $imagemanagerResponse = $this->container
                ->get('liip_imagine.controller')
                ->filterAction(
                    $request,
                    $key,      // original image you want to apply a filter to
                    $filter              // filter defined in config.yml
                );

            // string to put directly in the "src" of the tag <img>
            $absoluteUrl = $imagemanagerResponse->headers->get('location');


            $cdnEnabled = ($enableCdn && strlen($urlCdn) > 0);
            if ($cdnEnabled) {
                return str_replace($urlDomain, $urlCdn, $absoluteUrl);
            } else {
                return $absoluteUrl;
            }
        }

        return '';
    }

    public function getEtagForKey($key = '')
    {

        $key = trim($key);
        if (strlen($key) > 0) {
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

    public function getEtagForObject($key = '')
    {

        $etag = '';
        $key = trim($key);
        if (strlen($key) > 0) {

            $etag = $this->em->getRepository('MrappsAmazonBundle:S3Object')->getEtag($key);
            if (strlen($etag) == 0) {
                $etag = $this->getEtagForKey($key);
                if (strlen($etag) > 0) {
                    $this->em->getRepository('MrappsAmazonBundle:S3Object')->setEtag($key, $etag);
                }
            }
        }

        return $etag;
    }

    public function downloadObject($key = '', $savePath = '', $returnCompleteResponse = false, $bucket = '')
    {
        $key = trim($key);
        $bucket = trim($bucket);
        $savePath = trim($savePath);

        if (strlen($key) > 0 && strlen($savePath) > 0) {

            $params = $this->getParams();
            $client = $this->getClient();

            if(strlen($bucket) == 0) $bucket = $params['bucket'];

            if ($this->objectExists($key, $bucket)) {

                try {

                    $response = $client->getObject(array(
                        'Bucket' => $bucket,
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

    public function uploadObject($key = '', $filePath = '', $acl = 'public-read')
    {

        $filePath = trim($filePath);
        $key = trim($key);

        return (strlen($filePath) > 0 && strlen($key) > 0 && file_exists($filePath)) ? $this->createObject($key, file_get_contents($filePath), ['ACL' => $acl]) : array();
    }

    public function listObjectsInBucket($bucket = null, $prefix = '')
    {

        $params = $this->getParams();
        $client = $this->getClient();

        $bucket = trim($bucket);
        $prefix = trim($prefix);
        if (strlen($bucket) == 0) $bucket = $params['bucket'];

        $output = array();

        try {

            $marker = null;
            do {

                $input = array('Bucket' => $bucket);
                if ($marker !== null) $input['Marker'] = $marker;
                if (strlen($prefix) > 0) $input['Prefix'] = $prefix;

                $lastKey = '';
                $response = $client->listObjects($input)->toArray();
                if (isset($response['Contents'])) {

                    foreach ($response['Contents'] as $r) {
                        $output[] = array(
                            'Key' => $r['Key'],
                            'LastModified' => new \DateTime($r['LastModified']),
                            'ETag' => str_replace('"', '', $r['ETag']),
                            'Size' => intval($r['Size']),
                        );
                        $lastKey = $r['Key'];
                    }
                }

                if (isset($response['IsTruncated']) && $response['IsTruncated'] && strlen($lastKey) > 0) {
                    $marker = $lastKey;
                    $continueLoop = true;
                } else {
                    $continueLoop = false;
                }

            } while ($continueLoop);

        } catch (\Exception $ex) {
        }

        return $output;
    }

    public function deleteObject($key = '', $bucket = '')
    {
        $key = trim($key);
        $bucket = trim($bucket);

        if (strlen($key) > 0) {
            $params = $this->getParams();
            $client = $this->getClient();

            if(strlen($bucket) == 0) $bucket = $params['bucket'];

            try {
                $result = $client->deleteObject(array(
                    'Bucket' => $bucket,
                    'Key' => $key,
                ))->toArray();
            } catch (\Exception $ex) {
                $result = array();
            }

            return $result;
        }

        return false;
    }
}
