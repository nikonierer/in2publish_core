<?php

declare(strict_types=1);

namespace In2code\In2publishCore\Features\ContextMenuPublishEntry\Controller;

use In2code\In2publishCore\Command\PublishTaskRunner\RunTasksInQueueCommand;
use In2code\In2publishCore\Communication\RemoteCommandExecution\RemoteCommandDispatcher;
use In2code\In2publishCore\Communication\RemoteCommandExecution\RemoteCommandRequest;
use In2code\In2publishCore\Domain\Repository\CommonRepository;
use In2code\In2publishCore\Service\Permission\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

use function json_encode;

class PublishPageAjaxController
{
    /** @var CommonRepository */
    protected $commonRepository;

    /** @var PermissionService */
    protected $permissionService;

    /** @var RemoteCommandDispatcher */
    protected $remoteCommandDispatcher;

    public function __construct(
        CommonRepository $commonRepository,
        PermissionService $permissionService,
        RemoteCommandDispatcher $remoteCommandDispatcher
    ) {
        $this->commonRepository = $commonRepository;
        $this->permissionService = $permissionService;
        $this->remoteCommandDispatcher = $remoteCommandDispatcher;
    }

    public function publishPage(ServerRequestInterface $request): ResponseInterface
    {
        $response = new Response();

        $page = $request->getQueryParams()['page'] ?? null;

        $content = [
            'success' => false,
            'label' => 'Unknown error',
            'lArgs' => [],
            'error' => true,
        ];

        if (!$this->permissionService->isUserAllowedToPublish()) {
            $content['label'] = 'context_menu_publish_entry.forbidden';
            $content['error'] = false;
        }

        if (null === $page) {
            $content['label'] = 'context_menu_publish_entry.missing_page';
        } else {
            try {
                $this->commonRepository->disablePageRecursion();
                $record = $this->commonRepository->findByIdentifier((int)$page, 'pages');

                if (null !== $record && $record->isPublishable()) {
                    $this->commonRepository->publishRecordRecursive($record);
                    $rceRequest = new RemoteCommandRequest(RunTasksInQueueCommand::IDENTIFIER);
                    $rceResponse = $this->remoteCommandDispatcher->dispatch($rceRequest);
                    if ($rceResponse->isSuccessful()) {
                        $content['success'] = true;
                        $content['error'] = false;
                        $content['label'] = 'context_menu_publish_entry.page_published';
                    } else {
                        $content['label'] = 'context_menu_publish_entry.publishing_error';
                    }
                    $content['lArgs'][] = BackendUtility::getRecordTitle('pages', $record->getLocalProperties());
                } else {
                    $content['error'] = false;
                    $content['label'] = 'context_menu_publish_entry.not_publishable';
                }
            } catch (Throwable $exception) {
                $content['label'] = (string)$exception;
            }
        }

        $lArgs = !empty($content['lArgs']) ? $content['lArgs'] : null;
        $content['message'] = LocalizationUtility::translate($content['label'], 'in2publish_core', $lArgs);
        $response->getBody()->write(json_encode($content));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
