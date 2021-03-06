<?php
/**
 *  DVelum project https://github.com/dvelum/dvelum
 *  Copyright (C) 2011-2017  Kirill Yegorov
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace Dvelum\Externals\Client;

use Dvelum\Externals\ClientInterface;
use Dvelum\File;
use Dvelum\Lang\Dictionary as Lang;
use Dvelum\Config\ConfigInterface;

/**
 * Add-ons repository client
 */
class Packagist implements ClientInterface
{
    /**
     * @var ConfigInterface
     */
    protected $config;
    /**
     * @var string
     */
    protected $language = 'en';
    /**
     * @var Lang
     */
    protected $lang;
    /**
     * @var Lang
     */
    protected $appLang;
    /**
     * @var string $tmpDir
     */
    protected $tmpDir;

    protected $actions = [
        'list' => 'https://packagist.org/packages/list.json',
        'info' => 'https://repo.packagist.org/packages/'
    ];

    public function __construct(ConfigInterface $repoConfig)
    {
        $this->config = $repoConfig;
        $this->appLang = \Dvelum\Lang::lang();
    }

    /**
     * Set tmp dir for downloads
     * @param string $dir
     */
    public function setTmpDir(string $dir): void
    {
        $this->tmpDir = $dir;
    }

    /**
     * Set client language
     * @param $lang
     */
    public function setLanguage(string $lang): void
    {
        $this->language = $lang;
    }

    /**
     * set localization dictionary
     * @param Lang $lang
     */
    public function setLocalization(Lang $lang): void
    {
        $this->lang = $lang;
    }

    /**
     * Get add-ons list
     * @param array $params
     * @return array
     * @throws \Exception
     */
    public function getList(array $params): array
    {
        $requestUrl = $this->actions['list'] . '?vendor=' . $this->config->get('vendor');

        try {
            $data = json_decode(file_get_contents($requestUrl), true);
        } catch (\Throwable $e) {
            return [];
        }

        if (!isset($data['packageNames'])) {
            return [];
        }

        $result = [];
        foreach ($data['packageNames'] as $pack) {
            $itemUrl = $this->actions['info'] . $pack . '.json';
            try {
                $info = json_decode(file_get_contents($itemUrl), true);
            } catch (\Throwable $e) {
                return [];
            }
            if (!isset($info['package']) || $info['package']['type'] != 'dvelum3-module') {
                continue;
            }
            $info = $info['package'];

            $lastVersion = '';
            $date = '';
            foreach ($info['versions'] as $number => $item) {
                if (strpos($number, 'dev') === false) {
                    $lastVersion = $number;
                    $date = date('Y-m-d H:i:s', strtotime($item['time']));
                    break;
                }
            }

            $result[] = [
                'code' => $pack,
                'title' => $info['description'],
                'downloads' => $info['downloads']['total'],
                'number' => $lastVersion,
                'date' => $date
            ];
        }

        return $result;
    }

    /**
     * Get dist file download url
     * @param string $package
     * @param string $version
     * @return string|null
     */
    protected function getDownloadUrl(string $package, string $version): ?string
    {
        $itemUrl = $this->actions['info'] . $package . '.json';
        try {
            $info = json_decode(file_get_contents($itemUrl), true);
        } catch (\Throwable $e) {
            return null;
        }

        if (!isset($info['package']) || $info['package']['type'] != 'dvelum-module' || !isset($info['package']['versions'][$version])) {
            return null;
        }

        //return $info['package']['versions'][$version]['dist']['url'];
        // https://github.com/dvelum/module-articles/archive/2.0.3.zip
        return $info['package']['repository'].'/archive/'.$version.'.zip';
    }

    /**
     * Download add-on
     * @param string $app
     * @param string $version
     * @return bool
     * @throws \Exception
     */
    public function download(string $app, string $version): bool
    {
        if (!extension_loaded('curl')) {
            throw new \Exception($this->lang->get('error_need_curl'));
        }

        if (!extension_loaded('zip')) {
            throw new \Exception($this->lang->get('error_need_zip'));
        }

        $requestUrl = $this->getDownloadUrl($app, $version);

        if (empty($requestUrl)) {
            throw new \Exception($this->lang->get('cant_find_url'));
        }

        $tmpDir = $this->tmpDir . $app;

        if (!is_dir($tmpDir)) {
            if (!mkdir($tmpDir, 0775, true)) {
                throw new \Exception($this->lang->get('CANT_WRITE_FS') . ' ' . $tmpDir);
            }
        }

        $downloadPath = $this->config->get('download_path') . '/' . $app;

        if (!is_dir($downloadPath)) {
            if (!mkdir($downloadPath, 0775, true)) {
                throw new \Exception($this->lang->get('CANT_WRITE_FS') . ' ' . $downloadPath);
            }
        }

        $tmpFile = $this->tmpDir . uniqid('', true) . '.zip';

        $this->downloadRequest($requestUrl, $tmpFile);

        if (!file_exists($tmpFile)) {
            throw new \Exception($this->appLang->get('CANT_EXEC'));
        }

        if (!File::unzipFiles($tmpFile, $tmpDir)) {
            throw new \Exception($this->lang->get('error_cant_extract') . ' ' . $tmpFile);
        }

        $files =  scandir($tmpDir);
        $srcDir = false;
        foreach ($files as $item){
            if($item!='.' && $item!='..' && is_dir($tmpDir.'/'.$item)){
                $srcDir = $item;
            }
        }

        if(empty($srcDir)){
            throw new \Exception('Unknown archive structure');
        }

        if (!File::copyDir($tmpDir.'/'.$srcDir, $downloadPath)) {
            throw new \Exception($this->appLang->get('CANT_WRITE_FS') . ' ' . $this->config->get('download_path'));
        }
        @unlink($tmpFile);

        File::rmdirRecursive($tmpDir, true);

        return true;
    }

    /**
     * Download add-on file
     * @param string $url
     * @param string $path
     * @throws \Exception
     */
    protected function downloadRequest($url, $path)
    {
        set_time_limit(120);
        $fp = fopen($path, 'w+');
        $curl = \curl_init();
        \curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => 1,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_HEADER => 0,
            CURLOPT_CONNECTTIMEOUT => 60,
            CURLOPT_FILE => $fp
            // Auth options
            // CURLOPT_HTTPAUTH, CURLAUTH_ANY,
            // CURLOPT_USERPWD, 'user:password',
        ]);
        $result = \curl_exec($curl);
        $httpCode = \curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($httpCode !== 200) {
            throw new \Exception($this->language->get('error_connection') . ' RESPONSE CODE: ' . $httpCode);
        }
        if (!$result) {
            throw new \Exception($this->language->get('error_connection') . ': ' . \curl_error($curl));
        }
        \curl_close($curl);
    }
}