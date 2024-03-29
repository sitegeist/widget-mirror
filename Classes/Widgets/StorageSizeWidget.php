<?php

namespace Sitegeist\WidgetMirror\Widgets;

use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface as Cache;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Dashboard\WidgetApi;
use TYPO3\CMS\Dashboard\Widgets\AdditionalCssInterface;
use TYPO3\CMS\Dashboard\Widgets\EventDataInterface;
use TYPO3\CMS\Dashboard\Widgets\JavaScriptInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;
use TYPO3\CMS\Fluid\View\StandaloneView;

class StorageSizeWidget implements WidgetInterface, EventDataInterface, AdditionalCssInterface, JavaScriptInterface
{
    private const DEFAULT_MAX_SIZE = 214748364800;
    private const CACHE_IDENTIFIER = 'default_storage_data';

    public function __construct(
        private Cache $cache,
        private WidgetConfigurationInterface $configuration,
        private StandaloneView $view,
        private readonly array $options = []
    )
    {}

    public function renderWidgetContent(): string
    {
        $this->view->setTemplate('Widget/StorageSizeWidget');
        $this->view->assignMultiple([
            'configuration' => $this->configuration,
        ]);

        return $this->view->render();
    }

    public function getEventData(): array
    {
        $languageServiceFactory = GeneralUtility::makeInstance(LanguageServiceFactory::class);
        $languageService = $languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER']);
        $storageData = $this->getDefaultStorageData();
        return [
            'graphConfig' => [
                'type' => 'pie',
                'options' => [
                    'maintainAspectRatio' => false,
                    'legend' => [
                        'display' => true,
                        'position' => 'bottom',
                    ],
                    'title' => [
                        'display' => true,
                        'text' => $storageData['name']
                    ],
                ],
                'data' => [
                    'labels' => [
                        $languageService->sL('LLL:EXT:widget_mirror/Resources/Private/Language/backend.xlf:widgets.storageSize.chart.used')
                            . ' ' . GeneralUtility::formatSize($storageData['bytesUsed'], '| kB| MB| GB| TB| PB| EB| ZB| YB'),
                        $languageService->sL('LLL:EXT:widget_mirror/Resources/Private/Language/backend.xlf:widgets.storageSize.chart.free')
                            . ' ' . GeneralUtility::formatSize($storageData['bytesFree'], '| kB| MB| GB| TB| PB| EB| ZB| YB'),
                    ],
                    'datasets' => [
                        [
                            'backgroundColor' => WidgetApi::getDefaultChartColors(),
                            'data' => [
                                number_format($storageData['usage'], 2, '.', ''),
                                number_format(100 - $storageData['usage'], 2, '.', ''),
                            ],
                        ],
                    ],
                ]
            ],
        ];
    }

    public function getCssFiles(): array
    {
        return ['EXT:dashboard/Resources/Public/Css/Contrib/chart.css'];
    }

    public function getJavaScriptModuleInstructions(): array
    {
        return [
            JavaScriptModuleInstruction::forRequireJS('TYPO3/CMS/Dashboard/Contrib/chartjs'),
            JavaScriptModuleInstruction::forRequireJS('TYPO3/CMS/Dashboard/ChartInitializer'),
        ];
    }

    protected function getDefaultStorageData()
    {
        if($data = $this->cache->get(self::CACHE_IDENTIFIER)) {
            return $data;
        }

        $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);
        $storage = $storageRepository->getDefaultStorage();
        $data = [
            'name' => $storage->getName(),
            'usage' => ''
        ];

        if (!empty($storage->getStorageRecord()['tx_widget_mirror_max_size'])) {
            $maxSize = GeneralUtility::getBytesFromSizeMeasurement(
                $storage->getStorageRecord()['tx_widget_mirror_max_size']
            );
        } else {
            $maxSize = self::DEFAULT_MAX_SIZE;
        }

        if ($storage->getDriverType() == 'Local' && !empty($maxSize)) {
            $path = Environment::getPublicPath() . $storage->getRootLevelFolder()->getPublicUrl();
            if (is_readable($path)) {
                $dirSize = $this->getDirSize($path);
                $data['bytesUsed'] = $dirSize;
                $data['bytesFree'] = max(0, $maxSize - $dirSize);
                $data['usage'] = min(100, $dirSize / $maxSize * 100);
            }
        }

        $this->cache->set(self::CACHE_IDENTIFIER, $data);
        return $data;
    }

    protected function getDirSize($path): int
    {
        $fio = popen('du -sh '.$path, 'r');
        $output = fgets($fio);
        pclose($fio);
        $size = preg_split('/\s/', $output)[0] ?? 0;
        return GeneralUtility::getBytesFromSizeMeasurement($size);
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}
