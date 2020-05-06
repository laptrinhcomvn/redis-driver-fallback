<?php
namespace Pdeio\RedisDriverFallback;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\RedisStore;


/**
 * Class RedisDriverFallback
 * @package pdeio\redis-driver-fallback
 *
 * @author Paulo De Iovanna
 */
class RedisDriverFallback extends CacheManager
{

    /**
     * Resolve the given store.
     *
     * @param  string $currentDriver
     * @return \Illuminate\Contracts\Cache\Repository
     * @throws Exception
     */
    protected function resolve($currentDriver)
    {
        // check if the cache driver is redis
        if (config('redis-driver-fallback.fallback_turn_on', true) && $currentDriver === 'redis') {
            try {
                $repository = parent::resolve($currentDriver);
                //get the flag, to clear or not the redis's cache
                if ($this->flag_exists()) {
                    $this->deleteFlag($this->getFlagPath());
                    if (config('redis-driver-fallback.sync_mode', false)) {
                        //clear the new driver's cache
                        $this->clearCache('redis');
                    }
                }
                return $repository;
            } catch (\Exception $e) {
                //set the new cache driver
                $newDriver = $this->getNewDriver();
                if (!$this->flag_exists()) {
                    $this->putFlag();
                    // fires event
                    event('redis.unavailable', null);
                    // send email alert
                    if (config('redis-driver-fallback.email_config.send_email', false) == true) {
                        $this->sendEmail();
                    }
                    if (config('redis-driver-fallback.sync_mode', false)) {
                        //clear the new driver's cache
                        $this->clearCache($newDriver);
                    }
                }
                return $this->resolve($newDriver);
            }
        }
        return parent::resolve($currentDriver);
    }
    /**
     * Ping redis, to test connection
     *
     *  @return void
     *  @throws Exception
     *
     */

    protected function createRedisDriver(array $config)
    {
        if (config('redis-driver-fallback.fallback_turn_on', true)) {

            $config = $this->getConfig('redis');
            $redis = $this->app['redis'];
            $connection =  $config['connection'] ?? 'default';
            $store = new RedisStore($redis, $this->getPrefix($config), $connection);
            try {
                $store->getRedis()->ping();
                return $this->repository($store);
            } catch (\Exception $e) {
                throw $e;
            }
        } else {
            return parent::createRedisDriver($config);
        }
    }
    /**
     * Get next driver name based on fallback priority
     *
     * @param $driverName
     * @return string|null
     */
    private function getNewDriver()
    {
        return config('redis-driver-fallback.fallback_driver', 'file');
    }

    /**
     * Send an email with problem informations
     *
     *  @return void
     */
    private function sendEmail()
    {
        try {
            \Illuminate\Support\Facades\Mail::to(config('redis-driver-fallback.email_config.to', config('mail.username')))
                ->send(new AlertEmail());
        } catch (\Exception $e) {
            if (config('redis-driver-fallback.email_config.catch_error', false)) {
                $error = 'Cannot send an alert email, with the pdeio/redis-driver-fallback package. (' . \Carbon\Carbon::now() . ') \n' . $e;
                $contents = \Storage::get('redis/mails_error.log');
                $contents .= $error;
                \Storage::put('redis/mails_error.log', $contents);
            } else {
                throw $e;
            }
        }
    }

    /**
     * Clear the cache of the selected cache driver, in this way
     * don't run the risk of downloading not updated data from the new cache.
     *
     *  @return void
     *
     *
     */
    private function clearCache($newDriver)
    {

        $config = $this->getConfig($newDriver);
        $driverMethod = 'create' . ucfirst($config['driver']) . 'Driver';

        // check if the new cache, is supported
        if (method_exists($this, $driverMethod)) {
            // clear the cache.
            $this->{$driverMethod}($config)->clear();
        } else {
            throw new InvalidArgumentException("Driver [{$config['driver']}] is not supported.");
        }
    }

    /**
     * Create flag
     *
     *  @return void
     *
     *
     */
    private function putFlag()
    {
        $data = json_encode(['redis_is_down' => true]);
        // update the flag
        \Storage::put($this->getFlagPath(), $data);
    }
    private function getFlagPath()
    {
        return 'redis/redis_cachedriver_fallback.json';
    }
    private function deleteFlag()
    {
        // remove the flag
        \Storage::delete($this->getFlagPath());
    }

    /**
     * Check the redis flag
     *
     *  @return boolean
     *
     *
     */
    private function flag_exists()
    {
        //check the flag
        return \Storage::exists($this->getFlagPath());
    }
}
