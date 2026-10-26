<?php

declare(strict_types=1);

/*
 * Symfony: call init() at the top of public/index.php, before the kernel boots.
 * Symfony's ErrorHandler then replaces the global handlers, so also report from
 * a listener on kernel.exception (below) or with the Monolog handler.
 */

use NaijaCloud\Argus\Argus;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

// public/index.php
\NaijaCloud\Argus\init(['dsn' => $_SERVER['ARGUS_DSN'] ?? null]);

// src/EventListener/ArgusListener.php
final class ArgusListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        Argus::captureException($event->getThrowable(), [
            'transaction' => (string) $event->getRequest()->attributes->get('_route'),
        ]);
    }
}

// config/services.yaml
//   App\EventListener\ArgusListener:
//     tags: [{ name: kernel.event_listener, event: kernel.exception }]
//
// config/packages/monolog.yaml (optional, for logs)
//   monolog:
//     handlers:
//       argus: { type: service, id: NaijaCloud\Argus\Log\MonologHandler }
