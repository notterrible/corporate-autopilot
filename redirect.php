<?php

namespace PantheonSystems;

use Exception;

/*
 * User editable constants below.
 */
const PANTHEON_REDIRECT_CACHE_AGE = 180;        // Time the redirect cache is valid for in days.
const PANTHEON_REDIRECT_FILE_AGE = 14;          // Time the domain file is valid for in days.
const PANTHEON_REDIRECT_ENVIRONMENT = ['live']; // The environments that should be redirected.
const PANTHEON_REDIRECT_HSTS = true;            // If not using HSTS in pantheon.yml, set this to FALSE.

/*******************************
 * NO EDITING PAST THIS POINT! *
 *******************************/

/**
 * Redirect to primary domain.
 * Ensure we're on the Pantheon Platform,
 * and the command is not being run from the CLI.
 */
if (!empty($_ENV['PANTHEON_ENVIRONMENT'])
  && !empty($_ENV['PANTHEON_INFRASTRUCTURE_ENVIRONMENT'])
  && (php_sapi_name() != "cli")) {

  // Instantiate the redirect class.
    $pantheon_redirect = new PantheonRedirect();

    // Redirect request.
    $pantheon_redirect->redirect();
}


class PantheonRedirect
{
    public $environment_domain;

    public $environment;

    public $cache_age = 180;

    public $file_age = 14;

    public $redirect_env = ['live'];

    public $hsts = true;

    private $domain_file_name = 'pantheon_domains.json';

    private $domain_file_path;

    /**
     * @throws \Exception
     */
    public function __construct()
    {
        if (!isset($_ENV['PANTHEON_ENVIRONMENT'])) {
            throw new Exception('PANTHEON_ENVIRONMENT is not set.');
        }
        $this->cache_age = (defined('PANTHEON_REDIRECT_CACHE_AGE')) ? PANTHEON_REDIRECT_CACHE_AGE : $this->cache_age;
        $this->file_age = (defined('PANTHEON_REDIRECT_FILE_AGE')) ? PANTHEON_REDIRECT_FILE_AGE : $this->file_age;
        $this->redirect_env = (defined('PANTHEON_REDIRECT_ENVIRONMENT')) ? PANTHEON_REDIRECT_ENVIRONMENT : $this->redirect_env;
        $this->hsts = (defined('PANTHEON_REDIRECT_HSTS')) ? PANTHEON_REDIRECT_HSTS : $this->hsts;
        $this->environment = $_ENV['PANTHEON_ENVIRONMENT'];
        $this->environment_domain = "{$_ENV['PANTHEON_ENVIRONMENT']}-{$_ENV['PANTHEON_SITE_NAME']}.pantheonsite.io";
        $this->domain_file_path = $this->get_private_directory() . '/' . $this->domain_file_name;
    }

    /**
     * Get private directory path
     */
    public function get_private_directory(): string
    {
        $file_directory = $_ENV['FILEMOUNT'];
        $private_directory = $file_directory . '/private';

        // Check if the directory already exists.
        if (!is_dir($private_directory)) {
            // Directory does not exist, create it.
            mkdir($private_directory);
        }

        return $private_directory;
    }

    /**
     *  Standard Pantheon redirect.
     *  https://pantheon.io/docs/redirects
     */
    public function redirect()
    {

    // Check if current environment should be redirected,
        // and that we're only redirecting the platform domain.
        if (
      in_array($this->environment, $this->redirect_env)
      && strpos($_SERVER['HTTP_HOST'], 'pantheonsite.io') !== -1) {

      // Get primary domain
            $primary_domain = $this->get_primary_domain();
        } else {
            // Not redirecting, return.
            return;
        }

        $requires_redirect = false;

        // Ensure the site is being served from the primary domain.
        if ($_SERVER['HTTP_HOST'] !== $primary_domain) {
            $requires_redirect = true;
        }

        // Only used if you're not using HSTS in the pantheon.yml file.
        if (!$this->hsts) {
            if (!isset($_SERVER['HTTP_USER_AGENT_HTTPS']) || $_SERVER['HTTP_USER_AGENT_HTTPS'] != 'ON') {
                $requires_redirect = true;
            }
        }

        // Redirect to primary domain if we've determined we need to.
        if ($requires_redirect === true) {

      // Name transaction "redirect" in New Relic for improved reporting (optional).
            if (extension_loaded('newrelic')) {
                newrelic_name_transaction("redirect");
            }

            // Cache redirect longer than Global CDN default of 24 hours
            $seconds = $this->cache_age * 86400;
            header("Cache-Control: max-age=$seconds, public");

            // Redirect request.
            header('HTTP/1.0 301 Moved Permanently');
            header('Location: https://' . $primary_domain . $_SERVER['REQUEST_URI']);

            // Exit to prevent any further PHP processing.
            exit();
        }
    }

    /**
     * Fetch customer domains.
     *
     * @param [string] $env
     *
     * @return string
     */
    public function get_primary_domain(): string
    {

    // Get domains from cache.
        $domains = $this->get_domain_list();

        // Check if custom domains are available.
        if (count($domains) > 1) {
            return $this->get_custom_domain($domains);
        }

        // If no custom domains are available, use the environment domain.
        return $this->environment_domain;
    }

    /**
     * Get domain list from cache
     *
     * @return array
     */
    protected function get_domain_list(): array
    {

    // Check if the file exists.
        if (!file_exists($this->domain_file_path)) {
            // File doesn't exist, create it.
            $this->set_domain_list();
        }

        $now = time();
        $file_time = filemtime($this->domain_file_path);
        $file_age = $now - $file_time;

        // If file age is set, use that, else default to a month.
        $file_age_expiration = ($this->file_age > 0) ? $this->file_age * 86400 : 2629746;

        // If the file has expired, refresh it.
        if ($file_age > $file_age_expiration) {
            $this->set_domain_list();
        }

        // Get the latest domains from the file.
        return json_decode(file_get_contents($this->domain_file_path), true);
    }

    /**
     * Update domain file, return domain list.
     *
     * @return array
     */
    protected function set_domain_list(): array
    {
        $req = pantheon_curl("https://api.live.getpantheon.com/sites/self/environments/$this->environment/hostnames", null, 8443);
        $domains = json_decode($req['body'], true);

        file_put_contents($this->domain_file_path, json_encode($domains));

        return $domains;
    }

    /**
     * Get primary domain (if available)
     *
     * @param [array] $domains
     * @param [string] $env
     *
     * @return string
     */
    public function get_custom_domain($domains): string
    {

    // Loop through domains
        foreach ($domains as $domain) {

      // Only look at custom domains
            if (!empty($domain['type']) && $domain['type'] == 'custom') {

        // Check if there's only one custom domain.
                // If so, use that and skip the primary check.
                // Count is 2 because it includes the platform domain.
                if (count($domains) == 2) {
                    return $domain['key'];
                }

                // If domain is marked as primary, use that.
                if (!empty($domain['primary'])) {
                    return $domain['key'];
                }
            }
        }

        return $this->environment_domain;
    }
}
