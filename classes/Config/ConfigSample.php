<?php

declare(strict_types=1);

namespace Config;

class Config
{
    public string $site_title = '';  // shown in <title> and the front-page heading

    // May strangers create their own role='user' account at /login/register.php?
    // The first (admin) account is always allowed, gated by bootstrap_token.txt.
    public bool $allow_registration = true;
    public string $domain_name = '';  // used for cookies
    public string $cookie_name = '';  // used for cookies
    public int $cookie_lifetime = 60 * 60 * 24 * 30; // 30 days
    public string $app_path = '/home/user/sub.domain.com';

    public string $dbHost = "eich";
    public string $dbName = "";
    public string $dbUser = "";
    public string $dbPass = "";
}
