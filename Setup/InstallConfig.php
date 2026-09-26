<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Setup;

use DmLab\TypesenseIndexer\Model\ConnectionSettings;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Search\Setup\InstallConfigInterface;

/**
 * Persists Typesense engine + connection settings passed at `setup:install` time.
 *
 * `CompositeInstallConfig` dispatches here when `--search-engine=typesense`. The
 * hostname, port and index prefix live under `catalog/search/typesense_*` (like
 * OpenSearch's); the protocol is a Typesense-specific tuning under the module's own
 * section. We map install input keys to full paths and write them verbatim.
 *
 * The encrypted API key is intentionally not written here: it must go through the
 * `Encrypted` backend model, which install-time raw writes bypass. It is set from
 * admin after install.
 */
class InstallConfig implements InstallConfigInterface
{
    /** @var array<string,string> input option key ⇒ full config path */
    private array $configMapping;

    /**
     * @param WriterInterface $configWriter
     * @param array<string,string> $configMapping additional or overriding input-key ⇒ path entries
     */
    public function __construct(
        private readonly WriterInterface $configWriter,
        array $configMapping = []
    ) {
        $this->configMapping = array_merge(
            [
                'search-engine' => 'catalog/search/engine',
                'typesense-server-hostname' => ConnectionSettings::XML_PATH_SERVER_HOSTNAME,
                'typesense-server-port' => ConnectionSettings::XML_PATH_SERVER_PORT,
                'typesense-index-prefix' => ConnectionSettings::XML_PATH_INDEX_PREFIX,
                'typesense-protocol' => ConnectionSettings::XML_PATH_PROTOCOL,
            ],
            $configMapping
        );
    }

    /**
     * @inheritDoc
     *
     * @param array<string,mixed> $inputOptions
     */
    public function configure(array $inputOptions)
    {
        foreach ($inputOptions as $key => $value) {
            if ($value === null || !isset($this->configMapping[$key])) {
                continue;
            }
            $this->configWriter->save($this->configMapping[$key], (string)$value);
        }
    }
}
