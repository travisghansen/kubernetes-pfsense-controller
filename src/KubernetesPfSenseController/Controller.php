<?php

namespace KubernetesPfSenseController;

class Controller extends \KubernetesController\Controller
{
    public function getKubernetesVersionInfo()
    {
        return $this->getRegistryItem('kubernetesVersionInfo');
    }

    public function getKubernetesVersionMajorMinor()
    {
        $versionInfo = $this->getKubernetesVersionInfo();

        return $versionInfo['major'].'.'.$versionInfo['minor'];
    }
}
