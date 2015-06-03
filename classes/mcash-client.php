<?php


if(!class_exists('Mcash_Client')) {
    class Mcash_Client
    {

        const MCASH_PUB_KEY_PROD = '-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAyGr/0kllDmLNq8KblWJt
Ths43xqlj0q++xWdHjZKL/6Ko1/NouQsWCVhtoRvAwKWc8TKhVDfRn3an7zBnnyD
/9BXiHoN2OFfogwlY/EAHX4MbKR/0Ankqo5OPG875IpqrZJvWZ/1/NG5epuJAWYG
dxrlaS0QqueX8sl77bAA5U7CYEvUswiFQ3Fegm2xJzVYgTh81ScfPw8G+JyugxCR
C/guFdebyYqSGLRoC/A7oUrEyqUr04PSx8J1Axbp46ml0l6M9cS5e1YRyYREAB14
hxeVSYbgALaCSD+44YeN5XWgzqezocGdilNumPaQW1iVeRAgdTginTgk4rHohynp
AwIDAQAB
-----END PUBLIC KEY-----';
        
        public function __construct($log,
                                    $mcash_server,
                                    $mid,
                                    $uid,
                                    $priv_key,
                                    $sslverify,
                                    $testmode,
                                    $testbed_token
        ) {
            $this->log          = $log;
            $this->mcash_server = $mcash_server;
            $this->mid          = $mid;
            $this->uid          = $uid;
            $this->priv_key     = $priv_key;
            $this->sslverify    = $sslverify;     
            $this->testmode     = $testmode;     
            $this->testbed_token   = $testbed_token;
            $this->mcash_public_key = null;
        }
        
        public function log( $message ) 
        {
            if (!empty( $this->log ) ) {
                $this->log->add('mcash_client', $message);
            }
        }

        function get_mcash_public_key() 
        {
            if(is_null($this->mcash_public_key)) {
                return self::MCASH_PUB_KEY_PROD;
            }
            return $this->mcash_public_key;
        }
        
        function payment_request($success_return_uri,
                                 $failure_return_uri,
                                 $amount,
                                 $currency,
                                 $text,
                                 $pos_tid,
                                 $pos_id,
                                 $callback_uri,
                                 $required_scope=null
        ) {
            $url = $this->mcash_server . "/merchant/v1/payment_request/";
            $payload = array(
                'success_return_uri' => $success_return_uri,
                'failure_return_uri' => $failure_return_uri,
                'amount'             => $amount,
                'currency'           => $currency,
                'text'               => $text,
                'pos_id'             => $pos_id,
                'pos_tid'            => $pos_tid,
                'allow_credit'       => true,
                'action'             => 'sale',
                'callback_uri'       => $callback_uri
            );

            if(!is_null($required_scope)) {
                $payload['required_scope'] = $required_scope;
            }
            
            $result = $this->mcash_merchant_call(
                'POST', $url, $payload, $this->mid,
                $this->uid, $this->priv_key, $this->sslverify
            );
            if (floor($result['status'] / 100) != 2 ) {
                return false;
            }
            return $result['data'];
        }
        
        function payment_outcome($mcash_tid) 
        {
            $payload = '';
            $url =  $this->mcash_server . "/merchant/v1/payment_request/" . $mcash_tid . "/outcome/";
            $result = $this->mcash_merchant_call('GET', $url, $payload, $this->mid, $this->uid, $this->priv_key, $this->sslverify);
            return $result;
        }

        function capture_payment($mcash_tid) 
        {
            $url =  $this->mcash_server . "/merchant/v1/payment_request/" . $mcash_tid . "/";
            $payload = array(
                'action' => 'capture'
            );
            $result = $this->mcash_merchant_call(
                'PUT', $url, $payload, $this->mid,
                $this->uid, $this->priv_key, $this->sslverify
            );
            if($result['status'] == 204 ) {
                return true;
            }
            return false;
        }
    
        function refund_payment($mcash_tid, $mcash_refund_id, $amount, $text)
        {
            $url = $this->mcash_server . "/merchant/v1/payment_request/" . $mcash_tid . "/";
            $method = 'PUT';
            $payload = array(
                'action'            => 'refund',
                'refund_id'         => strval($mcash_refund_id),
                'currency'          => get_woocommerce_currency(),
                'amount'            => $amount,
                'additional_amount' => '0.00',
                'text'              => $text
            );
            return $this->mcash_merchant_call(
                $method, $url, $payload, $this->mid,
                $this->uid, $this->priv_key, $this->sslverify
            );
        }
    
        function mcash_merchant_call($method, $url, $payload, $mid, $uid, $priv_key, $sslverify)
        {
            $json_payload = ( $payload =='' ) ? '' : json_encode($payload);
            $headers = array(
                'Accept'                 => 'application/vnd.mcash.api.merchant.v1+json',
                'Content-Type'           => 'application/json',
                'X-Mcash-Merchant'       => $mid,
                'X-Mcash-User'           => $uid,
                'X-Mcash-Timestamp'      => $this->utcTimestamp(),
                'X-Mcash-Content-Digest' => $this->contentDigest($json_payload)
            );

            if (($this->testmode) && !empty($this->testbed_token) ) {
                $headers['X-Testbed-Token'] = $this->testbed_token;
            }
        
            $signature = $this->sign($method, $url, $headers, $priv_key);
            $headers['Authorization'] = "RSA-SHA256 " . $signature;

            $this->log('mcash_merchant_call()  $url = ' . print_r($url, true));
            $this->log('mcash_merchant_call()  $headers = ' . print_r($headers, true));
            $this->log('mcash_merchant_call()  $payload = ' . print_r($payload, true));
            $this->log('mcash_merchant_call()  $json_payload = ' . print_r($json_payload, true));
            $this->log('mcash_merchant_call()  $json_payload = ' . $json_payload);
        
            $response = wp_remote_request(
                $url,
                array(
                                               'method'    => $method,
                                               'body'      => $json_payload,
                                               'headers'   => $headers,
                                               'timeout'   => 90,
                                               'sslverify' => $sslverify)
            );
                                     
            $body = wp_remote_retrieve_body($response);
            if ($body == '' ) {
                $body = json_encode(array()); 
            }

            $result = array(
                'status' => wp_remote_retrieve_response_code($response),
                'data' => json_decode($body)
            );
        
            $this->log('mcash_merchant_call()  $result = ' . print_r($result, true));
            return $result;
        }

        // Sign data using RSA private key with PKCS1 v1.5 padding and SHA256 hash
        public function sign_pkcs1($key, $data) 
        {
            if (!openssl_sign($data, $signature, $key, "sha256")) {
                $this->log('sign_pkcs1() Failed to sign data');
            }
            return base64_encode($signature);
        }
    
        public function verify_signature_pkcs1($key, $data, $signature) 
        {
            return openssl_verify($data, base64_decode($signature), $key, "sha256");
        }
    
        // The base64 encoded hash digest of the request body. If the body is
        // empty, the hash should be computed on an empty string. The value of the
        // header should be on the form <algorithm (uppercase)>=<digest value>.
        private function contentDigest($data="") 
        {
            return "SHA256=" . base64_encode(hash("sha256", $data, true));
        }
        
        // The current UTC time. The time format is YYYY-MM-DD hh:mm:ss.
        private function utcTimestamp() 
        {
            return gmdate("Y-m-d H:i:s", time());
        }
    
        // The string that is to be signed (the signature message) is
        // constructed from the request in the following manner:
        //
        // <method>|<url>|<headers>
        // Here, method is the HTTP method used in the request, url is the
        // full url including protocol and query component (the part after ?)
        // but without fragment component (The part after #). The scheme name
        // (typically https) and hostname components are always lowercase, while
        // the rest of the url is case sensitive. The headers part is a
        // querystring using header names and values as key-value pairs. So, the
        // constructed string will be of the form:
        //
        // name1=value1&name2=value2...
        // In addition the following requirements apply:
        //
        // Headers are sorted alphabetically.
        // All header names must be made uppercase before constructing the string.
        // Headers whose names don't start with "X-MCASH-" are not included.
        public function buildSignatureMessage($requestMethod, $url, $headers) 
        {
            // Find headers that start with X-MCASH
            $mcashHeaders = array();
            foreach ($headers as $key => $value) {
                $ucKey = strtoupper($key);
                if (substr($ucKey, 0, 7) === "X-MCASH") {
                    $mcashHeaders[$ucKey] = $value;
                }
            }

            // Sort headers by key
            ksort($mcashHeaders);

            // Create key value pairs 'key=value'
            $headerPairs = array();
            foreach ($mcashHeaders as $key => $value) {
                $headerPairs[] = sprintf("%s=%s", $key, $value);
            }

            // Join header pairs
            $headerString = implode("&", $headerPairs);

            return sprintf(
                "%s|%s|%s", strtoupper($requestMethod), $url, $headerString
            );
        }
    
        private function sign($requestMethod, $url, $headers, $priv_key) 
        {
            $message = $this->buildSignatureMessage($requestMethod, $url, $headers);
            return $this->sign_pkcs1($priv_key, $message);
        }

        function startsWith($haystack, $needle) 
        {
            $length = strlen($needle);
            return (substr($haystack, 0, $length) === $needle);
        }
        
        public function valid_signature($method, $uri, $headers, $json_payload) 
        {
            $this->log('valid_signature() $method = ' . $method);
            $this->log('valid_signature() $uri = ' . $uri);
            $this->log('valid_signature() $headers = ' . print_r($headers, true));
            $this->log('valid_signature() $json_payload = ' . $json_payload);
            $key = $this->get_mcash_public_key();
            $data = $this->buildSignatureMessage($method, $uri, $headers);
            $authorization = $headers['Authorization'];
            list($unused, $signature) = explode(" ", $authorization);
            $valid = $this->verify_signature_pkcs1($key, $data, $signature);
            
            $calc_payload_digest = $this->contentDigest($json_payload);
            $this->log('valid_signature() $valid = ' . print_r($valid, true));
            $this->log('valid_signature() $data = ' . $data);
            $this->log('valid_signature() $calc_payload_digest = ' . $calc_payload_digest);
            $this->log('valid_signature() X-Mcash-Content-Digest = ' .  $headers['X-Mcash-Content-Digest']);

            if ($this->testmode ) {
                $this->log('valid_signature() testmode return true');
                return true;
            }
            
            if ($valid && ( $headers['X-Mcash-Content-Digest']===$calc_payload_digest ) ) {
                $this->log('valid_signature() return true');
                return true;
            }
            $this->log('valid_signature() return false');
            return false;
        }
        
    
        public function getallheaders()
        { 
            foreach($_SERVER as $K=>$V){$a=explode('_' ,$K); 
                if(array_shift($a)=='HTTP'){ 
                    array_walk($a,function(&$v){$v=ucfirst(strtolower($v));});
                    $retval[join('-',$a)]=$V;
                }
            }
            if(isset($_SERVER['CONTENT_TYPE'])) $retval['Content-Type'] = $_SERVER['CONTENT_TYPE'];
            if(isset($_SERVER['CONTENT_LENGTH'])) $retval['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
            return $retval;
        }
        
        public function request_headers()
        {
            if( function_exists('apache_request_headers') ) {
                $this->log('mCASH request_headers() apache_request_headers() is defined.');
                return apache_request_headers();
            } else {
                $this->log('mCASH  request_headers() apache_request_headers() is not defined. Using fallback');
                return $this->getallheaders();
            }
        }
        
    }   
}
