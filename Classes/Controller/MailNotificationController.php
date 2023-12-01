<?php
namespace T3\PwComments\Controller;

/*  | This extension is made for TYPO3 CMS and is licensed
 *  | under GNU General Public License.
 *  |
 *  | (c) 2011-2022 Armin Vieweg <armin@v.ieweg.de>
 */
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use T3\PwComments\Utility\HashEncryptionUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\TypoScript\TemplateService;
use TYPO3\CMS\Core\TypoScript\TypoScriptService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;
use TYPO3\CMS\Extbase\Core\Bootstrap;
use TYPO3\CMS\Extbase\Mvc\Web\FrontendRequestHandler;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 * Called by middleware request FrontendHandler
 */
class MailNotificationController
{

    /**
     * Send mail
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return ResponseInterface
     * @throws \TYPO3\CMS\Core\Error\Http\ServiceUnavailableException
     * @throws \TYPO3\CMS\Core\Exception
     */
    public function sendMail(ServerRequestInterface $request, ResponseInterface $response)
    {
        $queryParams = $request->getQueryParams();
        $params = $queryParams['tx_pwcomments'] ?? [];
        $action = $params['action'];
        $hash = $params['hash'];
        $uid = (int) $params['uid'];
        $pid = (int) $params['pid'];

        if (!$action || !$uid || !$pid || !$hash) {
            throw new \InvalidArgumentException('Invalid arguments given.');
        }

        // Get comment row
        /** @var ConnectionPool $pool */
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $pool->getQueryBuilderForTable('tx_pwcomments_domain_model_comment');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('*')
            ->from('tx_pwcomments_domain_model_comment')
            ->where($queryBuilder->expr()->eq(
                'uid',
                $queryBuilder->createNamedParameter($uid, \PDO::PARAM_INT)
            ))
            ->execute()->fetch(\PDO::FETCH_ASSOC);


        // Check hash
        $valid = HashEncryptionUtility::validCommentMessageHash($hash, $row['message']);
        if (!$valid) {
            throw new \RuntimeException('Given hash not valid!');
        }
        // Send mail and respond
        if ($action === 'sendAuthorMailWhenCommentHasBeenApproved' && $row['hidden']) {
            $this->runExtbaseController(
                'PwComments',
                'Comment',
                'sendAuthorMailWhenCommentHasBeenApproved',
                'Pi2',
                ['_commentUid' => $uid, '_skipMakingSettingsRenderable' => true],
                $pid,
                $request
            );
            $statusCode = 200; // OK
        } else {
            $statusCode = 400; // Bad request
        }
        return (new Response())->withStatus($statusCode);
    }


    /**
     * Initializes and runs an extbase controller
     *
     * @param string $extensionName Name of extension, in UpperCamelCase
     * @param string $controller Name of controller, in UpperCamelCase
     * @param string $action Optional name of action, in lowerCamelCase
     * @param string $pluginName Optional name of plugin. Default is 'Pi1'
     * @param array $settings Optional array of settings to use in controller
     * @param int $pid Uid of current page
     * @param string $vendorName VendorName
     * @return string output of controller's action
     * @throws \TYPO3\CMS\Core\Error\Http\ServiceUnavailableException
     * @throws \TYPO3\CMS\Core\Exception
     */
    protected function runExtbaseController(
        $extensionName,
        $controller,
        $action = 'index',
        $pluginName = 'Pi1',
        $settings = [],
        $pid = 0,
        $vendorName = 'T3',
        ServerRequestInterface $request
    ) {
        /** @var TypoScriptService $typoScriptService */
        $typoScriptService = GeneralUtility::makeInstance(TypoScriptService::class);

        // Get plugin setup
        $typoScriptSetup = $this->getTypoScriptSetup($pid);
        $plugin = $typoScriptSetup['plugin.']['tx_pwcomments.'];

        // Get plugin setup: settings
        $pluginSetupSettings = $typoScriptService->convertTypoScriptArrayToPlainArray($plugin['settings.']);
        $extensionConfig = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
            \TYPO3\CMS\Core\Configuration\ExtensionConfiguration::class
        )->get('pw_comments');
        if (is_array($extensionConfig)) {
            $settings = array_merge($settings, $extensionConfig);
        }
        $pluginSetupSettings = array_merge($settings, $pluginSetupSettings);

        // Get plugin setup: persistence
        $pluginSetupPersistence = $typoScriptService->convertTypoScriptArrayToPlainArray($plugin['persistence.'] ?? []);

        // Get plugin setup: _LOCAL_LANG
        $pluginSetupLocalLang = [];
        if (isset($plugin['_LOCAL_LANG.']) && is_array($plugin['_LOCAL_LANG.'])) {
            $pluginSetupLocalLang = $typoScriptService->convertTypoScriptArrayToPlainArray($plugin['_LOCAL_LANG.']);
        }

        // Run bootstrap with configuration
        /** @var Bootstrap $bootstrap */
        $bootstrap = GeneralUtility::makeInstance(Bootstrap::class);
        if (!method_exists($bootstrap, 'setContentObjectRenderer')) {
            $bootstrap->cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        } else {
            $bootstrap->setContentObjectRenderer(GeneralUtility::makeInstance(ContentObjectRenderer::class));
        }
        $configuration = [
            'pluginName' => $pluginName,
            'extensionName' => $extensionName,
            'controller' => $controller,
            'vendorName' => $vendorName,
            'controllerConfiguration' => [$controller],
            'action' => $action,
            'mvc' => [
                'requestHandlers' => [
                    FrontendRequestHandler::class => FrontendRequestHandler::class
                ]
            ],
            'settings' => $pluginSetupSettings,
            'persistence' => $pluginSetupPersistence,
            '_LOCAL_LANG' => $pluginSetupLocalLang
        ];

        return $bootstrap->run('', $configuration, $request);
    }

    /**
     * Returns TypoScript Setup array from a given page id
     * Adoption of same method in BackendConfigurationManager
     *
     * @param int|null $pageId
     * @return array the raw TypoScript setup
     */
    protected function getTypoScriptSetup($pageId): array
    {
        /** @var TemplateService $template */
        $template = GeneralUtility::makeInstance(TemplateService::class);
        // do not log time-performance information
        $template->tt_track = false;
        // Explicitly trigger processing of extension static files
        $template->setProcessExtensionStatics(true);
        // Get the root line
        $rootline = [];
        if ($pageId > 0) {
            try {
                $rootline = GeneralUtility::makeInstance(RootlineUtility::class, $pageId)->get();
            } catch (\RuntimeException $e) {
                $rootline = [];
            }
        }
        // This generates the constants/config + hierarchy info for the template.
        $template->runThroughTemplates($rootline, 0);
        $template->generateConfig();
        return $template->setup;
    }
}
