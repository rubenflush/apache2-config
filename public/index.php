<?php

$file = __DIR__.'/../config.json';
$data = json_decode(file_get_contents($file), true);

?>
<table>
    <tr>
        <th>Site</th>
        <th>PHP</th>
        <th>Root</th>
    </tr>
    <?php
    foreach($data['sites'] as $site => $row) {
        $php = $row['fpm'] ?? '7.3';
        $root = $row['documentRoot'] ?? $data['root'].$site;
        $url = $site.'.'.$data['domain'];
        echo "<tr><td><a href='http://$url' target='_blank'>$url</td><td>$php</td><td>$root</td></tr>";
    }
    ?>
</table>

<style>
    th {
        text-align: left;
    }
</style>
