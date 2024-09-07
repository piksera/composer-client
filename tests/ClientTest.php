<?php

namespace PikseraPackages\ComposerClient;

use PHPUnit\Framework\TestCase;
use PikseraPackages\ComposerClient\Client;

class ClientTest extends TestCase
{
    public function testMarketplaceIndex()
    {
        $composerClient = new Client();
        $composerSearch = $composerClient->search();

        foreach($composerSearch as $packageName=>$versions) {
            if (!is_array($versions)) {
                continue;
            }
            foreach($versions as $version) {
                if (strpos($version['name'], 'template') !== false) {
                    $this->assertNotEmpty($version['extra']['_meta']['screenshot']);
                    $this->assertNotEmpty($version['dist']['url']);
                }
            }
        }

    }

}
