<?php
/*
  Description: Extends WooCommerce by Adding mCASH Gateway.
  Author: mCASH AS
  License: The MIT License (MIT)
*/

require  'mcash-client.php' ;


class mcash_client_test extends PHPUnit_Framework_TestCase
{
        const PUB_KEY = '-----BEGIN PUBLIC KEY-----
MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQCfUDv8nt/RVvTdQ3sbgvz/j1L1
Bc9ltic4RxnLd/EwyW/Kc/Jj0Mq4reFvfOuXJHEjMk9YqT2iByXzDBxS2jfAvA4f
LZqnVzOsVyNp3rKmHtQbeGhH5omSqHsmR3epAS/365M0B1aima/7POhkzKJYOQfb
6mOjJ/z2bxUKubdcYQIDAQAB
-----END PUBLIC KEY-----';

        const PRIV_KEY = '-----BEGIN RSA PRIVATE KEY-----
MIICXAIBAAKBgQCfUDv8nt/RVvTdQ3sbgvz/j1L1Bc9ltic4RxnLd/EwyW/Kc/Jj
0Mq4reFvfOuXJHEjMk9YqT2iByXzDBxS2jfAvA4fLZqnVzOsVyNp3rKmHtQbeGhH
5omSqHsmR3epAS/365M0B1aima/7POhkzKJYOQfb6mOjJ/z2bxUKubdcYQIDAQAB
AoGBAJqL4yV1mfoiOPhMdiiCMZxZFUjMkh1BT1qw3r0bZcbGIsRrJkDeU0pEo+Tb
ck/08iwKqh6AT2HXPWFB5lgZiOrOQt2Yk+0pU9c6eMQZeE0QislgllfmGP+x1Blg
HzP9uy2a2WJA8fCu5lMWwt8C982ygCSnoUMpXw5rTSuggfCBAkEAuWsmhxFS6VB3
B5tCWOP6lBN37lEAVscfs13CzGoGSvO3TgocEaSlrAka+5484alfswEaUlWjJlr4
pgROp3K6VQJBANv1KhWR6F1C6Xyx8OE2FL9jysMDXqQEqBDmUq40saOWTsPXYkRH
c6Gh17rvjG6yra64eyTmbkMlW8ZQEg7tfd0CQEYdHI6KoH2Vbc00ipwuaTzBN+Ko
QqaN2ZDr7ZN6rDJ/gltCO2b4iaVKNCfdqEv0zjlUO23S8ES6tbehfVSYb5kCQBmS
N/FIBC6Lb9+KREm6YtEZReJECwWgcPV+AVC1WY1+FOwZpxfvApdg3FakMLxR03VD
hzV0AI+X0UKN3nuTypUCQERG3mw182/GVbG0GVp5GTJxdyy1E5VdI8mvvF5SNbwn
Dzsubiwvn5QzYKx3I8T9rJ2IzAGGFsfNFVcvFCXoOtI=
-----END RSA PRIVATE KEY-----';

    /**
     * Call protected/private method of a class.
     *
     * @param object &$object    Instantiated object that we will run method on.
     * @param string $methodName Method name to call
     * @param array  $parameters Array of parameters to pass into method.
     *
     * @return mixed Method return.
     */
    public function invokeMethod(&$object, $methodName, array $parameters = array())
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        
        return $method->invokeArgs($object, $parameters);
    }
    
    
    function test_mcash_client_sign_empty_payload() 
    {

        $client = new mcash_client(
            null,
            "https://api.mca.sh",
            'mid',
            'uid',
            null,
            false,
            false,
            ''
        );
        
        $client->mcash_public_key=self::PUB_KEY;
        $this->assertTrue($client->get_mcash_public_key()===self::PUB_KEY);

        $method = 'POST';
        $payload = '';
        $url = 'https://something.com';
        $json_payload = ( $payload =='' ) ? '' : json_encode($payload);

        $headers = array(
            'Accept'                 => 'application/vnd.mcash.api.merchant.v1+json',
            'Content-Type'           => 'application/json',
            'X-Mcash-Merchant'       => 'mid',
            'X-Mcash-User'           => 'uid',
            'X-Mcash-Timestamp'      => $this->invokeMethod($client, 'utcTimestamp'),
            'X-Mcash-Content-Digest' => $this->invokeMethod(
                $client, 'contentDigest',
                array($json_payload)
            )
        );

        $signature = $this->invokeMethod(
            $client, 'sign', array($method, $url, $headers, self::PRIV_KEY)
        );
        $headers['Authorization'] = "RSA-SHA256 " . $signature;
        $return = $client->valid_signature($method, $url, $headers, $json_payload);
        $this->assertTrue($return);
    }

    function test_mcash_client_sign() 
    {

        $client = new mcash_client(
            null,
            "https://api.mca.sh",
            'mid',
            'uid',
            null,
            false,
            false,
            ''
        );
        
        $client->mcash_public_key=self::PUB_KEY;
        $this->assertTrue($client->get_mcash_public_key()===self::PUB_KEY);

        $method = 'POST';
        $payload = array(
            'meta' => array(
                'id'     => 'whatever',
                'uri'    => 'whatnot'
            )
        );
        $url = 'https://something.com';
        $json_payload = ( $payload =='' ) ? '' : json_encode($payload);

        $headers = array(
            'Accept'                 => 'application/vnd.mcash.api.merchant.v1+json',
            'Content-Type'           => 'application/json',
            'X-Mcash-Merchant'       => 'mid',
            'X-Mcash-User'           => 'uid',
            'X-Mcash-Timestamp'      => $this->invokeMethod($client, 'utcTimestamp'),
            'X-Mcash-Content-Digest' => $this->invokeMethod(
                $client, 'contentDigest',
                array($json_payload)
            )
        );

        $signature = $this->invokeMethod(
            $client, 'sign', array($method, $url, $headers, self::PRIV_KEY)
        );
        $headers['Authorization'] = "RSA-SHA256 " . $signature;
        $return = $client->valid_signature($method, $url, $headers, $json_payload);
        $this->assertTrue($return);
    }

}
