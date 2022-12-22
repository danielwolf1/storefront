<?php declare(strict_types=1);

namespace Shopware\Storefront\Page\Navigation;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\PageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;

/**
 * @package storefront
 */
class NavigationPageLoadedEvent extends PageLoadedEvent
{
    /**
     * @var NavigationPage
     */
    protected $page;

    public function __construct(NavigationPage $page, SalesChannelContext $salesChannelContext, Request $request)
    {
        $this->page = $page;
        parent::__construct($salesChannelContext, $request);
    }

    public function getPage(): NavigationPage
    {
        return $this->page;
    }
}
