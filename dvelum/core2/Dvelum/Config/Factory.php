<?php
declare(strict_types=1);
/*
 * DVelum project http://code.google.com/p/dvelum/, https://github.com/k-samuel/dvelum , http://dvelum.net Copyright
 * (C) 2011-2016 Kirill A Egorov This program is free software: you can
 * redistribute it and/or modify it under the terms of the GNU General Public
 * License as published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version. This program is distributed
 * in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even
 * the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details. You should have received
 * a copy of the GNU General Public License along with this program. If not, see
 * <http://www.gnu.org/licenses/>.
 */
namespace Dvelum\Config;

use Dvelum\Config;

/**
 * Configuration Object Factory
 *
 * @author Kirill Egorov 2010
 * @package Config
 * @static
 *
 */
class Factory
{
    const Simple = 0;
    const File_Array = 1;

    /**
     *
     * @var Store_Interface
     */
    protected static $store = false;
    /**
     *
     * @var Cache_Interface
     */
    protected static $cache = false;

    /**
     * Set cache adapter
     *
     * @param Cache_Interface $core
     */
    public static function setCacheCore($core)
    {
        self::$cache = $core;
    }

    /**
     * Get cache adapter
     *
     * @return Cache_Interface | false
     */
    public static function getCacheCore()
    {
        return self::$cache;
    }

    /**
     * Factory method
     *
     * @param integer $type -type of the object being created, Config class constant
     * @param string $name - identifier
     * @param boolean $useCache - optional , default true. Use cache if available
     * @return Config\Config
     */
    static public function config(int $type , string $name , bool $useCache = true) : Config\Config
    {
        $store = self::$store;
        $cache = self::$cache;
        if(!$store)
            $store = self::_connectStore();

        $config = false;
        $configKey = $type . '_' . $name;

        /*
         * Check if config is already loaded
         */
        if($useCache && $store->keyExists($configKey))
            return $store->get($configKey);

        /*
         * If individual keys
         */
        if($useCache && $cache && $config = $cache->load($configKey))
        {
            $store->set($configKey , $config);
            return $config;
        }

        switch($type)
        {
            case self::File_Array :
                $config =  static::storage()->get($name,$useCache);
                break;
            case self::Simple :
                $config = new Config\Config($name);
                break;
        }

        if($useCache)
            $store->set($configKey , $config);

        if($useCache && $cache)
            $cache->save($config , $configKey);
        else
            self::cache();

        return $config;
    }

    /**
     * Clear cache
     */
    static public function resetCache()
    {
        if(is_null(self::$store))
            self::_connectStore();

        if(empty(self::$store))
            return;

        foreach(self::$store as $k => $v)
        {
            if(self::$cache)
                self::$cache->remove($k);

            self::$store->remove($k);
        }
    }

    /**
     * Instantiate storage
     * @return Store
     */
    static protected function _connectStore()
    {
        self::$store = \Store::factory(\Store::Local , 'class_' . __CLASS__);
        return self::$store;
    }

    /**
     * Cache data again
     * @property $key - optional
     * @return void
     */
    static public function cache($key = false)
    {
        if(! self::$cache)
            return;

        if($key === false)
        {
            foreach(self::$store as $k => $v)
            {
                self::$cache->save($v , $k);
            }
        }
        else
        {
            if(self::$store->keyExists($key))
            {
                self::$cache->save(self::$store->get($key) , $key);
            }
        }
    }

    /**
     * Get configuration storage
     * @return Storage
     */
    static public function storage() : Storage
    {
        static $store = false;

        if(!$store){
            $store = new Storage();
        }
        return $store;
    }

    /**
     * Create new config object
     * @param array $data
     * @return Config
     */
    static public function create(array $data) : Config\Config
    {
        $config = new Config\Config();
        $config->setData($data);
        return $config;
    }
}