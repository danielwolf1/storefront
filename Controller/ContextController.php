<?php declare(strict_types=1);

namespace Shopware\Storefront\Controller;

use Shopware\Core\Framework\Routing\Annotation\Since;
use Shopware\Core\Framework\Routing\Exception\LanguageNotFoundException;
use Shopware\Core\Framework\Routing\Exception\MissingRequestParameterException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Framework\Routing\RequestTransformer;
use Shopware\Storefront\Framework\Routing\Router;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * @package storefront
 *
 * @Route(defaults={"_routeScope"={"storefront"}})
 *
 * @internal
 */
class ContextController extends StorefrontController
{
    private AbstractContextSwitchRoute $contextSwitchRoute;

    private RequestStack $requestStack;

    private RouterInterface $router;

    /**
     * @internal
     */
    public function __construct(
        AbstractContextSwitchRoute $contextSwitchRoute,
        RequestStack $requestStack,
        RouterInterface $router
    ) {
        $this->contextSwitchRoute = $contextSwitchRoute;
        $this->requestStack = $requestStack;
        $this->router = $router;
    }

    /**
     * @Since("6.0.0.0")
     * @Route("/checkout/configure", name="frontend.checkout.configure", methods={"POST"}, options={"seo"="false"}, defaults={"XmlHttpRequest": true})
     */
    public function configure(Request $request, RequestDataBag $data, SalesChannelContext $context): Response
    {
        $this->contextSwitchRoute->switchContext($data, $context);

        return $this->createActionResponse($request);
    }

    /**
     * @Since("6.0.0.0")
     * @Route("/checkout/language", name="frontend.checkout.switch-language", methods={"POST"})
     */
    public function switchLanguage(Request $request, SalesChannelContext $context): RedirectResponse
    {
        if (!$request->request->has('languageId')) {
            throw new MissingRequestParameterException('languageId');
        }

        $languageId = $request->request->get('languageId');

        try {
            $newTokenResponse = $this->contextSwitchRoute->switchContext(
                new RequestDataBag([SalesChannelContextService::LANGUAGE_ID => $languageId]),
                $context
            );
        } catch (ConstraintViolationException $e) {
            throw new LanguageNotFoundException($languageId);
        }

        $route = (string) $request->request->get('redirectTo', 'frontend.home.page');

        if (empty($route)) {
            $route = 'frontend.home.page';
        }

        $params = $request->request->get('redirectParameters', '[]');

        if (\is_string($params)) {
            $params = json_decode($params, true);
        }

        if ($newTokenResponse->getRedirectUrl() === null) {
            return $this->redirectToRoute($route, $params);
        }

        /*
         * possible domains
         *
         * http://shopware.de/de
         * http://shopware.de/en
         * http://shopware.de/fr
         *
         * http://shopware.fr
         * http://shopware.com
         * http://shopware.de
         *
         * http://color.com
         * http://farben.de
         * http://couleurs.fr
         *
         * http://localhost/development/public/de
         * http://localhost/development/public/en
         * http://localhost/development/public/fr
         * http://localhost/development/public/de-DE
         *
         * http://localhost:8080
         * http://localhost:8080/en
         * http://localhost:8080/fr
         * http://localhost:8080/de-DE
         */
        $parsedUrl = parse_url($newTokenResponse->getRedirectUrl());

        if (!$parsedUrl) {
            throw new LanguageNotFoundException($languageId);
        }

        $routerContext = $this->router->getContext();
        $routerContext->setHttpPort($parsedUrl['port'] ?? 80);
        $routerContext->setMethod('GET');
        $routerContext->setHost($parsedUrl['host']);
        $routerContext->setBaseUrl(rtrim($parsedUrl['path'] ?? '', '/'));

        if ($this->requestStack->getMainRequest()) {
            $this->requestStack->getMainRequest()
                ->attributes->set(RequestTransformer::SALES_CHANNEL_BASE_URL, '');
        }

        $url = $this->router->generate($route, $params, Router::ABSOLUTE_URL);

        return new RedirectResponse($url);
    }
}
