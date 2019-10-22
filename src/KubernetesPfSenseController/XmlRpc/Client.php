<?php

namespace KubernetesPfSenseController\XmlRpc;

/**
 * XmlRpc Client for communication with pfSense
 * Class Client
 * @package KubernetesPfSenseController\XmlRpc
 */
class Client extends \Zend\XmlRpc\Client
{
    public function call($method, $params = [])
    {
        $value = parent::call($method, $params);

        if (getenv('PFSENSE_DEBUG')) {
            $req = $this->getHttpClient()->getRequest();
            $res = $this->getHttpClient()->getResponse();

            $resContent = 'HTTP/'.$res->getVersion().' '.$res->getStatusCode().' '.$res->getReasonPhrase();
            $resContent .= "\n";
            $resContent .= $res->getHeaders()->toString();
            $resContent .= "\n";
            $resContent .= $res->getBody();

            $this->log('###################### START XMLRPC LOG ##########################');
            echo $req.PHP_EOL.PHP_EOL.$resContent;
            echo "\n";
            $this->log('######################## END XMLRPC LOG ##########################');
        }

        return $value;
    }

    public function log($message)
    {
        echo date("c").' '.$message."\n";
    }
}
