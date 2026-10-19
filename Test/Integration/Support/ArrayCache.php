<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Integration\Support;

use Magento\Framework\App\CacheInterface;

/**
 * In-memory Magento cache; tags and lifetimes are ignored.
 */
class ArrayCache implements CacheInterface
{
    /** @var array<string,string> */
    private array $data = [];

    /**
     * @inheritDoc
     */
    public function getFrontend()
    {
        throw new \BadMethodCallException('ArrayCache has no cache frontend.');
    }

    /**
     * @inheritDoc
     */
    public function load($identifier)
    {
        return $this->data[$identifier] ?? false;
    }

    /**
     * @inheritDoc
     */
    public function save($data, $identifier, $tags = [], $lifeTime = null)
    {
        $this->data[$identifier] = (string)$data;

        return true;
    }

    /**
     * @inheritDoc
     */
    public function remove($identifier)
    {
        unset($this->data[$identifier]);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function clean($tags = [])
    {
        $this->data = [];

        return true;
    }
}
