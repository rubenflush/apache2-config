<?php

function dd()
{
    array_map('var_dump', func_get_args());
    exit;
}

function pre_r()
{
    array_map('print_r', func_get_args());
    exit;
}

function array_get($array, $key, $default = null)
{
    return array_key_exists($key, $array) ? $array[$key] : $default;
}

function info(string $string, ...$replacements)
{
    echo vsprintf("$string\n", $replacements);
}

if(!is_file('config.json')) {
    return 'No config file found';
}

$config = json_decode(file_get_contents('config.json'), true);
if(!is_array($config)) {
    info('Error in config file');
    info(json_last_error_msg());
	return;
}

if(in_array('--auto', $argv)) {
    info('Running in auto mode');

    if(!$config['root']) {
        info('No root set');
        exit;
    }

    // Read all available sites, listed in the root directory
    $newSites = [];
    foreach(glob(sprintf('%s*/', $config['root'])) as $siteDirectory) {
        // Remove all sites which are already in config
        $siteName = explode(DIRECTORY_SEPARATOR, trim($siteDirectory, DIRECTORY_SEPARATOR));
        $siteName = end($siteName);
        if(array_key_exists($siteName, $config['sites'])) {
            continue;
        }

        // Find index.php
        $laravel = false;
        if(is_file(sprintf('%sindex.php', $siteDirectory))
            || ($laravel = is_file(sprintf('%spublic/index.php', $siteDirectory)))) {

            $newSites[$siteName] = [];
            if(!$laravel) {
                $newSites[$siteName]['laravel'] = false;
            }
        }
    }

    info('- Adding %u new sites [%s]', count($newSites), implode(', ', array_keys($newSites)));
    if($newSites) {
        $config['sites'] += $newSites;

        info('- Writing new config file');
        file_put_contents('config.json', json_encode($config, JSON_PRETTY_PRINT));
    }
}

$apache2 = $config['apache2'];

$files = glob($apache2.'*.*');
if($files) {
    info('Creating backup of [%s] contents', $apache2);
    mkdir($copyDirectory = $apache2 . 'backup_' . date('ymd_his') . '/');
    foreach($files as $file) {
        rename($file, $copyDirectory . pathinfo($file, PATHINFO_BASENAME));
    }

    info('Moved all files to [%s]', $copyDirectory);
} else {
    info('No files to backup');
}

$replacements = [
    'httpPort' => $config['httpPort'],
    'httpsPort' => $config['httpsPort'],
    'serverAdmin' => $config['serverAdmin'],
    'ssl_certificate' => $config['ssl']['certificate_file'],
    'ssl_certificate_key' => $config['ssl']['key_file'],
];

$template = array_map('file_get_contents', [
    'default' => 'template.conf',
    'ssl' => 'template_ssl.conf',
    'fpm' => 'fpm.conf',
]);

foreach($config['sites'] as $name => $siteConfig) {

    info('Processing site [%s]', $name);

    $domain = array_get($siteConfig, 'domain', $config['domain']);
    $websiteName = array_get($siteConfig, 'name', $name);
    $documentRoot = array_get($siteConfig, 'documentDirectory', $config['root']);
    $directory = array_get($siteConfig, 'directory', $name);

    $siteReplacements = $replacements + [
        'host' => array_get($siteConfig, 'host', sprintf('%s.%s', $websiteName, $domain)),
        'documentRoot' =>  array_get($siteConfig, 'documentRoot', sprintf('%s%s', $documentRoot, $directory)),
        'ServerAlias' => null,
        'fpm' => '',
        'errorDocuments' => '',
    ];

    if(!is_dir($siteReplacements['documentRoot'])) {
        info(sprintf('- Document root not found for [%s]', $name));
        info('- Skipping site...');
        continue;
    }

    if($phpVersion = array_get($siteConfig, 'fpm')) {
        $siteReplacements['fpm'] = str_replace('{{ phpVersion }}', $phpVersion, $template['fpm']);
    }

    if($errorDocuments = array_get($siteConfig, 'errorDocuments', [])) {
        $siteReplacements['errorDocuments'] = implode("\n", array_map(function($httpCode, $action) {
            return sprintf('ErrorDocument %u %s', $httpCode, $action);
        }, array_keys($errorDocuments), $errorDocuments));
    }

    if($aliasList = array_get($siteConfig, 'alias', [])) {
        foreach($aliasList as &$alias) {
            if(strpos($alias, '.') === false) {
                $alias .= sprintf('.%s', $domain);
            }
        }

        $siteReplacements['ServerAlias'] = $aliasList
            ? sprintf('ServerAlias %s', implode(' ', $aliasList))
            : '';
    }

    if(array_get($siteConfig, 'laravel', array_get($config, 'laravel', false))) {
        $siteReplacements['documentRoot'] .= '/public';
    }

    $contents = $template['default'];
    if(array_get($siteConfig, 'ssl', $config['ssl']['enabled'])) {
        $contents .= "\n". $template['ssl'];
    }

    foreach($siteReplacements as $find => $replace) {
        $contents = str_replace(sprintf('{{ %s }}', $find), $replace, $contents);
    }

    file_put_contents($file = sprintf('%s%s.conf', $apache2, $name), $contents);

    info(' - Site [%s] stored as [%s]', $name, $file);
}

if($default = array_get($config, 'default')) {
    info('Adding default site [%s]', $default);
    copy(sprintf('%s%s.conf', $apache2, $default), sprintf('%s000-default.conf', $apache2));
}

if($postExecution = array_get($config, 'postExecution')) {
    foreach((array) $postExecution as $command) {
        info('Running command: %s', $command);
        $output = shell_exec($command);
        info('Done. Result: %s', json_encode($output));
    }
}

info('Cleaning up files');
foreach(array_slice(glob(sprintf('%sbackup_*', $apache2)), 0, -10) as $backup) {
    exec(sprintf('rm -rf %s', $backup));
}
