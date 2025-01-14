<?php

/*
----------------------------------
 ------  Created: 111523   ------
 ------  Austin Best	   ------
----------------------------------
*/

require 'shared.php';

if ($_POST['m'] == 'init') {
    $dependencyFile = $docker->setContainerDependencies($processList);
    $ports = $networks = $graphs = [];
    $running = $stopped = $memory = $cpu = $network = $size = $updated = $outdated = $healthy = $unhealthy = $unknownhealth = 0;

    foreach ($processList as $process) {
        $size += bytesFromString($process['size']);

        if (str_contains($process['Status'], 'healthy')) {
            $healthy++;
        } elseif (str_contains($process['Status'], 'unhealthy')) {
            $unhealthy++;
        } elseif (!str_contains($process['Status'], 'health')) {
            $unknownhealth++;
        }

        if ($process['State'] == 'running') {
            $running++;
        } else {
            $stopped++;
        }

        //-- GET UPDATES
        if ($pullsFile) {
            foreach ($pullsFile as $hash => $pull) {
                if (md5($process['Names']) == $hash) {
                    if ($pull['regctlDigest'] == $pull['imageDigest']) {
                        $updated++;
                    } else {
                        $outdated++;
                    }
                    break;
                }
            }
        }

        //-- GET USED NETWORKS
        if ($process['inspect'][0]['NetworkSettings']['Networks']) {
            $networkKeys = array_keys($process['inspect'][0]['NetworkSettings']['Networks']);
            foreach ($networkKeys as $networkKey) {
                $networks[$networkKey]++;
            }
        } else {
            $containerNetwork = $process['inspect'][0]['HostConfig']['NetworkMode'];
            if (str_contains($containerNetwork, ':')) {
                list($null, $containerId) = explode(':', $containerNetwork);
                $containerNetwork = 'container:' . $docker->findContainer(['id' => $containerId, 'data' => $processList]);
            }

            $networks[$containerNetwork]++;
        }

        //-- GET USED PORTS
        if ($process['inspect'][0]['HostConfig']['PortBindings']) {
            foreach ($process['inspect'][0]['HostConfig']['PortBindings'] as $internalBind => $portBinds) {
                foreach ($portBinds as $portBind) {
                    if ($portBind['HostPort']) {
                        $ports[$process['Names']][] = $portBind['HostPort'];
                    }
                }
            }
        }

        //-- GET MEMORY UAGE
        $memory += floatval(str_replace('%', '', $process['stats']['MemPerc']));

        //-- GET CPU USAGE
        $cpu += floatval(str_replace('%', '', $process['stats']['CPUPerc']));

        //-- GET NETWORK USAGE
        list($netUsed, $netAllowed) = explode(' / ', $process['stats']['NetIO']);
        $network += bytesFromString($netUsed);

        $graphs['utilization']['cpu']['total']['percent'] = 100;
        $graphs['utilization']['cpu']['containers'][$process['Names']] = str_replace('%', '', $process['stats']['CPUPerc']);

        list($memUsed, $memTotal) = explode('/', $process['stats']['MemUsage']);
        $graphs['utilization']['memory']['total']['size'] = trim($memTotal);
        $graphs['utilization']['memory']['total']['percent'] = 100;
        $graphs['utilization']['memory']['containers'][$process['Names']]['percent'] = str_replace('%', '', $process['stats']['MemPerc']);
        $graphs['utilization']['memory']['containers'][$process['Names']]['size'] = trim($memUsed);
    }

    if (intval($settingsTable['cpuAmount']) > 0) {
        $cpuActual = number_format(($cpu / intval($settingsTable['cpuAmount'])), 2);
    }

    ?>
    <div class="row mb-2">
        <div class="col-sm-6"><?= APP_NAME ?> at a glance</div>
        <div class="col-sm-6 d-flex justify-content-end">
            <div class="form-check form-switch">
                <label class="form-check-label" for="overviewDetailed">Detailed</label>
                <input class="form-check-input bg-primary" type="checkbox" role="switch" id="overviewDetailed" onchange="toggleOverviewView()" <?= $settingsTable['overviewLayout'] == UI::OVERVIEW_DETAILED ? 'checked' : '' ?>>
            </div>
        </div>
    </div>
    <?php
    if (!$settingsTable['overviewLayout'] || $settingsTable['overviewLayout'] == UI::OVERVIEW_SIMPLE) {
    ?>
    <div class="container-fluid">
        <div class="row">
            <div class="col-sm-12 col-lg-6 col-xl-4 mt-2" style="cursor:pointer;" onclick="initPage('containers')">
                <div class="row bg-secondary rounded p-4 me-2">
                    <div class="col-sm-12 col-lg-4">
                        <h3>Status</h3>
                    </div>
                    <div class="col-sm-12 col-lg-8 text-end">
                        Running: <?= $running ?><br>
                        Stopped: <?= $stopped ?><br>
                        Total: <?= $running + $stopped ?>
                    </div>
                </div>
            </div>
            <div class="col-sm-12 col-lg-6 col-xl-4 mt-2" style="cursor:pointer;" onclick="initPage('containers')">
                <div class="row bg-secondary rounded p-4 me-2">
                    <div class="col-sm-12 col-lg-4">
                        <h3>Health</h3>
                    </div>
                    <div class="col-sm-12 col-lg-8 text-end">
                        Healthy: <?= $healthy ?><br>
                        Unhealthy: <?= $unhealthy ?><br>
                        Unknown: <?= $unknownhealth ?>
                    </div>
                </div>
            </div>
            <div class="col-sm-12 col-lg-6 col-xl-4 mt-2" style="cursor:pointer;" onclick="openUpdateOptions()">
                <div class="row bg-secondary rounded p-4 me-2">
                    <div class="col-sm-12 col-lg-4">
                        <h3>Updates</h3>
                    </div>
                    <div class="col-sm-12 col-lg-8 text-end">
                        Up to date: <?= $updated ?><br>
                        Outdated: <?= $outdated ?><br>
                        Unchecked: <?= ($running + $stopped) - ($updated + $outdated) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-sm-12 col-lg-6 col-xl-4 mt-2">
                <div class="row bg-secondary rounded p-4 me-2">
                    <div class="col-sm-12 col-lg-4">
                        <h3>Usage</h3>
                    </div>
                    <div class="col-sm-12 col-lg-8 text-end">
                        Disk:  <?= byteConversion($size) ?><br>
                        CPU: <span title="Docker reported CPU"><?= $cpu ?>%</span><?= $cpuActual ? ' <span title="Calculated CPU">(' . $cpuActual . '%)</span>' : '' ?><br>
                        Memory: <?= $memory ?>%<br>
                        Network I/O: <?= byteConversion($network) ?>
                    </div>
                </div>
            </div>
            <div class="col-sm-12 col-lg-6 col-xl-4 mt-2" style="cursor:pointer;" onclick="initPage('networks')">
                <div class="row bg-secondary rounded p-4 me-2">
                    <div class="col-sm-12 col-lg-4">
                        <h3>Network</h3>
                    </div>
                    <div class="col-sm-12 col-lg-8 text-end">
                        <?php
                        $networkList = '';
                        foreach ($networks as $networkName => $networkCount) {
                            $networkList .= ($networkList ? '<br>' : '') . truncateMiddle($networkName, 30) . ': ' . $networkCount;
                        }
                        echo '<div style="max-height: 250px; overflow: auto;">' . $networkList . '</div>';
                        ?>
                    </div>
                </div>
            </div>
            <div class="col-sm-12 col-lg-6 col-xl-4 mt-2">
                <div class="row bg-secondary rounded p-4 me-2">
                    <div class="col-sm-12 col-lg-2">
                        <h3>Ports</h3>
                    </div>
                    <div class="col-sm-12 col-lg-10">
                        <?php
                        $portArray = [];
                        $portList = '';
                        if ($ports) {
                            foreach ($ports as $container => $containerPorts) {
                                foreach ($containerPorts as $containerPort) {
                                    $portArray[$containerPort] = $container;
                                }
                            }
                            ksort($portArray);
                            $portArray = formatPortRanges($portArray);
                            
                            if ($portArray) {
                                $portList = '<div style="max-height: 250px; overflow: auto;">';

                                foreach ($portArray as $port => $container) {
                                    $portList .= '<div class="row flex-nowrap p-0 m-0">';
                                    $portList .= '  <div class="col text-end">' . $port . '</div>';
                                    $portList .= '  <div class="col text-end" title="' . $container . '">' . truncateMiddle($container, 14) . '</div>';
                                    $portList .= '</div>';    
                                }

                                $portList .= '</div>';
                            }
                        }
                        echo $portList;
                    ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php } elseif ($settingsTable['overviewLayout'] == UI::OVERVIEW_DETAILED) { ?>
    <div class="row">
        <div class="col-sm-12">
            <div class="row bg-secondary rounded p-4">
                <div class="col-sm-12 col-lg-4" style="cursor:pointer;" onclick="initPage('containers')">
                    <div class="row">
                        <div class="col-sm-12 col-lg-3 text-center">
                            <span class="h4 text-primary">Status</span>
                        </div>
                        <div class="col-sm-12 col-lg-3 h5">
                            <span class="badge bg-success w-100">Running: <?= $running ?></span>
                        </div>
                        <div class="col-sm-12 col-lg-3 h5">
                            <span class="badge bg-warning w-100">Stopped: <?= $stopped ?></span>
                        </div>
                        <div class="col-sm-12 col-lg-3 h5">
                            <span class="badge bg-light w-100">Total: <?= $running + $stopped ?></span>
                        </div>
                    </div>
                </div>
                <div class="col-sm-12 col-lg-4" style="cursor:pointer;" onclick="initPage('containers')">
                    <div class="row">
                        <div class="col-sm-12 col-lg-3 text-center">
                            <span class="h4 text-primary">Health</span>
                        </div>
                        <div class="col-sm-12 col-lg-3 h5">
                            <span class="badge bg-success w-100">Healthy: <?= $healthy ?></span>
                        </div>
                        <div class="col-sm-12 col-lg-3 h5">
                            <span class="badge bg-warning w-100">Unhealthy: <?= $unhealthy ?></span>
                        </div>
                        <div class="col-sm-12 col-lg-3 h5">
                            <span class="badge bg-light w-100">Unknown: <?= $unknownhealth ?></span>
                        </div>
                    </div>
                </div>
                <div class="col-sm-12 col-lg-4" style="cursor:pointer;" onclick="openUpdateOptions()">
                    <div class="row">
                        <div class="col-sm-12 col-lg-3 text-center">
                            <span class="h4 text-primary">Updates</span>
                        </div>
                        <div class="col-sm-12 col-lg-3 h5">
                            <span class="badge bg-success w-100">Updated: <?= $updated ?></span>
                        </div>
                        <div class="col-sm-12 col-lg-3 h5">
                            <span class="badge bg-warning w-100">Outdated: <?= $outdated ?></span>
                        </div>
                        <div class="col-sm-12 col-lg-3 h5">
                            <span class="badge bg-light w-100">Unchecked: <?= ($running + $stopped) - ($updated + $outdated) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row mt-2">
        <div class="col-sm-12">
            <div class="row">
                <div class="col-sm-12 col-lg-4">
                    <div class="bg-secondary rounded p-2 mt-2">
                        <div class="row">
                            <div class="col-sm-6 text-primary">Disk usage</div>
                            <div class="col-sm-6"><?= byteConversion($size) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-12 col-lg-4">
                    <div class="bg-secondary rounded p-2 mt-2">
                        <div class="row">
                            <div class="col-sm-6 text-primary">Network I/O</div>
                            <div class="col-sm-6"><?= byteConversion($network) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-12 col-lg-4">
                    <div class="bg-secondary rounded p-2 mt-2">
                        <div class="row">
                            <div class="col-sm-6 col-lg-2 text-primary">CPU</div>
                            <div class="col-sm-6 col-lg-4 text-center"><span title="Docker reported CPU"><?= $cpu ?>%</span><?= $cpuActual ? ' <span title="Calculated CPU">(' . $cpuActual . '%)</span>' : '' ?></div>
                            <div class="col-sm-6 col-lg-2 text-primary">Memory</div>
                            <div class="col-sm-6 col-lg-4 text-center"><?= $memory ?>%</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-12 col-lg-6">
                    <div class="bg-secondary rounded p-2 mt-2" style="cursor:pointer;" onclick="initPage('networks')">
                        <div class="table-responsive-sm" style="max-height:50vh; overflow:auto;">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th class="w-50">Network</th>
                                        <th>Containers</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($networks as $networkName => $networkCount) { ?>
                                    <tr>
                                        <td><?= $networkName ?></td>
                                        <td><?= $networkCount?></td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-sm-12 col-lg-6">
                    <div class="bg-secondary rounded p-2 mt-2">
                        <div class="table-responsive-sm" style="max-height:50vh; overflow:auto;">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th class="w-50">Container</th>
                                        <th>Port</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if ($ports) {
                                        foreach ($ports as $container => $containerPorts) {
                                            foreach ($containerPorts as $containerPort) {
                                                $portArray[$containerPort] = $container;
                                            }
                                        }
                                        ksort($portArray);
                                        $portArray = formatPortRanges($portArray);
                                        
                                        if ($portArray) {
                                            foreach ($portArray as $port => $container) {
                                                ?>
                                                <tr>
                                                    <td><?= $container ?></td>
                                                    <td><?= $port?></td>
                                                </tr>
                                                <?php  
                                            }
                                        }
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-12 col-lg-6">
            <div id="chart-cpu-container" class="bg-secondary rounded p-2 mt-2"></div>
        </div>
        <div class="col-sm-12 col-lg-6">
            <div id="chart-memoryPercent-container" class="bg-secondary rounded p-2 mt-2"></div>
        </div>
        <div class="col-sm-12 col-lg-6"></div>
        <div class="col-sm-12 col-lg-6">
            <div id="chart-memorySize-container" class="bg-secondary rounded p-2 mt-2"></div>
        </div>
    </div>
    <?php
    }

    displayTimeTracking($loadTimes);

    //-- CPU
    foreach ($graphs['utilization']['cpu']['containers'] as $containerName => $containerPercent) {
        $utilizationCPULabels[] = $containerName;
        $utilizationCPUData[]   = $containerPercent;
    }

    //-- MEMORY PERCENT
    $utilizationMemoryPercentLabels = $utilizationMemoryPercentData = [];
    foreach ($graphs['utilization']['memory']['containers'] as $containerName => $graphDetails) {
        $utilizationMemoryPercentLabels[]   = $containerName;
        $utilizationMemoryPercentData[]     = $graphDetails['percent'];
    }

    //-- MEMORY SIZE
    $utilizationMemorySizeLabels = $utilizationMemorySizeData = $utilizationmemorySizeColors = [];
    foreach ($graphs['utilization']['memory']['containers'] as $containerName => $graphDetails) {
        $g = str_contains($graphDetails['size'], 'GiB') ? true : false;
        $utilizationMemorySizeLabels[]  = $containerName;
        $utilizationMemorySizeData[]    = preg_replace('/[^0-9.]/', '', $graphDetails['size']) * ($g ? 1024 : 1);
        $utilizationmemorySizeColors[]  = '#' . str_pad(dechex(mt_rand(0, 0xFFFFFF)), 6, '0', STR_PAD_LEFT);
    }

    ?>
    <script>
        GRAPH_UTILIZATION_CPU_LABELS            = '<?= json_encode($utilizationCPULabels) ?>';
        GRAPH_UTILIZATION_CPU_DATA              = '<?= json_encode($utilizationCPUData) ?>';
        GRAPH_UTILIZATION_MEMORY_PERCENT_LABELS = '<?= json_encode($utilizationMemoryPercentLabels) ?>';
        GRAPH_UTILIZATION_MEMORY_PERCENT_DATA   = '<?= json_encode($utilizationMemoryPercentData) ?>';
        GRAPH_UTILIZATION_MEMORY_SIZE_LABELS    = '<?= json_encode($utilizationMemorySizeLabels) ?>';
        GRAPH_UTILIZATION_MEMORY_SIZE_DATA      = '<?= json_encode($utilizationMemorySizeData) ?>';
        GRAPH_UTILIZATION_MEMORY_SIZE_COLORS    = '<?= json_encode($utilizationmemorySizeColors) ?>';
    </script>
    <?php
}

if ($_POST['m'] == 'toggleOverviewView') {
    $layout = $_POST['enabled'] ? UI::OVERVIEW_DETAILED : UI::OVERVIEW_SIMPLE;
    $database->setSetting('overviewLayout', $layout);
}
