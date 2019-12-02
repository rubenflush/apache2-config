# apache2-config

## How to use

1. Checkout this repository - `git clone git@github.com:rubenflush/apache2-config.git && cd apache2-config`
2. Copy `config.example.json` to `config.json` and modify where needed - `cp config.example.json config.json && vim config.json`
3. Execute processing script - `sudo php process.php`

A backup of your current apache config files will be created and maximum 10 copies will be kept.
