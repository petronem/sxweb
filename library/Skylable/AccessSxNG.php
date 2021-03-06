<?php
/*
    The contents of this file are subject to the Common Public Attribution License
    Version 1.0 (the "License"); you may not use this file except in compliance with
    the License. You may obtain a copy of the License at
    http://opensource.org/licenses/cpal_1.0. The License is based on the Mozilla
    Public License Version 1.1 but Sections 14 and 15 have been added to cover use
    of software over a computer network and provide for limited attribution for the
    Original Developer. In addition, Exhibit A has been modified to be consistent with
    Exhibit B.
    
    Software distributed under the License is distributed on an "AS IS" basis, WITHOUT
    WARRANTY OF ANY KIND, either express or implied. See the License for the
    specific language governing rights and limitations under the License.
    
    The Original Code is the SXWeb project.
    
    The Original Developer is the Initial Developer.
    
    The Initial Developer of the Original Code is Skylable Ltd (info-copyright@skylable.com). 
    All portions of the code written by Initial Developer are Copyright (c) 2013 - 2015
    the Initial Developer. All Rights Reserved.

    Contributor(s):    

    Alternatively, the contents of this file may be used under the terms of the
    Skylable White-label Commercial License (the SWCL), in which case the provisions of
    the SWCL are applicable instead of those above.
    
    If you wish to allow use of your version of this file only under the terms of the
    SWCL and not to allow others to use your version of this file under the CPAL, indicate
    your decision by deleting the provisions above and replace them with the notice
    and other provisions required by the SWCL. If you do not delete the provisions
    above, a recipient may use your version of this file under either the CPAL or the
    SWCL.
*/

/**
 * FIXME: WORK IN PROGRESS
 * TODO: fix content-type header parsing
 *
 * Access the SX cluster using the RESTful API.
 *
 */
class Skylable_AccessSxNG {

    protected
        $_headers,
        $_body,
        $_error,
        $_error_no,
        $_response,
        $_node_list = array(),

        /**
         * Connect using SSL 
         * @var bool
         */
        $_use_ssl = TRUE,

        /**
         * Alternative server port.
         * If NULL use the default one.
         * @var null
         */
        $_port = NULL,
        $_secret_key = NULL,
        $_cluster = NULL,

        /**
         * @var Zend_Log
         */
        $_logger = NULL;

    const
        // Verbs used for REST calls
        REQUEST_VERB_GET = 'GET',
        REQUEST_VERB_POST = 'POST',
        REQUEST_VERB_DELETE = 'DELETE',
        REQUEST_VERB_PUT = 'PUT';


    /**
     * Initialize the object to use.
     *
     * Mandatory parameters:
     * 'secret_key' - the user secret key (the authorization token)
     * 'cluster' - the cluster on which operate (can be an IP address for non-DNS clusters)
     * 
     * Other parameters:
     * 'port' - the port number of the cluster
     * 'use_ssl' - boolean, TRUE use SSL (the default), FALSE otherwise
     * 'logger' - Zend_Log use this logger
     *
     * @param array $options
     */
    public function __construct(array $options = array()) {
        if (array_key_exists('secret_key', $options)) {
            $this->_secret_key = $options['secret_key'];
        }

        if (array_key_exists('cluster', $options)) {
            $this->_cluster = $options['cluster'];
            if (strncmp($this->_cluster, 'sx://', 5) == 0) {
                $this->_cluster = substr($this->_cluster, 5);
            }
        }

        if (array_key_exists('port', $options)) {
            if (is_numeric($options['port'])) {
                $this->_port = $options['port'];
            }
        }

        if (array_key_exists('use_ssl', $options)) {
            $this->_use_ssl = (bool)$options['use_ssl'];
        } else {
            $this->_use_ssl = TRUE;
        }

        if (array_key_exists('logger', $options)) {
            $this->_logger = $options['logger'];
        } else {
            $bs = Zend_Controller_Front::getInstance()->getParam('bootstrap');
            if (is_object($bs)) {
                $this->_logger = $bs->getResource('log');
            }
        }
        
        $this->clear();
    }

    /**
     * Return a base URL to use in cURL calls.
     * 
     * @param string $host the host (IP or domain name) to call
     * @return string
     */
    private function getBaseURL($host) {
        return 'http' . ($this->_use_ssl ? 's' : '') . '://'.$host;
    }

    /**
     * Returns the global logger.
     *
     * @return Zend_Log
     */
    public function getLogger() {
        return $this->_logger;
    }

    /**
     * Breaks the auth token into his parts.
     *
     * IMPORTANT: the user id and user key are returned as strings of hexadecimal bytes (2 char per byte).
     *
     * @param string $token base64 encoded auth token
     * @param string $user_id the resulting user id
     * @param string $user_key the resulting user key
     * @return boolean
     */
    public function processAuthToken($token, &$user_id, &$user_key) {
        $b = base64_decode($token);
        $id = unpack('H40', substr($b, 0, 20));
        $user_id = $id[1];
        $key = unpack('H40', substr($b, 20, 40));
        $user_key = $key[1];
        return TRUE;
    }

    /**
     * Check if a string is an auth token.
     * 
     * @param string $token
     * @return bool
     */
    public static function isAuthToken($token) {
        $b = base64_decode($token, TRUE);
        if ($b !== FALSE) {
            if (strlen($b) >= 40) {
                return TRUE;
            }
        }
        
        return FALSE;
    }

    /**
     * Returns the request signature.
     *
     * @param string $token base64 encoded auth token
     * @param string $verb
     * @param string $path
     * @param string $date
     * @param string $sha1_body SHA1 of the request body
     * @return string the request signature
     */
    public function getRequestSignature($token, $verb, $path, $date, $sha1_body) {
        $user_id = '';
        $user_key = '';
        $this->processAuthToken($token, $user_id, $user_key);
        $req_str = $verb."\n".$path."\n".$date."\n".$sha1_body."\n";

        $hmac = hash_hmac('sha1', $req_str, pack('H*',$user_key) );
        $raw_auth = $user_id.$hmac."0000";
        $pack_raw = pack('H*', $raw_auth);
        return base64_encode($pack_raw);
    }

    /**
     * Returns the RFC 1123 date for requests.
     *
     * @return string
     */
    public function getRequestDate() {
        return gmdate('D, d M Y H:i:s T');
    }

    /**
     *
     * Mandatory params:
     * 'url' -> string - url to call
     * 'date' -> string - RFC1123 date
     * 'authorization' -> string - SX call authorization key
     *
     * Extra:
     * 'verb' - the verb to use
     * 'headers' - array of string with the additional headers
     * 'content-length' - unsigned integer specifies content length
     * 'post-fields' - string of data for POST verb
     * 'curl-options' -  associative array of curl options: array( 'curl_option' => 'value')
     *
     * @param array $params
     * @param boolean $return_transfer TRUE return request data, FALSE otherwise
     * @return bool|mixed
     */
    protected function RESTCall(array $params, $return_transfer = FALSE) {
        $this->clear();

        $res = curl_init();
        if ($res !== FALSE) {
            curl_setopt($res, CURLOPT_URL, $params['url']);
            $this->getLogger()->debug(__METHOD__.' URL: ' .var_export($params['url'], TRUE));
            
            if (!empty($this->_port)) {
                curl_setopt($res, CURLOPT_PORT, $this->_port);
                $this->getLogger()->debug(__METHOD__.' PORT: ' .var_export($this->_port, TRUE));
            }

            if (array_key_exists('verb', $params)) {
                switch($params['verb']) {
                    case self::REQUEST_VERB_POST:
                        curl_setopt($res, CURLOPT_POST, TRUE);
                        if (array_key_exists('post-fields', $params)) {
                            curl_setopt($res, CURLOPT_POSTFIELDS, $params['post-fields']);
                        }
                        break;
                    case self::REQUEST_VERB_PUT: curl_setopt($res, CURLOPT_PUT, TRUE); break;
                    case self::REQUEST_VERB_DELETE: curl_setopt($res, CURLOPT_CUSTOMREQUEST, 'DELETE'); break;
                    default:
                        curl_setopt($res, CURLOPT_HTTPGET, TRUE);
                }
            } else {
                $is_custom = FALSE; // Custom request flag
                if (array_key_exists('curl-options', $params)) {
                    $is_custom = array_key_exists(CURLOPT_CUSTOMREQUEST, $params['curl-options']);
                }
                if (!$is_custom) {
                    curl_setopt($res, CURLOPT_HTTPGET, TRUE);    
                }
            }

            curl_setopt($res, CURLOPT_FOLLOWLOCATION, TRUE);
            curl_setopt($res, CURLOPT_HEADER, FALSE);

            $headers = array(
                'Date: '.$params['date'],
                'Authorization: SKY '.$params['authorization']
            );
            if (array_key_exists('content-length', $params)) {
                $headers[] = 'Content-Length: '.strval($params['content-length']);
            }

            if (array_key_exists('headers', $params)) {
                $headers = array_merge($headers, $params['headers']);
            }

            $this->getLogger()->debug(__METHOD__.' HEADERS: ' .var_export($headers, TRUE));

            curl_setopt($res, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($res, CURLOPT_RETURNTRANSFER, $return_transfer);
            curl_setopt($res, CURLOPT_HEADERFUNCTION, array($this, 'writeHeader') );
            curl_setopt($res, CURLOPT_WRITEFUNCTION, array($this, 'writeBody') );

            curl_setopt($res, CURLOPT_SSL_VERIFYPEER, FALSE);
            curl_setopt($res, CURLOPT_SSL_VERIFYHOST, FALSE);

            if (array_key_exists('curl-options', $params)) {
                foreach($params['curl-options'] as $curlopt => $value) {
                    curl_setopt($res, $curlopt, $value);
                    $this->getLogger()->debug(__METHOD__.' CURLOPT: '.$curlopt .print_r($value, TRUE));
                }
            }

            if (curl_exec($res) === FALSE) {
                $this->_error = curl_error($res);
                $this->_error_no = curl_errno($res);
                $this->getLogger()->err(__METHOD__.' cURL error: ' .var_export($this->_error, TRUE));
            }
            $this->getLogger()->debug(__METHOD__.' '.print_r($this->_headers, TRUE));
            $this->getLogger()->debug(__METHOD__.' '.print_r($this->_body, TRUE));

            curl_close($res);
            return ($this->_error_no == 0);
        }
        return FALSE;
    }

    protected function parseHeaders() {
        /**
         * FIXME: This approach will fail with folded lines, but... who cares! 8-)
         */

        /*
         * From: http://www.faqs.org/rfcs/rfc2616.html
         *
         * HTTP/1.1 header field values can be folded onto multiple lines if the
         * continuation line begins with a space or horizontal tab. All linear
         * white space, including folding, has the same semantics as SP. A
         * recipient MAY replace any linear white space with a single SP before
         * interpreting the field value or forwarding the message downstream.
         *
         * LWS            = [CRLF] 1*( SP | HT )
         *
        */

        $this->_response = array();

        if (preg_match('#^(?P<proto>HTTP/\d\.\d)\s(?P<httpcode>\d{3})\s(?P<httpmessage>[^\r]*)#', $this->_headers, $matches) == 1) {
            $this->_response['http_code'] = intval($matches['httpcode']);
            $this->_response['http_message'] = $matches['httpmessage'];
            $this->_response['headers'] = array();

            if (preg_match_all('#^(?P<header>[^:]+):\s(?P<values>[^\r]+)?#m', $this->_headers, $matches, PREG_PATTERN_ORDER, strpos($this->_headers, "\r\n"))) {
                foreach($matches['header'] as $idx => $header) {
                    $this->_response['headers'][strtolower($header)] = $matches['values'][$idx];
                }
                return TRUE;
            }
        }
        return FALSE;
    }


    protected function parseBody() {

    }

    /**
     * Used by cURL to save the request headers into the class.
     * @param resource $res
     * @param string $data
     * @return int
     * @see RESTCall
     */
    public function writeHeader($res, $data) {
        $this->_headers .= $data;
        return strlen($data);
    }

    /**
     * Used by cURL to save the request body into the class.
     * @param resource $res
     * @param string $data
     * @return int
     * @see RESTCall
     */
    public function writeBody($res, $data) {
        $this->_body .= $data;
        return strlen($data);
    }

    /**
     * Tells if the last REST call has errors. Only reports cURL library errors.
     * @return bool
     */
    public function hasRESTError() {
        return ($this->_error_no != 0);
    }

    /**
     * Checks if a JSON reply is an error and throws an exception
     * @param array $reply the parsed JSON reply
     * @throws Skylable_AccessSxException
     * @throws Skylable_InvalidCredentialsException
     * @throws Skylable_VolumeNotFoundException
     */
    protected function replyIsError($reply) {
        if (is_array($reply)) {
            if (array_key_exists('ErrorId', $reply)) {
                $err_msg = (array_key_exists('ErrorMessage', $reply) ? $reply['ErrorMessage'] : 'An error occurred');
                if (stripos($err_msg, 'invalid credentials') !== FALSE) {
                    throw new Skylable_InvalidCredentialsException($err_msg, $reply['ErrorId'] );
                } elseif (stripos($err_msg, 'no such volume') !== FALSE) {
                    throw new Skylable_VolumeNotFoundException($err_msg, $reply['ErrorId'] );
                }
                
                throw new Skylable_AccessSxException( $err_msg, $reply['ErrorId'] );
            }
        }
    }

    /**
     * Returns the nodes that take part in the SX cluster.
     *
     * See: https://wiki.skylable.com/wiki/REST_API_List_Nodes
     *
     * @return array|bool
     */
    public function nodeList() {
        $date = $this->getRequestDate();

        if ($this->RESTCall(
            array(
                'url' => $this->getBaseURL($this->_cluster).'/?nodeList',
                'date' => $date,
                'authorization' => $this->getRequestSignature($this->_secret_key, 'GET', '?nodeList', $date, sha1(''))
            )
        )) {
            // Parse the reply and saves internally for caching
            if ($this->parseHeaders()) {
                if ($this->_response['http_code'] == 200 && $this->isJSON()) {
                    $data = json_decode($this->_body, TRUE);
                    $this->replyIsError($data);
                    if (!is_null($data)) {
                        if (array_key_exists('nodeList', $data)) {
                            $this->_node_list = $data['nodeList'];
                            return $this->_node_list;
                        }
                    }
                } else {
                    $this->checkReply();
                }
            }
        }
        return FALSE;
    }

    /**
     * List nodes responsible for a volume.
     *
     * See: https://wiki.skylable.com/wiki/REST_API_Locate_Volume
     *
     * @param $volume
     * @return array|bool
     */
    public function volumeNodeList($volume) {
        $date = $this->getRequestDate();

        if ($this->RESTCall(
            array(
                'url' => $this->getBaseURL($this->_cluster).'/'.$volume.'?o=locate',
                'date' => $date,
                'authorization' => $this->getRequestSignature($this->_secret_key, 'GET', $volume.'?o=locate', $date, sha1(''))
            )
        )) {

            if ($this->parseHeaders()) {
                if ($this->_response['http_code'] == 200 && $this->isJSON()) {
                    $data = json_decode($this->_body, TRUE);
                    $this->replyIsError($data);
                    if (!is_null($data)) {
                        if (array_key_exists('nodeList', $data)) {
                            return $data['nodeList'];
                        }
                    }
                } else {
                    $this->checkReply();
                }
            }
        }
        return FALSE;
    }

    /**
     * Returns a list of users having access to a volume and the type of grants they have.
     *
     * See: https://wiki.skylable.com/wiki/REST_API_Get_Volume_ACL
     *
     * @param $volume
     * @return bool|mixed
     */
    public function getACL($volume) {
        $date = $this->getRequestDate();

        if ($this->RESTCall(
            array(
                'url' => $this->getBaseURL($this->_cluster).'/'.$volume.'?o=acl',
                'date' => $date,
                'authorization' => $this->getRequestSignature($this->_secret_key, 'GET', $volume.'?o=acl', $date, sha1(''))
            )
        )) {

            if ($this->parseHeaders()) {
                if ($this->_response['http_code'] == 200 && $this->isJSON()) {
                    $data = json_decode($this->_body, TRUE);
                    $this->replyIsError($data);
                    if (!is_null($data)) {
                        return $data;
                    }
                } else {
                    $this->checkReply();
                }
            }
        }
        return FALSE;
    }

    /**
     * Returns all the accessible volumes in the SX cluster.
     *
     * See: https://wiki.skylable.com/wiki/REST_API_List_Volumes
     *
     * @return bool
     */
    public function volumeList() {
        $date = $this->getRequestDate();
        if ($this->RESTCall(
            array(
                'url' => $this->getBaseURL($this->_cluster).'/?volumeList',
                'date' => $date,
                'authorization' => $this->getRequestSignature($this->_secret_key, 'GET', '?volumeList', $date, sha1(''))
            )
        )) {

            if ($this->parseHeaders()) {
                if ($this->_response['http_code'] == 200 && $this->isJSON()) {
                    $volumelist = json_decode($this->_body, TRUE);
                    $this->replyIsError($volumelist);
                    if (!is_null($volumelist)) {
                        if (array_key_exists('volumeList', $volumelist)) {
                            return $volumelist['volumeList'];
                        }
                    }
                } else {
                    $this->checkReply();
                }
            }
        }
        return FALSE;
    }

    /**
     * Reset the internal values.
     */
    public function clear() {
        $this->_headers = '';
        $this->_body = '';
        $this->_error = '';
        $this->_error_no = 0;
        $this->_response = array();
    }

    /**
     * List files in an SX volume.
     *
     * See: https://wiki.skylable.com/wiki/REST_API_List_Files
     *
     * @param string $volume the name of the volume to locate
     * @param string $pattern globbing pattern to limit the results returned to only those matching it
     * @param bool $recursive TRUE list files recursively, FALSE otherwise
     * @return bool|mixed
     */
    public function ls($volume, $pattern = '', $recursive = FALSE) {
        // Gets the volume node list
        $nodes = $this->volumeNodeList($volume);
        if ($nodes !== FALSE) {
            $req = $volume.'?o=list';
            if(!empty($pattern)) {
                $req .= '&filter='.rawurlencode($pattern);
            }
            if ($recursive) $req .= '&recursive';

            foreach($nodes as $node) {
                $date = $this->getRequestDate();

                if ($this->RESTCall(
                    array(
                        'url' => $this->getBaseURL($node).'/'.$req,
                        'date' => $date,
                        'authorization' => $this->getRequestSignature($this->_secret_key, 'GET', $req, $date, sha1(''))
                    )
                )) {

                    if ($this->parseHeaders()) {
                        if ($this->_response['http_code'] == 200 && $this->isJSON()) {
                            $data = json_decode($this->_body, TRUE);
                            $this->replyIsError($data);
                            if (!is_null($data)) {
                                return $data;
                            }
                        } else {
                            $this->checkReply();
                        }
                    }
                }

            }
        }
        return FALSE;
    }

    const
        FILE_TYPE_NONE = 0,
        FILE_TYPE_DIR = 1,
        FILE_TYPE_FILE = 2,
        FILE_TYPE_VOLUME = 3,
        FILE_TYPE_ANY = 4;

    /**
     * Checks if a file exists on the cluster.
     *
     * IMPORTANT:
     * The file type check is strict, so:
     * 'my-vol' -> returns FILE_TYPE_VOLUME
     * 'my-vol/' -> returns FILE_TYPE_DIR (the dir is '/')
     *
     * The path must be complete: 'vol-name/file/path'.
     * On success set the $file_type parameter with the file type, which is
     * one of the FILE_TYPE_* class constants.
     *
     * @param string $path the path to check
     * @param integer $file_type the file type
     * @return bool TRUE if the file exists, FALSE otherwise
     * @throws Skylable_AccessSxException
     */
    public function fileExists($path, &$file_type) {
        $this->getLogger()->debug(__METHOD__.' Checking: '.$path);
        $file_type = self::FILE_TYPE_NONE;
        $volume = My_Utils::getRootFromPath($path);
        $file = My_Utils::skipPath($path, 2);

        if (strlen($file) == 0 || $file == '/') {
            $this->getLogger()->debug(__METHOD__.' file is empty or /');
            if ($this->volumeExists($path)) {
                $this->getLogger()->debug(__METHOD__.' volume exists');
                if ($path[strlen($path) - 1] == '/') {
                    $file_type = self::FILE_TYPE_DIR;
                } else {
                    $file_type = self::FILE_TYPE_VOLUME;
                }
                return TRUE;
            }
            $this->getLogger()->debug(__METHOD__.' volume don\'t exists');
            return FALSE;
        }

        $file_list = $this->ls($volume, $file);
        if ($file_list === FALSE) {
            return FALSE;
        }
        foreach($file_list['fileList'] as $f_name => $f_data) {
            if (My_Utils::isSamePath($f_name, $file)) {
                $file_type = empty($f_data) ? self::FILE_TYPE_DIR : self::FILE_TYPE_FILE;
                return TRUE;
            }
        }
        return FALSE;
    }

    /**
     * Tells if a volume exists.
     *
     * @param string $path the volume name (can be a complete path)
     * @return bool TRUE if the volume exists, FALSE if not or on errors
     *
     */
    public function volumeExists($path) {
        $volume = My_Utils::getRootFromPath($path);
        $this->getLogger()->debug(__METHOD__.' checking volume: ' .var_export($volume, TRUE));
        if ($volume === FALSE || strlen($volume) == 0) {
            return FALSE;
        }
        $volumes = $this->volumeList();
        $this->getLogger()->debug(__METHOD__.' volumes: ' .var_export($volumes, TRUE));
        if ($volumes === FALSE) {
            return FALSE;
        }

        return array_key_exists($volume, $volumes);
    }

    /**
     * Delete an existing SX file object.
     *
     * See: https://wiki.skylable.com/wiki/REST_API_Delete_File
     *
     * @param $volume
     * @param $path
     * @return bool|mixed
     */
    public function delete($volume, $path) {
        // Gets the volume node list
        $nodes = $this->volumeNodeList($volume);
        if ($nodes !== FALSE) {
            $req = $volume.'/'.$path;

            foreach($nodes as $node) {
                $date = $this->getRequestDate();

                if ($this->RESTCall(
                    array(
                        'url' => $this->getBaseURL($node).'/'.$req,
                        'date' => $date,
                        'authorization' => $this->getRequestSignature($this->_secret_key, 'DELETE', $req, $date, sha1('')),
                        'verb' => self::REQUEST_VERB_DELETE
                    )
                )) {

                    if ($this->parseHeaders()) {
                        if ($this->_response['http_code'] == 200 && $this->isJSON()) {
                            $data = json_decode($this->_body, TRUE);
                            $this->replyIsError($data);
                            if (!is_null($data)) {
                                return $data;
                            }
                        } else {
                            $this->checkReply();
                        }
                    }
                }
            }
        }
        return FALSE;
    }

    /**
     * Retrieves the list of blocks of which a file is comprised of and the nodes where those blocks are available.
     *
     * See: https://wiki.skylable.com/wiki/REST_API_Get_File
     *
     * @param $volume
     * @param $path
     * @return bool|mixed
     */
    public function getFile($volume, $path) {
        // Gets the volume node list
        $nodes = $this->volumeNodeList($volume);
        if ($nodes !== FALSE) {
            $req = $volume.'/'.$path;

            foreach($nodes as $node) {
                $date = $this->getRequestDate();

                if ($this->RESTCall(
                    array(
                        'url' => $this->getBaseURL($node).'/'.$req,
                        'date' => $date,
                        'authorization' => $this->getRequestSignature($this->_secret_key, 'GET', $req, $date, sha1(''))
                    )
                )) {

                    if ($this->parseHeaders()) {
                        if ($this->_response['http_code'] == 200 && $this->isJSON()) {
                            $data = json_decode($this->_body, TRUE);
                            $this->replyIsError($data);
                            if (!is_null($data)) {
                                return $data;
                            }
                        } else {
                            $this->checkReply();
                        }
                    }
                }
            }
        }
        return FALSE;
    }

    /**
     * Retrieves the metadata associated with an existing SX file.
     *
     * See: https://wiki.skylable.com/wiki/REST_API_Get_File_Metadata
     *
     * @param $volume
     * @param $path
     * @return bool|mixed
     */
    public function getFileMetadata($volume, $path) {
        return $this->getFile($volume, $path.'?fileMeta');
    }

    /**
     * Tells if the response is a JSON
     *
     * @return bool
     */
    protected function isJSON() {
        return (strncmp($this->_response['headers']['content-type'], 'application/json', strlen('application/json')) == 0);
    }

    /**
     * Sets cluster metadata.
     * 
     * If the $meta parameter is a string, it is used as the key to set, and the $value parameter is the value.
     * If the $meta parameter is an associative array, use its key,value pairs set the metadata. In this 
     * case the $value parameter is ignored.
     * 
     * IMPORTANT: The API sets all metadata at once, so to alter only one value you should before get 
     * all the meta, prepare an array of new values then use this array as parameter.
     * 
     * @param string|array $meta
     * @param string $value
     * @return bool
     * @throws Skylable_AccessSxException
     */
    public function setClusterMetadata($meta, $value = NULL) {

        $date = $this->getRequestDate();
        if (is_array($meta)) {
            $meta_arr = array();
            foreach($meta as $mk => $mv) {
                $meta_arr[$mk] = bin2hex($mv); 
            }
            $json = Zend_Json::encode( array( "clusterMeta" => $meta_arr ) );
        } else {
            $json = Zend_Json::encode( array( "clusterMeta" => array( $meta => bin2hex($value) )) );    
        }
        
        if ($this->RESTCall(
            array(
                'url' => $this->getBaseURL($this->_cluster).'/.clusterMeta',
                'date' => $date,
                'authorization' => $this->getRequestSignature($this->_secret_key, 'PUT', '.clusterMeta', $date, sha1($json)),
                'headers' => array('Content-Type: application/json'),
                'content-length' => strlen($json),
                'curl-options' => array(
                    CURLOPT_CUSTOMREQUEST => 'PUT',
                    CURLOPT_POSTFIELDS => $json
                )
            )
        )) {

            if ($this->parseHeaders()) {
                if ($this->_response['http_code'] == 200 && $this->isJSON()) {
                    $data = json_decode($this->_body, TRUE);
                    $this->replyIsError($data);
                    if ($this->isJobReply($data)) {
                        // Do the polling to check if the job finished
                        while(TRUE) {
                            usleep($data['minPollInterval']);
                            $date = $this->getRequestDate();
                            $req = '.results/'.$data['requestId'];
                            if ($this->RESTCall(
                                array(
                                    'url' => $this->getBaseURL($this->_cluster).'/'.$req,
                                    'date' => $date,
                                    'authorization' => $this->getRequestSignature($this->_secret_key, 'GET', $req, $date, sha1(''))
                                )
                            )) {
                                if (!$this->parseHeaders()) {
                                    $this->getLogger()->debug(__METHOD__.': Failed to parse JOB reply headers');
                                    break;
                                }
                                if ($this->_response['http_code'] == 200 && $this->isJSON()) {
                                    $response_data = json_decode($this->_body, TRUE);
                                    $this->replyIsError($response_data);
                                    if (!$this->isJobRequestReply($response_data)) {
                                        break;
                                    }
                                    if ($response_data['requestStatus'] == 'OK') {
                                        return TRUE;
                                    } elseif ($response_data['requestStatus'] == 'ERROR') {
                                        $this->getLogger()->debug(__METHOD__.': JOB reply failed, reason: '.$response_data['requestMessage']);
                                        break;
                                    }
                                } else {
                                    $this->getLogger()->debug(__METHOD__.': JOB reply (HTTP code: '.$this->_response['http_code'].') has errors or is not JSON');
                                    break;
                                }
                            } else {
                                break;
                            }
                            // Increases the interval between calls
                            if ($data['minPollInterval'] + 10 < $data['maxPollInterval']) {
                                $data['minPollInterval'] += 10;
                            }
                        }
                    }
                } else {
                    $this->checkReply();
                }
            }
        }
        return FALSE;
    }

    /**
     * Get all defined cluster meta.
     * 
     * On success return an associative array of key => value meta.
     * 
     * Returns FALSE on failure
     * 
     * @return array|boolean
     * @throws Skylable_AccessSxException
     */
    public function getClusterMeta() {
        $date = $this->getRequestDate();
        
        if ($this->RESTCall(
            array(
                'url' => $this->getBaseURL($this->_cluster).'/?clusterMeta',
                'date' => $date,
                'authorization' => $this->getRequestSignature($this->_secret_key, 'GET', '?clusterMeta', $date, sha1(''))
            )
        )) {

            if ($this->parseHeaders()) {
                if ($this->_response['http_code'] == 200 && $this->isJSON()) {
                    $data = json_decode($this->_body, TRUE);
                    $this->replyIsError($data);
                    if (!is_null($data)) {
                        if (array_key_exists('clusterMeta', $data)) {
                            
                            foreach($data['clusterMeta'] as $k => $v) {
                                $data['clusterMeta'][$k] = hex2bin($v);
                            }
                            
                            return $data['clusterMeta'];    
                        } else {
                            $this->getLogger()->debug(__METHOD__.' clusterMeta not found.');
                        }
                    }
                } else {
                    $this->checkReply();
                }
            }
        }
        return FALSE;
    }

    /**
     * Tells if the parsed JSON reply is of type JOB_PUT or JOB_DELETE.
     * @param array $reply
     * @return bool
     */
    protected function isJobReply($reply) {
        if (is_array($reply)) {
            return (array_key_exists('requestId', $reply) && array_key_exists('minPollInterval', $reply) && array_key_exists('maxPollInterval', $reply) );
        }
        return FALSE;
    }

    /**
     * Tells if the parsed JSON reply is of a pending JOB request.
     * 
     * @param array $reply
     * @return bool
     */
    protected function isJobRequestReply($reply) {
        if (is_array($reply)) {
            return (array_key_exists('requestId', $reply) && array_key_exists('requestStatus', $reply) && array_key_exists('requestMessage', $reply) );
        }
        return FALSE;
    }

    /**
     * Return the user list of a cluster.
     * 
     * Returns an associative array in the form:
     * array(
     * 'username' => array(
     *  'admin' => bool (TRUE if the user is admin, FALSE otherwise)
     *  ... other key depending on the server reply    
     * )
     * )
     * 
     * See:  http://docs.skylable.com/docs/list-users
     * 
     * @param bool|FALSE $with_quota
     * @return bool|mixed
     * @throws Skylable_AccessSxException
     */
    public function getUserList($with_quota = FALSE) {
        $date = $this->getRequestDate();
        $req = '.users?desc';
        if ($with_quota) {
            $req .= '&quota';
        }

        if ($this->RESTCall(
            array(
                'url' => $this->getBaseURL($this->_cluster).'/' . $req,
                'date' => $date,
                'authorization' => $this->getRequestSignature($this->_secret_key, 'GET', $req, $date, sha1(''))
            )
        )) {

            if ($this->parseHeaders()) {
                if ($this->_response['http_code'] == 200 && $this->isJSON()) {
                    $data = json_decode($this->_body, TRUE);
                    $this->replyIsError($data);
                    if (!is_null($data)) {
                        return $data;
                    }
                } else {
                    $this->checkReply();
                }
            }
        }
        return FALSE;
    }

    /**
     * Tells if an user exists on the SX cluster.
     * 
     * @param string $username the user name
     * @param boolean $role TRUE if the user is an admin, FALSE otherwise
     * @param boolean $ignore_case TRUE ignore case in comparisons, FALSE be case sensitive
     * @return bool
     * @throws Skylable_AccessSxException
     */
    public function userExists($username, &$role, $ignore_case = FALSE) {
        if (!is_string($username) || empty($username)) {
            throw new Skylable_AccessSxException('Invalid user name');
        }
        $role = FALSE;
        $date = $this->getRequestDate();
        if ($this->RESTCall(
            array(
                'url' => $this->getBaseURL($this->_cluster).'/.users',
                'date' => $date,
                'authorization' => $this->getRequestSignature($this->_secret_key, 'GET', '.users', $date, sha1(''))
            )
        )) {

            if ($this->parseHeaders()) {
                if ($this->_response['http_code'] == 200 && $this->isJSON()) {
                    $data = json_decode($this->_body, TRUE);
                    $this->replyIsError($data);
                    if (!is_null($data)) {
                        foreach($data as $k => $v) {
                            $exists = ($ignore_case ? strcasecmp($k, $username) : strcmp($k, $username) );

                            if ($exists == 0) {
                                $role = $v['admin'];
                                return TRUE;
                            }
                        }
                    }
                } else {
                    $this->checkReply();
                }
            }
        }
        return FALSE;
    }

    /**
     * Rerturns the user details.
     * 
     * Implements: http://docs.skylable.com/docs/get-user-details.
     * 
     * Returns an associative array in the form:
     * array(
     * 'name' - string - the user name
     * 'admin' - boolean - TRUE if the user is an admin
     * 'userQuota' - integer - the user quota
     * 'userQuotaUsed' - integer - the user quota already used
     * 'userDesc' - string - the user description
     * )
     * 
     * @return bool TRUE on success, FALSE on failure
     * @throws Skylable_AccessSxException
     */
    public function getUserDetails() {
        $date = $this->getRequestDate();
        if ($this->RESTCall(
            array(
                'url' => $this->getBaseURL($this->_cluster).'/.self',
                'date' => $date,
                'authorization' => $this->getRequestSignature($this->_secret_key, 'GET', '.self', $date, sha1(''))
            )
        )) {

            if ($this->parseHeaders()) {
                $this->getLogger()->debug(__METHOD__.print_r($this->_response, TRUE));
                if ($this->_response['http_code'] == 200 && $this->isJSON()) {
                    $data = json_decode($this->_body, TRUE);
                    $this->replyIsError($data);
                    if (!is_null($data)) {
                        reset($data);
                        list($username, $user_data) = each($data);
                        $user_data['name'] = $username;
                        return $user_data;
                    }
                } else {
                    $this->checkReply();
                }
            }
        }
        return FALSE;
    }

    /**
     * Check is the reply contains fatal errors and throws exceptions.
     * 
     * @return bool TRUE if the error is fatal, FALSE otherwise
     * @see replyIsError
     */
    protected function checkReply($throws_exception = TRUE) {
        if (in_array($this->_response['http_code'], array(404, 408, 429))) {
            if ($throws_exception) {
                if ($this->isJSON()) {
                    $data = json_decode($this->_body, TRUE);
                    $this->replyIsError($data);
                }
            }
            return FALSE;
        } elseif (substr(strval($this->_response['http_code']),0,1) == '4') {
            if ($throws_exception) {
                if ($this->isJSON()) {
                    $data = json_decode($this->_body, TRUE);
                    $this->replyIsError($data);    
                }
            }
            return TRUE;
        } 
        return FALSE;
    }
}
