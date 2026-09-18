<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Response;

class CorsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly string $allowOrigin = '*'
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 9999],
            KernelEvents::RESPONSE => ['onKernelResponse', 9999],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Don't do anything if it's not the master request
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $method = $request->getRealMethod();

        // Handle preflight requests
        if ('OPTIONS' === $method) {
            $response = new Response();
            $this->addCorsHeaders($response, $request);
            $event->setResponse($response);
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        // Don't do anything if it's not the master request
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $request = $event->getRequest();

        $this->addCorsHeaders($response, $request);
    }

    private function addCorsHeaders(Response $response, $request): void
    {
        $origin = $request->headers->get('Origin');
        
        if ($this->allowOrigin === '*' || empty($this->allowOrigin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin ?: '*');
        } else {
            $allowedList = array_map('trim', explode(',', $this->allowOrigin));
            if ($origin && in_array($origin, $allowedList, true)) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
            } elseif (!empty($allowedList)) {
                $response->headers->set('Access-Control-Allow-Origin', $allowedList[0]);
            }
        }

        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-API-KEY');
        $response->headers->set('Access-Control-Max-Age', '3600');
    }
}
