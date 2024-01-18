<?php

/*
----------------------------------
 ------  Created: 010624   ------
 ------  Austin Best	   ------
----------------------------------
*/

/*
    USAGE:
        $api            = dockerContainerCreateAPI($inspect);
        $apiResponse    = dockerCurlAPI($api, 'post');
*/

function dockerCommunicateAPI()
{
    if ($_SERVER['DOCKER_HOST']) { //-- IF A DOCKER HOST IS PROVIDED, DONT EVEN TRY TO USE THE SOCK
        $curl = curl('http://' . $_SERVER['DOCKER_HOST']);

        if ($curl['code'] == 403) {
            return true;
        }

        return false;
    }

    if (file_exists('/var/run/docker.sock')) {
        return true;
    }

    return false;
}

function dockerVersionAPI()
{
    $cmd = '/usr/bin/docker version | grep "API"';
    $shell = shell_exec($cmd . ' 2>&1');
    preg_match_all("/([0-9]\.[0-9]{1,3})/", $shell, $matches);

    return $matches[0][1];
}

function dockerCurlAPI($create, $method)
{
    $payload    = $create['payload'];
    $endpoint   = $create['endpoint'];
    $container  = $create['container'];

    //-- WRITE THE PAYLOAD TO A FILE
    $filename = $container . '_' . time() . '.json';
    file_put_contents(TMP_PATH . $filename, json_encode($payload, JSON_UNESCAPED_SLASHES));

    //-- BUILD THE CURL CALL
    $headers    = '-H "Content-Type: application/json"';
    $cmd        = 'curl --silent ' . (strtolower($method) == 'post' ? '-X POST' : '');

    if ($_SERVER['DOCKER_HOST']) {
        $cmd        .= ' http://' . $_SERVER['DOCKER_HOST'] . $endpoint;
    } else {
        $version    = dockerVersionAPI();
        $cmd        .= ' --unix-socket /var/run/docker.sock http://v'. $version . $endpoint;
    }

    if ($headers) {
        $cmd .= ' ' . $headers . ' ';
    }
    if ($payload) {
        $cmd .= ' -d @' . TMP_PATH . $filename;
    }
    $shell = shell_exec($cmd . ' 2>&1');

    if (!$shell) {
        $shell = ['result' => 'failed', 'cmd' => $cmd, 'shell' => $shell];
    } else {
        $shell = json_decode($shell, true);
        if ($shell['message']) {
            $shell['cmd'] = $cmd;
        }
    }

    return $shell;
}

function dockerEscapePayloadAPI($payload)
{
    $in     = ["\\", '"', '$'];
    $out    = ["\\\\", '\"', '\$'];

    return str_replace($in, $out, $payload);
}

//-- https://docs.docker.com/engine/api/v1.41/#tag/Container/operation/ContainerStop
function dockerContainerStopAPI($name)
{
    /*
        Only added as a simple way to test it.
        dockerContainerStopAPI('container');
        $apiResponse if it works: 
            Array
            (
                [message] => No such container: container
            )
    */

    $endpoint = '/containers/' . $name . '/stop';

    return ['endpoint' => $endpoint, 'payload' => ''];
}

//-- https://docs.docker.com/engine/api/v1.42/#tag/Container/operation/ContainerCreate
function dockerContainerCreateAPI($inspect = [])
{
    $inspect = $inspect[0] ? $inspect[0] : $inspect;

    $containerName = '';
    if ($inspect['Name']) {
        $containerName = str_replace('/', '', $inspect['Name']);
    }

    if (!$containerName && !empty($inspect['Config']['Env'])) {
        foreach ($inspect['Config']['Env'] as $env) {
            if (str_contains($env, 'HOST_CONTAINERNAME')) {
                list($key, $containerName) = explode('=', $env);
                break;
            }
        }
    }

    $payload                        = [
                                        'Hostname'      => $inspect['Config']['Hostname'] ? $inspect['Config']['Hostname'] : $containerName,
                                        'Domainname'    => $inspect['Config']['Domainname'],
                                        'User'          => $inspect['Config']['User'],
                                        'Tty'           => $inspect['Config']['Tty'],
                                        'Entrypoint'    => $inspect['Config']['Entrypoint'],
                                        'Image'         => $inspect['Config']['Image'],
                                        'StopTimeout'   => $inspect['Config']['StopTimeout'],
                                        'StopSignal'    => $inspect['Config']['StopSignal'],
                                        'WorkingDir'    => $inspect['Config']['WorkingDir']
                                    ];

    $payload['Env']                 = $inspect['Config']['Env'];
    $payload['Cmd']                 = $inspect['Config']['Cmd'];
    $payload['Healthcheck']         = $inspect['Config']['Healthcheck'];
    $payload['Labels']              = $inspect['Config']['Labels'];
    $payload['Volumes']             = $inspect['Config']['Volumes'];
    $payload['ExposedPorts']        = $inspect['Config']['ExposedPorts'];
    $payload['HostConfig']          = $inspect['HostConfig'];
    $payload['NetworkingConfig']    = [
                                        'EndpointsConfig' => $inspect['NetworkSettings']['Networks']
                                    ];

    //-- API VALIDATION STUFF
    if (empty($payload['HostConfig']['PortBindings'])) {
        $payload['HostConfig']['PortBindings'] = null;
    }

    if (empty($payload['NetworkingConfig']['EndpointsConfig'])) {
        $payload['NetworkingConfig']['EndpointsConfig'] = null;
    }

    if (empty($payload['ExposedPorts'])) {
        $payload['ExposedPorts'] = null;
    } else {
        foreach ($payload['ExposedPorts'] as $port => $value) {
            $payload['ExposedPorts'][$port] = null;
        }
    }

    if (empty($payload['Volumes'])) {
        $payload['Volumes'] = null;
    } else {
        foreach ($payload['Volumes'] as $volume => $value) {
            $payload['Volumes'][$volume] = null;
        }
    }

    if (empty($payload['HostConfig']['LogConfig']['Config'])) {
        $payload['HostConfig']['LogConfig']['Config'] = null;
    }

    if (str_contains($payload['HostConfig']['NetworkMode'], 'container:')) { //-- Remove conflicting payload values if it's a container network
        $payload['Hostname'] = null;
        $payload['ExposedPorts'] = null;
    }

    if (empty($payload['HostConfig']['Mounts'])) {
        $payload['HostConfig']['Mounts'] = null;
    } else {
        foreach ($payload['HostConfig']['Mounts'] as $index => $mount) {
            if (empty($payload['HostConfig']['Mounts'][$index]['VolumeOptions'])) {
                $payload['HostConfig']['Mounts'][$index]['VolumeOptions'] = null;
            }
        }
    }

    if ($payload['NetworkSettings']['Networks']) {
        foreach ($payload['NetworkSettings']['Networks'] as $network => $networkSettings) {
            if ($networkSettings['Aliases']) {
                foreach ($networkSettings['Aliases'] as $index => $alias) {
                    //-- REMOVE ALIAS FROM PREVIOUS CONTAINER
                    if ($alias == substr($inspect['Id'], 0, 12)) {
                        unset($payload['NetworkSettings']['Networks'][$network]['Aliases'][$index]);
                    }
                }
            }
        }
    }

    $endpoint = '/containers/create?name=' . $containerName;

    return ['container' => $containerName, 'endpoint' => $endpoint, 'payload' => $payload];
}
