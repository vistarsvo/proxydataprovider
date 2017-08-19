<?php
/**
 * This file is part of the Vistar project.
 * This source code under MIT license
 *
 * Copyright (c) 2017 Vistar project <https://github.com/vistarsvo/>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 */

namespace vistarsvo\proxydataprovider;

use yii\base\Component;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;


/**
 * Class ProxyCustomer
 * @package vistarsvo\proxydataprivider
 */
class ProxyDataProvider extends Component
{
    // Proxymanager type values.
    public static $TYPE_HTTP = 1;
    public static $TYPE_HTTPS = 2;
    public static $TYPE_SOCKS4 = 3;
    public static $TYPE_SOCKS5 = 4;

    // Proxymanager anonymous values
    public static $ANONYMOUS_TRANSPARENT = 1;
    public static $ANONYMOUS_MEDIUM = 2;
    public static $ANONYMOUS_HIGH = 3;
    public static $ANONYMOUS_ELITE = 4;
    public static $ANONYMOUS_PERSONAL = 5;

    // Model property names.
    /** @var string model primary key name. Only single integer values */
    public $modelId = 'id';
    /** @var string model ip property name*/
    public $modelIp = 'ip';
    /** @var string model port property name */
    public $modelPort = 'port';
    /** @var string model type property name (HTTP(S) and SOCKS) */
    public $modelType = 'type';
    /** @var string model country property name */
    public $modelCountry = 'country';
    /** @var string model anonymous type property name */
    public $modelAnonymous = 'anonymous';
    /** @var string model login property name */
    public $modelLogin = 'login';
    /** @var string model password property name */
    public $modelPassword = 'password';

    // Temporary memory for script
    /** @var int max random id's for remember */
    public $randomMemoryCount = 100;
    /** @var ActiveRecord proxy table model*/
    public $proxyModel;
    /** @var int last proxy id */
    private $rotateMemory = 0;
    /** @var array remember id's when random choice */
    private $randomCache = [];

    // Filter arrays
    /** @var array filter proxy by country(s)  */
    private $filterCountry = [];
    /** @var array filter proxy by type(s) like HTTP(s) or SOCKS(4/5) */
    private $filterType = [];
    /** @var array filter proxy by anonymous type(s) */
    private $filterAnonymous = [];
    /** @var ActiveRecord proxy model object */
    private $proxyData;

    /**
     * Get component class name
     * @return string
     */
    public function getName() : string
    {
        return self::className();
    }

    /**
     * Get random proxy, but not last(N) in cache memory
     * @return $this
     */
    public function getRandomProxy()
    {
        $query = $this->proxyModel::find();
        $query = $this->queryAndWhere($query);

        $max = $query->max($this->modelId);
        $min = $query->min($this->modelId);
        $counter = 1;
        do {
            $rand = rand($min, $max);

            $this->proxyData = $this->proxyModel::find();
            $this->proxyData = $this->queryAndWhere($this->proxyData);
            $this->proxyData = $this->proxyData->andWhere($this->modelId . ' >= :id', [':id' => $rand])
                ->andWhere('status' . ' = :status', [':status' => 1])
                ->andFilterWhere(['NOT IN', $this->modelId, $this->randomCache])
                ->limit(1)
                ->one();
            if ($this->proxyData) break;
            if ($counter > 3) $this->randomCache = [];
            $counter++;
        } while (true || $counter < 6);

        if ($this->proxyData) {
            if (!empty($this->proxyData->{$this->modelId}))  {
                $this->randomCache[] = $this->proxyData->{$this->modelId};
            }
        }
        return $this;
    }

    /**
     * Get next proxy after previous request with filter params.
     * If proxy not found, try to reset last rotation memory and try again one time
     * @return $this|ProxyDataProvider
     */
    public function getRotatedProxy()
    {
        $this->proxyData = $this->proxyModel::find();
        $this->proxyData = $this->queryAndWhere($this->proxyData);
        $this->proxyData = $this->proxyData->where(['>', $this->modelId, $this->rotateMemory])
        ->andWhere('status' . ' = :status', [':status' => 1])
        ->limit(1)
        ->one();


        if ($this->proxyData) {
            if (!empty($this->proxyData->{$this->modelId}))  {
                $this->rotateMemory = $this->proxyData->{$this->modelId};
            }
        } else {
            if ($this->rotateMemory > 0) {
                $this->rotateMemory = 0;
                return $this->getRotatedProxy();
            }
        }
        return $this;
    }

    /**
     * Get concrete proxy by id without any filters
     * @param int $id
     * @return $this
     */
    public function getConcreteProxyById(int $id)
    {
        $this->proxyData = $this->proxyModel::findOne([$this->modelId => $id]);
        return $this;
    }

    /**
     * Return proxy data in dsn string
     * @return string
     */
    public function toDsn() : string
    {
        if ($this->proxyData) {
            try {
                $this->check($this->proxyData);
            } catch (\Exception $exception) {
                return '';
            }
            $dsn = 'tcp://';
            // Auth if need
            if (!empty($this->proxyData->{$this->modelLogin}) && !empty($this->proxyData->{$this->modelPassword})) {
                $dsn .= $this->proxyData->{$this->modelLogin} . ':' . $this->proxyData->{$this->modelPassword} . '@';
            }
            $dsn .= $this->proxyData->{$this->modelIp};
            $dsn .= ':';
            $dsn .= $this->proxyData->{$this->modelPort};
            return $dsn;
        } else {
            return '';
        }
    }

    /**
     * Return proxy data as array
     * @return array
     */
    public function toArray() : array
    {
        if ($this->proxyData) {
            try {
                $this->check($this->proxyData);
            } catch (\Exception $exception) {
                return [];
            }
            $return = [];
            if (!empty($this->modelId)) $return[$this->modelId] = $this->proxyData->{$this->modelId};
            if (!empty($this->modelIp)) $return[$this->modelIp] = $this->proxyData->{$this->modelIp};
            if (!empty($this->modelPort)) $return[$this->modelPort] = $this->proxyData->{$this->modelPort};
            if (!empty($this->modelType)) $return[$this->modelType] = $this->proxyData->{$this->modelType};
            if (!empty($this->modelCountry)) $return[$this->modelCountry] = $this->proxyData->{$this->modelCountry};
            if (!empty($this->modelAnonymous)) $return[$this->modelAnonymous] = $this->proxyData->{$this->modelAnonymous};
            if (!empty($this->modelLogin)) $return[$this->modelLogin] = $this->proxyData->{$this->modelLogin};
            if (!empty($this->modelPassword)) $return[$this->modelPassword] = $this->proxyData->{$this->modelPassword};
            return $return;
        } else {
            return [];
        }
    }

    /**
     * Return raw model data
     * @return ActiveRecord
     */
    public function raw()
    {
        return $this->proxyData;
    }

    /**
     * Set filter Country
     * @param mixed array|string $country
     * @return $this
     */
    public function setFilterCountry($country)
    {
        if (is_string($country)) {
            $country = [$country];
        } elseif (!is_array($country)) {
            $country = [];
        }

        if ($this->filterCountry != $country) {
            $this->resetMemory();
        }

        $this->filterCountry = $country;
        return $this;
    }

    /**
     * Set filter Type
     * @param $type
     * @return $this
     */
    public function setFilterType($type)
    {
        if (is_string($type)) {
            $type = [$type];
        } elseif (!is_array($type)) {
            $type = [];
        }

        if ($this->filterType != $type) {
            $this->resetMemory();
        }

        $this->filterType = $type;
        return $this;
    }

    /**
     * Set filter Anonymous
     * @param $anonymous
     * @return $this
     */
    public function setFilterAnonymous($anonymous)
    {
        if (is_string($anonymous)) {
            $anonymous = [$anonymous];
        } elseif (!is_array($anonymous)) {
            $anonymous = [];
        }

        if ($this->filterAnonymous != $anonymous) {
            $this->resetMemory();
        }

        $this->filterAnonymous = $anonymous;
        return $this;
    }

    /**
     * Check returned proxy data
     * @param $proxy
     * @throws \Exception
     */
    private function check($proxy) {
        if (   empty($proxy->{$this->modelId})
            || empty($proxy->{$this->modelIp})
            || empty($proxy->{$this->modelPort})
            || empty($proxy->{$this->modelType})
        ) {
            throw new \Exception('Incorrect proxy format');
        }
    }

    /**
     * Add where rules for ActiveQuery
     * @param $query ActiveQuery
     * @return mixed
     */
    private function queryAndWhere(ActiveQuery $query)
    {
        // Set country filter
        if (is_array($this->filterCountry) && count($this->filterCountry) > 0 && !empty($this->modelCountry)) {
            $query->andFilterWhere(['IN', $this->modelCountry, $this->filterCountry]);
        }

        // Set type filter
        if (is_array($this->filterType) && count($this->filterType) > 0 && !empty($this->modelType)) {
            $filterType = [];
            foreach ($this->filterType AS $type) {
                switch ($type) {
                    case self::$TYPE_HTTP:
                        $filterType[] = 'HTTP';
                        break;
                    case self::$TYPE_HTTPS:
                        $filterType[] = 'HTTPS';
                        break;
                    case self::$TYPE_SOCKS4:
                        $filterType[] = 'SOCKS4';
                        break;
                    case self::$TYPE_SOCKS5:
                        $filterType[] = 'SOCKS5';
                        break;
                }
            }
            $query->andFilterWhere(['IN', $this->modelType, $filterType]);
        }

        // Set anonymous filter
        if (is_array($this->filterAnonymous) && count($this->filterAnonymous) > 0 && !empty($this->modelAnonymous)) {
            $filterAnonymous = [];
            foreach ($this->filterAnonymous AS $anonymous) {
                switch ($anonymous) {
                    case self::$ANONYMOUS_TRANSPARENT:
                        $filterAnonymous[] = 'transparent';
                        $filterAnonymous[] = 'none';
                        break;
                    case self::$ANONYMOUS_MEDIUM:
                        $filterAnonymous[] = 'medium';
                        break;
                    case self::$ANONYMOUS_HIGH:
                        $filterAnonymous[] = 'high';
                        break;
                    case self::$ANONYMOUS_ELITE:
                        $filterAnonymous[] = 'elite';
                        $filterAnonymous[] = 'elite proxy';
                        break;
                    case self::$ANONYMOUS_PERSONAL:
                        $filterAnonymous[] = 'personal';
                        break;
                }
            }
            $query->andFilterWhere(['IN', $this->modelAnonymous, $filterAnonymous]);
        }
        return $query;
    }

    /**
     * Reset only memory random cache and rotate
     */
    private function resetMemory()
    {
        $this->rotateMemory = 0;
        $this->randomCache = [];
    }

    /**
     * Reset all filters and memory
     * @return $this
     */
    public function reset()
    {
        $this->filterType = [];
        $this->filterCountry = [];
        $this->filterAnonymous = [];
        $this->randomCache = [];
        $this->rotateMemory = 0;
        return $this;
    }
}