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
    echo sprintf("$string\n", ...$replacements);
}

if(!is_file('config.json')) {
    return 'No config file found';
}

$config = json_decode(file_get_contents('config.json'), true);
if(!is_array($config)) {
    return 'Error in config file';
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
    ];

    if($phpVersion = array_get($siteConfig, 'fpm')) {
        $siteReplacements['fpm'] = str_replace('{{ phpVersion }}', $phpVersion, $template['fpm']);
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