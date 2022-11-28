<?php

namespace Mrapps\AmazonBundle\Handler;

use Aws\Exception\MultipartUploadException;
use Aws\S3\MultipartUploader;
use Aws\S3\ObjectUploader;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManager;
use Liip\ImagineBundle\Controller\ImagineController;
use Mrapps\AmazonBundle\Interfaces\S3FileInterface;
use Symfony\Component\HttpFoundation\Request;

class S3Handler
{
    /**@var EntityManager $em*/
    private $em;

    /**@var ImagineController $imagineController*/
    private $imagineController;

    /**@var array $params*/
    private $params;

    /**@var bool $cdnEnabled*/
    private $cdnEnabled;

    /**@var string $cdnUrl*/
    private $cdnUrl;

    public function __construct(EntityManager $em, ImagineController $imagineController, $access, $secret, $region, $defaultBucket, $cdnEnabled, $cdnUrl)
    {
        $this->em = $em;
        $this->imagineController = $imagineController;

        $this->params = [
            'access' => $access,
            'secret' => $secret,
            'region' => $region,
            'bucket' => $defaultBucket
        ];

        $this->cdnEnabled = (bool) $cdnEnabled;
        $this->cdnUrl = trim($cdnUrl, '/');
    }

    private function getParams(): array
    {
        return $this->params;
    }

    private function isCdnEnabled(): bool{
        return $this->cdnEnabled && !empty($this->cdnUrl);
    }

    private function getClient(): S3Client
    {
        $params = $this->getParams();

        return new S3Client
        ([
            'version' => 'latest',
            'region' => $params['region'],
            'credentials' => [
                'key' => $params['access'],
                'secret' => $params['secret'],
            ]
        ]);
    }

    public function objectExists($key, $bucket = ''): bool
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

    public function headObject($key, $bucket): ?array
    {
        $key = trim($key);
        $bucket = trim($bucket);
        if (!empty($key) && !empty($bucket)) {
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

    public function createObject($key = '', $content = '', $options = [])
    {
        try {
            $result = $this->uploadObject($key, $content, 'public-read', $options);

            //Aggiornamento etag Database
            $now = new \DateTime();
            $etag = $this->getEtagForKey($key);
            $this->em->getRepository('MrappsAmazonBundle:S3Object')->setEtag($key, $etag, $now);

        } catch (\Exception $ex) {
            $result = [];
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

                $client->waitUntil('ObjectExists', ['Bucket' => $destBucket, 'Key' => $dest]);

                //Aggiornamento etag Database
                $now = new \DateTime();
                $etag = $this->getEtagForKey($dest);
                $this->em->getRepository('MrappsAmazonBundle:S3Object')->setEtag($dest, $etag, $now);
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

        $output = '';

        if (strlen($key) > 0) {

            $params = $this->getParams();
            $client = $this->getClient();

            if (strlen($bucket) == 0) $bucket = $params['bucket'];

            if ($this->objectExists($key, $bucket)) {

                $result = $client->getObject([
                    'Bucket' => $bucket,
                    'Key' => $key,
                ]);

                if(isset($result['Body'])){
                    $output .= $result['Body'];
                }
            }
        }

        return $output;
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

            if ($this->isCdnEnabled()) {
                return $this->cdnUrl . '/' . $key;
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

            // RedirectResponse object
            $imagemanagerResponse = $this->imagineController->filterAction(
                    $request,
                    $key,      // original image you want to apply a filter to
                    $filter              // filter defined in config.yml
                );

            // string to put directly in the "src" of the tag <img>
            $absoluteUrl = $imagemanagerResponse->headers->get('location');

            if ($this->isCdnEnabled()) {
                return str_replace($urlDomain, $this->cdnUrl, $absoluteUrl);
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

    public function uploadS3File(S3FileInterface $file): array
    {
        return $this->uploadObject(
            $file->getLocalPath(),
            $file->getRemoteRelativePath()
        );
    }

    public function uploadObject($key, $source, $acl = 'public-read', $options = []): array
    {
        if (empty($key) || empty($source)) {
            return [];
        }

        $params = $this->getParams();
        $client = $this->getClient();

        if (isset($options['ACL'])) {
            $acl = $options['ACL'];
            unset($options['ACL']);
        }

        if (is_file($source)) {
            $source = fopen($source, 'rb');
        }

        $uploader = new ObjectUploader(
            $client,
            $params['bucket'],
            $key,
            $source,
            $acl,
            $options
        );

        do {
            try {
                $result = $uploader->upload();
            } catch (MultipartUploadException $e) {
                rewind($source);
                $uploader = new MultipartUploader($client, $source, [
                    'state' => $e->getState(),
                ]);
            }
        } while (!isset($result));

        fclose($source);

        return $result->toArray();
    }

    public function listObjectsInBucket($bucket = null, $prefix = ''): array
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
