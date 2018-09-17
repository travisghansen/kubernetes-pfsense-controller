<?php

// prevent double logging
ini_set('log_errors', 1);
ini_set('display_errors', 0);

// include autoloader
require_once 'vendor/autoload.php';

// environment variable processing
if (file_exists(__DIR__.DIRECTORY_SEPARATOR.'.env')) {
    $dotenv = new Dotenv\Dotenv(__DIR__);
} else {
    $file = tempnam(sys_get_temp_dir(), 'pfsense-controller');
    register_shutdown_function(function () use ($file) {
        if (file_exists($file)) {
            unlink($file);
        }
    });

    $dotenv = new Dotenv\Dotenv(dirname($file), basename($file));
}
$dotenv->load();
$dotenv->required(['PFSENSE_URL', 'PFSENSE_PASSWORD'])->notEmpty();

$pfSenseUsername = (getenv('PFSENSE_USERNAME')) ? getenv('PFSENSE_USERNAME') : 'admin';
$pfSenseInsecure = (strtolower(getenv('PFSENSE_INSECURE')) == 'true') ? true : false;

// kubernetes client
if (getenv('KUBERNETES_SERVICE_HOST')) {
    $config = KubernetesClient\Config::InClusterConfig();
} else {
    $config = KubernetesClient\Config::BuildConfigFromFile();
}
$kubernetesClient = new KubernetesClient\Client($config);

// pfSense client
$pfSenseClient = new Zend\XmlRpc\Client(getenv('PFSENSE_URL').'/xmlrpc.php');
$pfSenseClient->getHttpClient()->setAuth($pfSenseUsername, getenv('PFSENSE_PASSWORD'));
if ($pfSenseInsecure) {
    $pfSenseClient->getHttpClient()->setOptions(['sslverifypeer' => false, 'sslallowselfsigned' => true, 'sslverifypeername' => false]);
}

// setup controller
$controllerName = 'pfsense-controller';
$options = [
    //'configMapNamespace' => 'kube-system',
    //'configMapName' => $controllerName.'-controller-config',
    //'storeEnabled' => true,
    //'storeNamespace' => 'kube-system',
    //'storeName' => $controllerName.'-controller-store',
];

$controller = new KubernetesController\Controller($controllerName, $kubernetesClient, $options);

// registry
$controller->setRegistryItem('pfSenseClient', $pfSenseClient);

// plugins
$controller->registerPlugin('\KubernetesPfSenseController\Plugin\MetalLB');
$controller->registerPlugin('\KubernetesPfSenseController\Plugin\HAProxyDeclarative');
$controller->registerPlugin('\KubernetesPfSenseController\Plugin\HAProxyIngressProxy');
$controller->registerPlugin('\KubernetesPfSenseController\Plugin\DNSHAProxyIngressProxy');
$controller->registerPlugin('\KubernetesPfSenseController\Plugin\DNSServices');
$controller->registerPlugin('\KubernetesPfSenseController\Plugin\DNSIngresses');

// start
$controller->main();
