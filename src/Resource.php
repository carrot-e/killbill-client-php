<?php
/*
 * Copyright 2011-2017 Ning, Inc.
 *
 * Ning licenses this file to you under the Apache License, version 2.0
 * (the "License"); you may not use this file except in compliance with the
 * License.  You may obtain a copy of the License at:
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.  See the
 * License for the specific language governing permissions and limitations
 * under the License.
 */

namespace Killbill\Client;

use Killbill\Client\Exception\ResourceParsingException;
use Killbill\Client\Exception\ResponseException;

abstract class Resource /* implements JsonSerializable */
{
    protected $auditLogs = null;
    /** @var Client */
    protected $client;

    public function setAuditLogs($auditLogs)
    {
        $this->auditLogs = $auditLogs;
    }

    public function getAuditLogs()
    {
        return $this->auditLogs;
    }

    /**
     * Makes the query parameters to use in complex requests
     *
     * @param array $queryData Array of key=>value data to use as query parameters
     *
     * @return string Query string to use in a request
     */
    protected function makeQuery($queryData)
    {
        if (empty($queryData)) {
            return '';
        }

        $query = http_build_query($queryData);

        return '?'.$query;
    }

    /**
     * Issues a GET request to killbill
     *
     * @param string        $uri     Relative or absolute killbill url
     * @param string[]|null $headers Any additional headers
     *
     * @return Response A response object
     */
    protected function getRequest($uri, $headers = null)
    {
        $this->initClientIfNeeded();

        return $this->client->request(Client::GET, $uri, null, null, null, null, $headers);
    }

    /**
     * Issues a create request to killbill
     *
     * @param string        $uri     Relative or absolute killbill url
     * @param string|null   $user    User requesting the creation
     * @param string|null   $reason  Reason for the creation
     * @param string|null   $comment Any addition comment
     * @param string[]|null $headers Any additional headers
     *
     * @return Response A response object
     */
    protected function createRequest($uri, $user, $reason, $comment, $headers = null)
    {
        $this->initClientIfNeeded();

        return $this->client->request(Client::POST, $uri, $this->jsonSerialize(), $user, $reason, $comment, $headers);
    }

    /**
     * Issues an update request to killbill
     *
     * @param string        $uri     Relative or absolute killbill url
     * @param string|null   $user    User requesting the update
     * @param string|null   $reason  Reason for the update
     * @param string|null   $comment Any addition comment
     * @param string[]|null $headers Any additional headers
     *
     * @return Response A response object
     */
    protected function updateRequest($uri, $user, $reason, $comment, $headers = null)
    {
        $this->initClientIfNeeded();

        return $this->client->request(Client::PUT, $uri, $this->jsonSerialize(), $user, $reason, $comment, $headers);
    }

    /**
     * Issues a DELETE request to killbill
     *
     * @param string        $uri     Relative or absolute killbill url
     * @param string|null   $user    User requesting the deletion
     * @param string|null   $reason  Reason for the deletion
     * @param string|null   $comment Any addition comment
     * @param string[]|null $headers Any additional headers
     *
     * @return Response A response object
     */
    protected function deleteRequest($uri, $user, $reason, $comment, $headers = null)
    {
        $this->initClientIfNeeded();

        return $this->client->request(Client::DELETE, $uri, $this->jsonSerialize(), $user, $reason, $comment, $headers);
    }

    /**
     * Given a response object, lookup the resource in killbill via
     * the location header
     *
     * @param string        $class    Resource class
     * @param Response      $response Response object
     * @param string[]|null $headers  Any additional headers
     *
     * @return Resource|Resource[]|null An instance or collection of resources
     */
    protected function getFromResponse($class, $response, $headers = null)
    {
        if ($response === null) {
            return null;
        }

        $reponseHeaders = $response->headers;
        if ($reponseHeaders === null || !isset($reponseHeaders['Location']) || $reponseHeaders['Location'] === null) {
            return null;
        }

        $this->initClientIfNeeded();

        $getResonse = $this->getRequest($reponseHeaders['Location'], $headers);
        if ($getResonse === null || $getResonse->body === null) {
            return null;
        }

        return $this->getFromBody($class, $getResonse);
    }

    /**
     * Given a response object, decode the body
     *
     * @param string   $class    resource class (optional)
     * @param Response $response response object
     *
     * @return Resource|Resource[]|null An instance or collection of resources
     */
    protected function getFromBody($class, $response)
    {
        $dataJson = json_decode($response->body);

        if ($dataJson === null) {
            // cater for lack of X-Killbill-ApiKey and X-Killbill-ApiSecret headers
            if (isset($response->statusCode) && isset($response->body)) {
                return array('statusCode' => $response->statusCode, 'body' => $response->body);
            }

            return null;
        }

        return $this->fromJson($class, $dataJson);
    }

    /**
     * Given a json object, create the associated resource(s)
     * instance(s)
     *
     * @param string          $class Resource class name
     * @param object|object[] $json  Decoded json from killbill
     *
     * @return string|Resource|Resource[]|null An instance or collection of resources
     */
    private function fromJson($class, $json)
    {
        if (is_array($json)) {
            return $this->fromJsonArray($class, $json);
        } elseif (is_string($json)) {
            return $json;
        } else {
            return $this->fromJsonObject($class, $json);
        }
    }

    /**
     * @param string $class
     * @param array  $json
     *
     * @return array
     */
    private function fromJsonArray($class, $json)
    {
        $objects = array();

        foreach ($json as $object) {
            $objects[] = $this->fromJson($class, $object);
        }

        return $objects;
    }

    /**
     * @param string        $class
     * @param string|object $json
     *
     * @return object|string
     * @throws ResourceParsingException
     * @throws ResponseException
     */
    private function fromJsonObject($class, $json)
    {
        if ($json === null) {
            return null;
        }

        if (isset($json->className) && isset($json->code) && isset($json->message)) {
            // An exception has been returned by killbill
            // also available: $json->causeClassName, $json->causeMessage, $json->stackTrace
            throw new ResponseException('Killbill returned an exception: '.$json->className.' '.$json->message, $json->code);
        }

        if (!class_exists($class)) {
            throw new ResourceParsingException('Could not instantiate a class of type '.$class);
        }

        $object = new $class();

        foreach ($json as $key => $value) {
            $typeMethod = 'get'.ucfirst($key).'Type';
            if (method_exists($object, $typeMethod)) {
                $type = $object->{$typeMethod}();

                // A type has been specified for this property, so trying to convert the value into this type
                if ($type) {
                    $value = $this->fromJsonObject($type, $value);
                }
            }

            $setterMethod = 'set'.ucfirst($key);
            if (!method_exists($object, $setterMethod)) {
                throw new ResourceParsingException('Could not call method '.$setterMethod.' on object of type '.get_class($object));
            }

            $object->{$setterMethod}($value);
        }

        return $object;
    }

    /**
     * Returns the resource as json
     *
     * @return string Json encoded resource
     */
    public function jsonSerialize()
    {
        $x = $this->prepareForSerialization();

        return json_encode($x);
    }

    /**
     * Converts the resource into an array
     *
     * @return array
     */
    public function prepareForSerialization()
    {
        $keys = get_object_vars($this);

        unset($keys['client']);

        foreach ($keys as $k => $v) {
            if ($v instanceof Resource) {
                $keys[$k] = $v->prepareForSerialization();
            } else {
                if (is_array($v)) {
                    $keys[$k] = array();
                    foreach ($v as $ve) {
                        if ($ve instanceof Resource) {
                            array_push($keys[$k], $ve->prepareForSerialization());
                        } else {
                            array_push($keys[$k], $ve);
                        }
                    }
                }
            }
        }

        $sortedArray = array();
        $arrayKeys   = array_keys($keys);
        asort($arrayKeys, SORT_STRING | SORT_NATURAL);

        foreach ($arrayKeys as $arrayKey) {
            $sortedArray[$arrayKey] = $keys[$arrayKey];
        }

        return $sortedArray;
    }

    /**
     *
     */
    private function initClientIfNeeded()
    {
        if (is_null($this->client)) {
            $this->client = new Client();
        }
    }
}