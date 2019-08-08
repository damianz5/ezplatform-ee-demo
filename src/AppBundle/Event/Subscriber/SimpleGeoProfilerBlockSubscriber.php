<?php

/**
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace AppBundle\Event\Subscriber;

use AppBundle\Event\AbstractBlockEvent;
use AppBundle\Helper\ContentHelper;
use AppBundle\Helper\LocationHelper;
use eZ\Publish\API\Repository\Values\Content\Query;
use eZ\Publish\Core\QueryType\QueryType;
use EzSystems\EzPlatformPageFieldType\Event\BlockResponseEvent;
use EzSystems\EzPlatformPageFieldType\Event\BlockResponseEvents;
use EzSystems\EzPlatformPageFieldType\FieldType\Page\Block\Renderer\BlockRenderEvents;
use EzSystems\EzPlatformPageFieldType\FieldType\Page\Block\Renderer\Event\PreRenderEvent;
use EzSystems\PlatformHttpCacheBundle\Handler\TagHandler;
use Netgen\TagsBundle\Core\Repository\TagsService as TagsServiceInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Netgen\TagsBundle\API\Repository\Values\Content\Query as TagQuery;

class SimpleGeoProfilerBlockSubscriber extends AbstractBlockEvent implements EventSubscriberInterface
{
    /** @var \Symfony\Component\HttpFoundation\RequestStack */
    private $requestStack;

    /** @var \Netgen\TagsBundle\Core\Repository\TagsService  */
    private $tagsService;

    private const BLOCK_IDENTIFIER = 'simple_geo_profiler';

    public function __construct(
        RequestStack $requestStack,
        TagsServiceInterface $tagsService,
        QueryType $queryType,
        LocationHelper $locationHelper,
        ContentHelper $contentHelper,
        TagHandler $tagHandler
    ) {

        parent::__construct($locationHelper, $contentHelper, $queryType, $tagHandler);

        $this->requestStack = $requestStack;
        $this->tagsService = $tagsService;
        $this->queryType = $queryType;
    }

    /**
     * @return array The event names to listen to
     */
    public static function getSubscribedEvents(): array
    {
        return [
            BlockRenderEvents::getBlockPreRenderEventName(self::BLOCK_IDENTIFIER) => 'onBlockPreRender',
            BlockResponseEvents::getBlockResponseEventName(self::BLOCK_IDENTIFIER) => 'onBlockResponse',
        ];
    }

    /**
     * @param \EzSystems\EzPlatformPageFieldType\FieldType\Page\Block\Renderer\Event\PreRenderEvent $event
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException
     */
    public function onBlockPreRender(PreRenderEvent $event): void
    {
        $renderRequest = $event->getRenderRequest();
        $blockValue = $event->getBlockValue();

        $clientIp = $this->requestStack->getMasterRequest()->getClientIp();
        // brooklyn ip
        //$clientIp = '161.185.160.93';

        // polish ip
        //$clientIp = '78.10.182.168';

        try {
            $geoData = json_decode(file_get_contents('https://geoip-db.com/json/' . $clientIp), true);
        } catch (\Exception $e) {
            // todo: log - unable to fetch client data
            return;
        }

        $tags = false;

        if (!empty($geoData['city']) && $geoData['city'] !== 'Not found') {
            $tags = $this->tagsService->searchTags($geoData['city'], '');
        }

        if ($tags->totalCount === 0 && !empty($geoData['state']) && $geoData['state'] !== 'Not found') {
            $tags = $this->tagsService->searchTags($geoData['state'], '');
        }

        if ($tags->totalCount === 0 && !empty($geoData['country_name']) && $geoData['country_name'] !== 'Not found') {
            $tags = $this->tagsService->searchTags($geoData['country_name'], '');
        }

        if (!$tags) {
            // todo: log - unable to match tags with geo data
            return;
        }

        $tags = array_map(function($item) {
            return $item->getKeyword();
        }, $tags->tags);


        $tagsCriterion = array_map(function($item) {
            return new TagQuery\Criterion\TagKeyword(Query\Criterion\Operator::LIKE, $item);
        }, $tags);


        $location = $this->locationHelper->loadLocationByContentId((int) $blockValue->getAttribute('contentId')->getValue());

        $query = $this->queryType->getQuery([
            'contentTypeIdentifier' => ['place', 'article'],
            'limit' => 1,
            'additionalConditions' => [
                new Query\Criterion\Subtree($location->pathString),
                new Query\Criterion\LogicalOr($tagsCriterion)
            ]
        ]);

        $result = $this->contentHelper->findContentItems($query);

        if (empty($result)) {
            // TODO : render default item here, add it first to the block as default attribute -> embed single
            return;
        }

        $renderRequest->setParameters([
            'contentId' => $result[0]->id,
            'locationId' => $result[0]->versionInfo->contentInfo->mainLocationId,
        ]);
    }

    /**
     * @param \EzSystems\EzPlatformPageFieldType\Event\BlockResponseEvent $event
     */
    public function onBlockResponse(BlockResponseEvent $event): void
    {
        $response = $event->getResponse();
        $response->setPrivate();
    }
}
