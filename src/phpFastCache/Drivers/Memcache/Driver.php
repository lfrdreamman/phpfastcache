<?php
/**
 *
 * This file is part of phpFastCache.
 *
 * @license MIT License (MIT)
 *
 * For full copyright and license information, please see the docs/CREDITS.txt file.
 *
 * @author Khoa Bui (khoaofgod)  <khoaofgod@gmail.com> http://www.phpfastcache.com
 * @author Georges.L (Geolim4)  <contact@geolim4.com>
 *
 */

namespace phpFastCache\Drivers\Memcache;

use Memcache as MemcacheSoftware;
use phpFastCache\Core\Pool\DriverBaseTrait;
use phpFastCache\Core\Pool\ExtendedCacheItemPoolInterface;
use phpFastCache\Entities\driverStatistic;
use phpFastCache\Exceptions\phpFastCacheDriverCheckException;
use phpFastCache\Exceptions\phpFastCacheDriverException;
use phpFastCache\Util\MemcacheDriverCollisionDetectorTrait;
use Psr\Cache\CacheItemInterface;

/**
 * Class Driver
 * @package phpFastCache\Drivers
 */
class Driver implements ExtendedCacheItemPoolInterface
{
    use DriverBaseTrait, MemcacheDriverCollisionDetectorTrait;

    /**
     * @var int
     */
    protected $memcacheFlags = 0;

    /**
     * Driver constructor.
     * @param array $config
     * @throws phpFastCacheDriverException
     */
    public function __construct(array $config = [])
    {
        self::checkCollision('Memcache');
        $this->setup($config);

        if (!$this->driverCheck()) {
            throw new phpFastCacheDriverCheckException(sprintf(self::DRIVER_CHECK_FAILURE, $this->getDriverName()));
        } else {
            $this->instance = new MemcacheSoftware();
            $this->driverConnect();

            if (array_key_exists('compress_data', $config) && $config[ 'compress_data' ] === true) {
                $this->memcacheFlags = MEMCACHE_COMPRESSED;
            }
        }
    }

    /**
     * @return bool
     */
    public function driverCheck()
    {
        return class_exists('Memcache');
    }

    /**
     * @param \Psr\Cache\CacheItemInterface $item
     * @return mixed
     * @throws \InvalidArgumentException
     */
    protected function driverWrite(CacheItemInterface $item)
    {
        /**
         * Check for Cross-Driver type confusion
         */
        if ($item instanceof Item) {
            return $this->instance->set($item->getKey(), $this->driverPreWrap($item), $this->memcacheFlags, $item->getTtl());
        } else {
            throw new \InvalidArgumentException('Cross-Driver type confusion detected');
        }
    }

    /**
     * @param \Psr\Cache\CacheItemInterface $item
     * @return mixed
     */
    protected function driverRead(CacheItemInterface $item)
    {
        $val = $this->instance->get($item->getKey());

        if ($val === false) {
            return null;
        } else {
            return $val;
        }
    }

    /**
     * @param \Psr\Cache\CacheItemInterface $item
     * @return bool
     * @throws \InvalidArgumentException
     */
    protected function driverDelete(CacheItemInterface $item)
    {
        /**
         * Check for Cross-Driver type confusion
         */
        if ($item instanceof Item) {
            return $this->instance->delete($item->getKey());
        } else {
            throw new \InvalidArgumentException('Cross-Driver type confusion detected');
        }
    }

    /**
     * @return bool
     */
    protected function driverClear()
    {
        return $this->instance->flush();
    }

    /**
     * @return bool
     */
    protected function driverConnect()
    {
        $servers = (!empty($this->config[ 'memcache' ]) && is_array($this->config[ 'memcache' ]) ? $this->config[ 'memcache' ] : []);
        if (count($servers) < 1) {
            $servers = [
              ['127.0.0.1', 11211],
            ];
        }

        foreach ($servers as $server) {
            try {
                if (!$this->instance->addserver($server[ 0 ], $server[ 1 ])) {
                    $this->fallback = true;
                }
                if(!empty($server[ 'sasl_user' ]) && !empty($server[ 'sasl_password'])){
                    $this->instance->setSaslAuthData($server[ 'sasl_user' ], $server[ 'sasl_password']);
                }
            } catch (\Exception $e) {
                $this->fallback = true;
            }
        }
    }

    /********************
     *
     * PSR-6 Extended Methods
     *
     *******************/

    /**
     * @return driverStatistic
     */
    public function getStats()
    {
        $stats = (array) $this->instance->getstats();
        $stats[ 'uptime' ] = (isset($stats[ 'uptime' ]) ? $stats[ 'uptime' ] : 0);
        $stats[ 'version' ] = (isset($stats[ 'version' ]) ? $stats[ 'version' ] : 'UnknownVersion');
        $stats[ 'bytes' ] = (isset($stats[ 'bytes' ]) ? $stats[ 'version' ] : 0);
        
        $date = (new \DateTime())->setTimestamp(time() - $stats[ 'uptime' ]);

        return (new driverStatistic())
          ->setData(implode(', ', array_keys($this->itemInstances)))
          ->setInfo(sprintf("The memcache daemon v%s is up since %s.\n For more information see RawData.", $stats[ 'version' ], $date->format(DATE_RFC2822)))
          ->setRawData($stats)
          ->setSize($stats[ 'bytes' ]);
    }
}