<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Api\Controller;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Flow\Api\FlowActionCollector;
use Shopware\Core\Framework\Api\ApiDefinition\DefinitionService;
use Shopware\Core\Framework\Api\ApiDefinition\Generator\EntitySchemaGenerator;
use Shopware\Core\Framework\Api\ApiDefinition\Generator\OpenApi3Generator;
use Shopware\Core\Framework\Bundle;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\BusinessEventCollector;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Increment\Exception\IncrementGatewayNotFoundException;
use Shopware\Core\Framework\Increment\IncrementGatewayRegistry;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Routing\Annotation\Since;
use Shopware\Core\Framework\Routing\Exception\InvalidRequestParameterException;
use Shopware\Core\Kernel;
use Shopware\Core\Maintenance\System\Service\AppUrlVerifier;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Asset\PackageInterface;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route(defaults={"_routeScope"={"api"}})
 */
class InfoController extends AbstractController
{
    private DefinitionService $definitionService;

    private ParameterBagInterface $params;

    private Packages $packages;

    private Kernel $kernel;

    private bool $enableUrlFeature;

    /**
     * @var array{administration?: string}
     */
    private array $cspTemplates;

    private BusinessEventCollector $eventCollector;

    private ?FlowActionCollector $flowActionCollector;

    private IncrementGatewayRegistry $incrementGatewayRegistry;

    private Connection $connection;

    private AppUrlVerifier $appUrlVerifier;

    /**
     * @param array{administration?: string} $cspTemplates
     *
     * @internal
     */
    public function __construct(
        DefinitionService $definitionService,
        ParameterBagInterface $params,
        Kernel $kernel,
        Packages $packages,
        BusinessEventCollector $eventCollector,
        IncrementGatewayRegistry $incrementGatewayRegistry,
        Connection $connection,
        AppUrlVerifier $appUrlVerifier,
        ?FlowActionCollector $flowActionCollector = null,
        bool $enableUrlFeature = true,
        array $cspTemplates = []
    ) {
        $this->definitionService = $definitionService;
        $this->params = $params;
        $this->packages = $packages;
        $this->kernel = $kernel;
        $this->enableUrlFeature = $enableUrlFeature;
        $this->flowActionCollector = $flowActionCollector;
        $this->cspTemplates = $cspTemplates;
        $this->eventCollector = $eventCollector;
        $this->incrementGatewayRegistry = $incrementGatewayRegistry;
        $this->connection = $connection;
        $this->appUrlVerifier = $appUrlVerifier;
    }

    /**
     * @Since("6.0.0.0")
     * @Route("/api/_info/openapi3.json", defaults={"auth_required"="%shopware.api.api_browser.auth_required_str%"}, name="api.info.openapi3", methods={"GET"})
     */
    public function info(Request $request): JsonResponse
    {
        $apiType = $request->query->getAlpha('type', DefinitionService::TypeJsonApi);

        $apiType = $this->definitionService->toApiType($apiType);
        if ($apiType === null) {
            throw new InvalidRequestParameterException('type');
        }

        $data = $this->definitionService->generate(OpenApi3Generator::FORMAT, DefinitionService::API, $apiType);

        return new JsonResponse($data);
    }

    /**
     * @Since("6.4.6.0")
     * @Route("/api/_info/queue.json", name="api.info.queue", methods={"GET"})
     */
    public function queue(): JsonResponse
    {
        try {
            $gateway = $this->incrementGatewayRegistry->get(IncrementGatewayRegistry::MESSAGE_QUEUE_POOL);
        } catch (IncrementGatewayNotFoundException $exception) {
            // In case message_queue pool is disabled
            return new JsonResponse([]);
        }

        // Fetch unlimited message_queue_stats
        $entries = $gateway->list('message_queue_stats', -1);

        return new JsonResponse(array_map(function (array $entry) {
            return [
                'name' => $entry['key'],
                'size' => (int) $entry['count'],
            ];
        }, array_values($entries)));
    }

    /**
     * @Since("6.0.0.0")
     * @Route("/api/_info/open-api-schema.json", defaults={"auth_required"="%shopware.api.api_browser.auth_required_str%"}, name="api.info.open-api-schema", methods={"GET"})
     */
    public function openApiSchema(): JsonResponse
    {
        $data = $this->definitionService->getSchema(OpenApi3Generator::FORMAT, DefinitionService::API);

        return new JsonResponse($data);
    }

    /**
     * @Since("6.0.0.0")
     * @Route("/api/_info/entity-schema.json", name="api.info.entity-schema", methods={"GET"})
     */
    public function entitySchema(): JsonResponse
    {
        $data = $this->definitionService->getSchema(EntitySchemaGenerator::FORMAT, DefinitionService::API);

        return new JsonResponse($data);
    }

    /**
     * @Since("6.3.2.0")
     * @Route("/api/_info/events.json", name="api.info.business-events", methods={"GET"})
     */
    public function businessEvents(Context $context): JsonResponse
    {
        $events = $this->eventCollector->collect($context);

        return $this->json($events);
    }

    /**
     * @Since("6.0.0.0")
     * @Route("/api/_info/swagger.html", defaults={"auth_required"="%shopware.api.api_browser.auth_required_str%"}, name="api.info.swagger", methods={"GET"})
     */
    public function infoHtml(Request $request): Response
    {
        $nonce = $request->attributes->get(PlatformRequest::ATTRIBUTE_CSP_NONCE);
        $apiType = $request->query->getAlpha('type', DefinitionService::TypeJson);
        $response = $this->render(
            '@Framework/swagger.html.twig',
            [
                'schemaUrl' => 'api.info.openapi3',
                'cspNonce' => $nonce,
                'apiType' => $apiType,
            ]
        );

        $cspTemplate = $this->cspTemplates['administration'] ?? '';
        $cspTemplate = trim($cspTemplate);
        if ($cspTemplate !== '') {
            $csp = str_replace('%nonce%', $nonce, $cspTemplate);
            $csp = str_replace(["\n", "\r"], ' ', $csp);
            $response->headers->set('Content-Security-Policy', $csp);
        }

        return $response;
    }

    /**
     * @Since("6.0.0.0")
     * @Route("/api/_info/config", name="api.info.config", methods={"GET"})
     *
     * @deprecated tag:v6.5.0 $context param will be required
     * @deprecated tag:v6.5.0 $request param will be required
     */
    public function config(?Context $context = null, ?Request $request = null): JsonResponse
    {
        if (!$context) {
            Feature::triggerDeprecationOrThrow(
                'v6.5.0.0',
                'First parameter `$context` will be required in method `config()` in `InfoController` in v6.5.0.0'
            );

            $context = Context::createDefaultContext();
        }

        $appUrlReachable = true;
        if ($request) {
            $appUrlReachable = $this->appUrlVerifier->isAppUrlReachable($request);
        }

        return new JsonResponse([
            'version' => $this->params->get('kernel.shopware_version'),
            'versionRevision' => $this->params->get('kernel.shopware_version_revision'),
            'adminWorker' => [
                'enableAdminWorker' => $this->params->get('shopware.admin_worker.enable_admin_worker'),
                'transports' => $this->params->get('shopware.admin_worker.transports'),
            ],
            'bundles' => $this->getBundles($context),
            'settings' => [
                'enableUrlFeature' => $this->enableUrlFeature,
                'appUrlReachable' => $appUrlReachable,
                'appsRequireAppUrl' => $this->appUrlVerifier->hasAppsThatNeedAppUrl($context),
            ],
        ]);
    }

    /**
     * @Since("6.3.5.0")
     * @Route("/api/_info/version", name="api.info.shopware.version", methods={"GET"})
     * @Route("/api/v1/_info/version", name="api.info.shopware.version_old_version", methods={"GET"})
     */
    public function infoShopwareVersion(): JsonResponse
    {
        return new JsonResponse([
            'version' => $this->params->get('kernel.shopware_version'),
        ]);
    }

    /**
     * @Since("6.4.5.0")
     * @Route("/api/_info/flow-actions.json", name="api.info.actions", methods={"GET"})
     */
    public function flowActions(Context $context): JsonResponse
    {
        if (!$this->flowActionCollector) {
            return $this->json([]);
        }

        $events = $this->flowActionCollector->collect($context);

        return $this->json($events);
    }

    /**
     * @return array<string, array{type: 'plugin', css: string[], js: string[], baseUrl: ?string }|array{type: 'app', name: string, active: bool, integrationId: string, baseUrl: string, version: string, permissions: array<string,string[]>}>
     */
    private function getBundles(Context $context): array
    {
        $assets = [];
        $package = $this->packages->getPackage('asset');

        foreach ($this->kernel->getBundles() as $bundle) {
            if (!$bundle instanceof Bundle) {
                continue;
            }

            $bundleDirectoryName = preg_replace('/bundle$/', '', mb_strtolower($bundle->getName()));
            if ($bundleDirectoryName === null) {
                throw new \RuntimeException(sprintf('Unable to generate bundle directory for bundle "%s"', $bundle->getName()));
            }

            $styles = array_map(static function (string $filename) use ($package, $bundleDirectoryName) {
                $url = 'bundles/' . $bundleDirectoryName . '/' . $filename;

                return $package->getUrl($url);
            }, $this->getAdministrationStyles($bundle));

            $scripts = array_map(static function (string $filename) use ($package, $bundleDirectoryName) {
                $url = 'bundles/' . $bundleDirectoryName . '/' . $filename;

                return $package->getUrl($url);
            }, $this->getAdministrationScripts($bundle));

            $baseUrl = $this->getBaseUrl($bundle, $package, $bundleDirectoryName);

            if (empty($styles) && empty($scripts)) {
                if ($baseUrl === null) {
                    continue;
                }
            }

            $assets[$bundle->getName()] = [
                'css' => $styles,
                'js' => $scripts,
                'baseUrl' => $baseUrl,
                'type' => 'plugin',
            ];
        }

        foreach ($this->getActiveApps() as $app) {
            $assets[$app['name']] = [
                'active' => (bool) $app['active'],
                'integrationId' => $app['integrationId'],
                'type' => 'app',
                'baseUrl' => $app['baseUrl'],
                'permissions' => $app['privileges'],
                'version' => $app['version'],
                'name' => $app['name'],
            ];
        }

        return $assets;
    }

    /**
     * @return list<string>
     */
    private function getAdministrationStyles(Bundle $bundle): array
    {
        $path = 'administration/css/' . str_replace('_', '-', $bundle->getContainerPrefix()) . '.css';
        $bundlePath = $bundle->getPath();

        if (!file_exists($bundlePath . '/Resources/public/' . $path)) {
            return [];
        }

        return [$path];
    }

    /**
     * @return list<string>
     */
    private function getAdministrationScripts(Bundle $bundle): array
    {
        $path = 'administration/js/' . str_replace('_', '-', $bundle->getContainerPrefix()) . '.js';
        $bundlePath = $bundle->getPath();

        if (!file_exists($bundlePath . '/Resources/public/' . $path)) {
            return [];
        }

        return [$path];
    }

    private function getBaseUrl(Bundle $bundle, PackageInterface $package, string $bundleDirectoryName): ?string
    {
        if (!$bundle instanceof Plugin) {
            return null;
        }

        if ($bundle->getAdminBaseUrl()) {
            return $bundle->getAdminBaseUrl();
        }

        $defaultEntryFile = 'administration/index.html';
        $bundlePath = $bundle->getPath();

        if (!file_exists($bundlePath . '/Resources/public/' . $defaultEntryFile)) {
            return null;
        }

        $url = 'bundles/' . $bundleDirectoryName . '/' . $defaultEntryFile;

        return $package->getUrl($url);
    }

    /**
     * @return list<array{name: string, active: int, integrationId: string, baseUrl: string, version: string, privileges: array<string,list<string>>}>
     */
    private function getActiveApps(): array
    {
        /** @var list<array{name: string, active: int, integrationId: string, baseUrl: string, version: string, privileges: ?string}> $apps */
        $apps = $this->connection->fetchAllAssociative('SELECT
    app.name,
    app.active,
    LOWER(HEX(app.integration_id)) as integrationId,
    app.base_app_url as baseUrl,
    app.version,
    ar.privileges as privileges
FROM app
LEFT JOIN acl_role ar on app.acl_role_id = ar.id
WHERE app.active = 1 AND app.base_app_url is not null');

        return array_map(static function (array $item) {
            $privileges = $item['privileges'] ? json_decode($item['privileges'], true, 512, \JSON_THROW_ON_ERROR) : [];

            $item['privileges'] = [];

            foreach ($privileges as $privilege) {
                if (substr_count($privilege, ':') !== 1) {
                    $item['privileges']['additional'][] = $privilege;

                    continue;
                }

                [$entity, $key] = \explode(':', $privilege);
                $item['privileges'][$key][] = $entity;
            }

            return $item;
        }, $apps);
    }
}
