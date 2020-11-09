<?php

declare(strict_types=1);

namespace In2code\In2publishCore\Features\ContextMenuPublishEntry\ContextMenu;

use In2code\In2publishCore\Domain\Model\RecordInterface;
use In2code\In2publishCore\Service\Permission\PermissionService;
use TYPO3\CMS\Backend\ContextMenu\ItemProviders\AbstractProvider;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\SignalSlot\Dispatcher;

class PublishItemProvider extends AbstractProvider
{
    protected $itemsConfiguration = [
        'publish' => [
            'label' => 'LLL:EXT:in2publish_core/Resources/Private/Language/Features/ContextMenuPublishEntry.xlf:publish_page',
            'iconIdentifier' => 'tx_in2publishcore_contextmenupublishentry_publish',
            'callbackAction' => 'publishRecord',
        ],
    ];

    protected $permissionService;

    public function __construct(string $table, string $identifier, string $context = '')
    {
        parent::__construct($table, $identifier, $context);
        $this->permissionService = GeneralUtility::makeInstance(PermissionService::class);
    }

    public function canHandle(): bool
    {
        return $this->table === 'pages';
    }

    public function getPriority(): int
    {
        return 43;
    }

    public function addItems(array $items): array
    {
        $signalSlotDispatcher = GeneralUtility::makeInstance(Dispatcher::class);
        if ($this->permissionService->isUserAllowedToPublish()) {
            $votes = $signalSlotDispatcher->dispatch(
                RecordInterface::class,
                'isPublishable',
                [['yes' => 0, 'no' => 0], $this->table, (int)$this->identifier]
            );
            if ($votes[0]['yes'] >= $votes[0]['no']) {
                return parent::addItems($items);
            }
        }
        return $items;
    }

    /**
     * @param string $itemName
     * @return array
     * @throws RouteNotFoundException
     */
    protected function getAdditionalAttributes(string $itemName): array
    {
        $attributes = [];
        if ($itemName === 'publish') {
            $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
            $publishUrl = (string)$uriBuilder->buildUriFromRoute(
                'ajax_in2publishcore_contextmenupublishentry_publish',
                ['id' => $this->identifier]
            );
            $attributes += [
                'data-publish-url' => $publishUrl,
                'data-callback-module' => 'TYPO3/CMS/In2publishCore/ContextMenuPublishEntry',
            ];
        }
        return $attributes;
    }
}
