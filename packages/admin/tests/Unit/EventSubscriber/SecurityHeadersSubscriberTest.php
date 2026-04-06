<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Tests\Unit\EventSubscriber;

use ArnaudMoncondhuy\SynapseAdmin\EventSubscriber\SecurityHeadersSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class SecurityHeadersSubscriberTest extends TestCase
{
    public function testSubscribesToKernelResponse(): void
    {
        $events = SecurityHeadersSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
    }

    public function testSetsHeadersForAdminPath(): void
    {
        $subscriber = new SecurityHeadersSubscriber();

        $request = Request::create('/synapse/admin/dashboard');
        $response = new Response();

        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $subscriber->onKernelResponse($event);

        $this->assertTrue($response->headers->has('Content-Security-Policy'));
        $this->assertTrue($response->headers->has('X-Content-Type-Options'));
        $this->assertTrue($response->headers->has('X-Frame-Options'));
        $this->assertTrue($response->headers->has('Referrer-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function testDoesNotSetHeadersForNonAdminPath(): void
    {
        $subscriber = new SecurityHeadersSubscriber();

        $request = Request::create('/api/chat');
        $response = new Response();

        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $subscriber->onKernelResponse($event);

        $this->assertFalse($response->headers->has('X-Frame-Options'));
    }

    public function testIgnoresSubRequests(): void
    {
        $subscriber = new SecurityHeadersSubscriber();

        $request = Request::create('/synapse/admin/dashboard');
        $response = new Response();

        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::SUB_REQUEST,
            $response,
        );

        $subscriber->onKernelResponse($event);

        $this->assertFalse($response->headers->has('X-Frame-Options'));
    }
}
