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
$pfSenseClient = new \KubernetesPfSenseController\XmlRpc\Client(getenv('PFSENSE_URL').'/xmlrpc.php');
$pfSenseClient->getHttpClient()->setAuth($pfSenseUsername, getenv('PFSENSE_PASSWORD'));
$httpOptions = [];
if ($pfSenseInsecure) {
    $httpOptions = array_merge($httpOptions, ['sslverifypeer' => false, 'sslallowselfsigned' => true, 'sslverifypeername' => false]);
}

if (getenv('PFSENSE_SSLCAPATH')) {
    $httpOptions = array_merge($httpOptions, ['sslcapath' => getenv('PFSENSE_SSLCAPATH')]);
}

if (getenv('PFSENSE_SSLCAFILE')) {
    $httpOptions = array_merge($httpOptions, ['sslcafile' => getenv('PFSENSE_SSLCAFILE')]);
}

if (getenv('PFSENSE_HTTPKEEPALIVE')) {
    $httpOptions = array_merge($httpOptions, ['keepalive' => true]);
}

// https://docs.laminas.dev/laminas-http/client/intro/#configuration
// https://docs.laminas.dev/laminas-http/client/adapters/
if (count($httpOptions) > 0) {
    echo 'setting http client options: ' . json_encode($httpOptions)."\n";
    $pfSenseClient->getHttpClient()->setOptions($httpOptions);
}

// setup controller
if (getenv('CONTROLLER_NAME')) {
    $controllerName = getenv('CONTROLLER_NAME');
} else {
    $controllerName = 'kubernetes-pfsense-controller';
}

if (getenv('CONTROLLER_NAMESPACE')) {
    $controllerNamespace = getenv('CONTROLLER_NAMESPACE');
} else {
    $controllerNamespace = 'kube-system';
}


$options = [
    'configMapNamespace' => $controllerNamespace,
    //'configMapName' => $controllerName.'-controller-config',
    //'storeEnabled' => true,
    'storeNamespace' => $controllerNamespace,
    //'storeName' => $controllerName.'-controller-store',
];

// expose the above

$controller = new KubernetesPfSenseController\Controller($controllerName, $kubernetesClient, $options);
$kubernetesClient = $controller->getKubernetesClient();

// register pfSenseClient
$controller->setRegistryItem('pfSenseClient', $pfSenseClient);

// register kubernetes version info
$kubernetesVersionInfo = $kubernetesClient->request("/version");
$controller->setRegistryItem('kubernetesVersionInfo', $kubernetesVersionInfo);

// plugins
$controller->registerPlugin('\KubernetesPfSenseController\Plugin\MetalLB');
$controller->registerPlugin('\KubernetesPfSenseController\Plugin\HAProxyDeclarative');
$controller->registerPlugin('\KubernetesPfSenseController\Plugin\HAProxyIngressProxy');
$controller->registerPlugin('\KubernetesPfSenseController\Plugin\DNSHAProxyIngressProxy');
$controller->registerPlugin('\KubernetesPfSenseController\Plugin\DNSServices');
$controller->registerPlugin('\KubernetesPfSenseController\Plugin\DNSIngresses');

// start
$controller->main();
