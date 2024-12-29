<?php

namespace PikseraPackages\ComposerClient;

use PikseraPackages\App\Models\SystemLicenses;
use PikseraPackages\ComposerClient\Traits\FileDownloader;

class Client
{
    use FileDownloader;

    public $licenses = [];
    public $packageServers = [
        'https://pikserapi.com/packages.json',
    ];

 
    public $updaterServers = [
        'https://pikserapi.com/', // Local API server
    ];
    

    public function __construct()
    {
        //
    }

    public function setLicenses(array $licenses)
    {
        $this->licenses = $licenses;
    
        $logFile = storage_path('logs/license_set.log');
        file_put_contents($logFile, "Set Licenses:\n" . print_r($licenses, true), FILE_APPEND);
    }
    

    public function addLicense($license)
    {
        $this->licenses[] = $license;
    }
    public function consumeLicense($license, $relType, $consumeAfterDownload = false)
{
    $status = 'invalid';
    $valid = false;
    $logFile = storage_path('logs/license_consume.log');

    foreach ($this->updaterServers as $server) {
        $licenseConsumeUrl = rtrim($server, '/') . '/api/licenses/consume';

        $payload = ['key' => $license, 'relType' => $relType];
        $headers = $this->prepareHeaders();
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $licenseConsumeUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => array_merge($headers, ['Content-Type: application/json']),
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        file_put_contents($logFile, "Request: {$licenseConsumeUrl}\nPayload: " . json_encode($payload) . "\nResponse: {$response}\nHTTP Code: {$httpCode}\nError: {$error}\n", FILE_APPEND);

        if (!$error && $httpCode == 200) {
            $data = json_decode($response, true);
            if (isset($data['details']['status']) && $data['details']['status'] === 'consumed' && $data['details']['relType'] === $relType) {
                $status = 'consumed';
                $valid = true;

                if (!$consumeAfterDownload) {
                    $this->saveLicenseToSystem($data['details'], $relType);
                }

                file_put_contents($logFile, "License validated successfully.\n", FILE_APPEND);
                break;
            } else {
                file_put_contents($logFile, "RelType mismatch or invalid status.\n", FILE_APPEND);
            }
        } else {
            file_put_contents($logFile, "Failed to validate license. Error: {$error}\n", FILE_APPEND);
        }
    }

    return ['valid' => $valid, 'status' => $status];
}

    
    
    
    /**
     * Save the consumed license to the system_licenses table.
     *
     * @param array $licenseDetails The details of the license from the API.
     * @param string|null $relType The relative type for the license.
     */
    private function saveLicenseToSystem(array $licenseDetails, $relType)
    {
        $license = new SystemLicenses();
        $license->rel_type = $relType;
        $license->local_key = $licenseDetails['local_key'] ?? null;
        $license->local_key_hash = $licenseDetails['md5hash'] ?? null;
        $license->registered_name = $licenseDetails['registeredname'] ?? null;
        $license->domains = $licenseDetails['validdomain'] ?? null;
        $license->status = $licenseDetails['status'] ?? null;
        $license->product_id = $licenseDetails['productid'] ?? null;
        $license->service_id = $licenseDetails['serviceid'] ?? null;
        $license->billing_cycle = $licenseDetails['billingcycle'] ?? null;
        $license->reg_on = $licenseDetails['regdate'] ?? null;
        $license->due_on = $licenseDetails['nextduedate'] ?? null;
    
        $license->save();
    }
    
    

    

    public function getPackageByName($packageName, $packageVersion = false) {
        $foundedPackage = [];
        foreach ($this->packageServers as $package) {
            $singlePackageParseUrl = parse_url($package);
            $singlePackageUrl = $singlePackageParseUrl['scheme'] .'://'. $singlePackageParseUrl['host']. '/packages/'.$packageName.'.json';
            file_put_contents(storage_path('logs/package_debug.log'), "Trying URL: $singlePackageUrl" . PHP_EOL, FILE_APPEND);
    
            $packageFile = $this->getPackageFile($singlePackageUrl);
            
            if (!empty($packageFile)) {
                foreach ($packageFile as $name => $versions) {
                    if (!is_array($versions)) {
                        continue;
                    }
                    if ($packageName == $name) {
                        $versions['latest'] = end($versions);
                        $foundedPackage = $packageVersion ? ($versions[$packageVersion] ?? []) : end($versions);
                    }
                }
            }
        }
    
        return $foundedPackage;
    }
    

    public function search($filter = array())
    {
        if (!empty($filter) && isset($filter['require_name'])) {

            $packageName = $filter['require_name'];

            $packageVersion = false;
            if (isset($filter['require_version'])) {
                $packageVersion = $filter['require_version'];
            }

            return $this->getPackageByName($packageName, $packageVersion);
        }

        $packageFileMerged = [];
        foreach ($this->packageServers as $package) {
            $packageFile = $this->getPackageFile($package);
            if (!empty($packageFile)) {
                $packageFileMerged = array_merge($packageFileMerged, $packageFile);
            }
        }

        return $packageFileMerged;
    }

    public function prepareHeaders()
    {
        $headers = [];

        if (defined('MW_VERSION')) {
            $headers[] = "x-mw-version: " . MW_VERSION;
        }

        if (function_exists('site_url')) {
            $headers[] = "x-mw-site-url: " . base64_encode(site_url());
        }

        if (!empty($this->licenses)) {

            $base64EncodedPassword = base64_encode(json_encode($this->licenses));
            $base64Encoded = base64_encode('license:' . $base64EncodedPassword);

            $headers[] = "Authorization: Basic " . $base64Encoded;
        }

        if (function_exists('mw_root_path')) {
            $headers[] = "x-mw-root-path: " . base64_encode(mw_root_path());
        }

        return $headers;
    }

    public function getPackageFile($packageUrl)
    {
        $curl = curl_init();
    
        $headers = $this->prepareHeaders();
    
        $opts = [
            CURLOPT_URL => $packageUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_POSTFIELDS => "",
        ];
        if (!empty($headers)) {
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0); // Skip SSL Verification
    
        curl_setopt_array($curl, $opts);
    
        $response = curl_exec($curl);
        $err = curl_error($curl);
    
        file_put_contents(storage_path('logs/package_debug.log'), "Request: $packageUrl" . PHP_EOL, FILE_APPEND);
    
        if ($err) {
            file_put_contents(storage_path('logs/package_debug.log'), "Error: $err" . PHP_EOL, FILE_APPEND);
            return ["error" => "cURL Error #:" . $err];
        } else {
            file_put_contents(storage_path('logs/package_debug.log'), "Response: $response" . PHP_EOL, FILE_APPEND);
            $getPackages = json_decode($response, true);
    
            if (isset($getPackages['packages']) && is_array($getPackages['packages'])) {
                return $getPackages['packages'];
            }
            return [];
        }
    }
    

    public function notifyPackageInstall($package)
    {
        $packageUrl = false;
        if (isset($package['notification-url'])) {
            $packageUrl = $package['notification-url'];
        }

        if(!$packageUrl){
            return;
        }

        $curl = curl_init();

        $headers = $this->prepareHeaders();

        $opts = [
            CURLOPT_URL => $packageUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => "",
        ];
        if (!empty($headers)) {
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }

        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0); // Skip SSL Verification

        curl_setopt_array($curl, $opts);

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            return ["error" => "cURL Error #:" . $err];
        } else {
            return @json_decode($response, true);

        }

    }


}
