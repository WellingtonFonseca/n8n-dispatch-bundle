<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\N8nDispatchBundle\Service\DispatchListReader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * N8n Dispatch (main menu) > Disparos: the table of dispatches the three n8n
 * campaign actions made. Page size follows core's own convention, the
 * 'mautic.<sessionVar>.limit' session key the pagination helper's limit
 * selector writes.
 */
class DispatchController extends CommonController
{
    private const SESSION_VAR   = 'n8ndispatch.dispatch';
    private const DEFAULT_LIMIT = 30;

    public function indexAction(Request $request, DispatchListReader $reader, int $page = 1): Response
    {
        if (!$this->security->isGranted('plugin:plugins:manage')) {
            return $this->accessDenied();
        }

        $session = $request->getSession();
        $limit   = (int) $session->get('mautic.'.self::SESSION_VAR.'.limit', self::DEFAULT_LIMIT);
        $limit   = $limit > 0 ? $limit : self::DEFAULT_LIMIT;
        $total   = $reader->count();

        // A page past the end (rows gone, or the limit changed) goes back to the last one.
        $page = max(1, min($page, (int) ceil($total / $limit) ?: 1));

        return $this->delegateView([
            'viewParameters' => [
                'items'      => $reader->read($page, $limit),
                'totalItems' => $total,
                'page'       => $page,
                'limit'      => $limit,
                'tmpl'       => $request->get('tmpl', 'index'),
            ],
            'contentTemplate' => '@N8nDispatch/Dispatch/list.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_n8ndispatch.dispatch_index',
                'mauticContent' => 'n8ndispatch.dispatch',
                'route'         => $this->generateUrl('mautic_n8ndispatch.dispatch_index', ['page' => $page]),
            ],
        ]);
    }
}
