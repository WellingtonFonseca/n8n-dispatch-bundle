<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\N8nDispatchBundle\Resolver\LocaleConventions;
use MauticPlugin\N8nDispatchBundle\Service\DispatchFilters;
use MauticPlugin\N8nDispatchBundle\Service\DispatchListReader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * N8n Dispatch (main menu) > Disparos: the table of dispatches the three n8n
 * campaign actions made. Page size follows core's own convention, the
 * 'mautic.<sessionVar>.limit' session key the pagination helper's limit
 * selector writes.
 */
class DispatchController extends CommonController
{
    /** Campaigns > Full: who sees the screen, its details and may resend (the menu in Config/config.php asks for the same). */
    public const PERMISSION = 'campaign:campaigns:full';

    private const SESSION_VAR   = 'n8ndispatch.dispatch';
    private const DEFAULT_LIMIT = 30;

    public function indexAction(Request $request, DispatchListReader $reader, CoreParametersHelper $params, TranslatorInterface $translator, int $page = 1): Response
    {
        if (!$this->security->isGranted(self::PERMISSION)) {
            return $this->accessDenied();
        }

        $session  = $request->getSession();
        $limit    = (int) $session->get('mautic.'.self::SESSION_VAR.'.limit', self::DEFAULT_LIMIT);
        $limit    = $limit > 0 ? $limit : self::DEFAULT_LIMIT;
        $timeZone = new \DateTimeZone((string) ($params->get('default_timezone') ?: 'UTC'));
        // Dates are typed in the system language's format (dd/mm/yyyy in pt_BR), like the rest of the screen.
        $dateFormat = LocaleConventions::dateFormat($translator->getLocale(), 'date');
        $filters    = DispatchFilters::fromQuery($request->query->all(), $timeZone, $dateFormat);
        $query      = $filters->toQuery($timeZone, $dateFormat);

        $result = $reader->search($filters, $page, $limit);

        // A page past the end (rows gone, the filters or the limit changed) goes back to the last one.
        $last = (int) ceil($result['total'] / $limit) ?: 1;
        if ($page > $last) {
            $page   = $last;
            $result = $reader->search($filters, $page, $limit);
        }

        return $this->delegateView([
            'viewParameters' => [
                'items'       => $result['items'],
                'totalItems'  => $result['total'],
                'capped'      => $result['capped'],
                'scanLimit'   => DispatchListReader::STATUS_SCAN_LIMIT,
                'page'        => max(1, $page),
                'limit'       => $limit,
                'tmpl'        => $request->get('tmpl', 'index'),
                'filters'     => $query,
                'dateFormat'  => $dateFormat,
                'hasFilters'  => !$filters->isEmpty(),
                'queryString' => [] === $query ? '' : '&'.http_build_query($query),
                'options'     => $reader->filterOptions(),
                'statuses'    => DispatchFilters::STATUSES,
            ],
            'contentTemplate' => '@N8nDispatch/Dispatch/list.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_n8ndispatch.dispatch_index',
                'mauticContent' => 'n8ndispatch.dispatch',
                'route'         => $this->generateUrl('mautic_n8ndispatch.dispatch_index', ['page' => max(1, $page)] + $query),
            ],
        ]);
    }

    /**
     * Body of the details modal: the dispatch's card, opened by
     * data-toggle="ajaxmodal" from the table's options menu.
     */
    public function detailsAction(DispatchListReader $reader, int $id): Response
    {
        if (!$this->security->isGranted(self::PERMISSION)) {
            return $this->accessDenied();
        }

        $dispatch = $reader->find($id);

        if (null === $dispatch) {
            return $this->notFound();
        }

        return $this->delegateView([
            'viewParameters'  => ['dispatch' => $dispatch],
            'contentTemplate' => '@N8nDispatch/Dispatch/details.html.twig',
            'passthroughVars' => [
                'route' => false,
            ],
        ]);
    }
}
