<?php

namespace Drupal\gg_members_attendance\EventSubscriber;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The door device's account always finds its way home: the site's front
 * page, its own user page, or any page it may not open lead to /eisodos.
 */
class DoorHome implements EventSubscriberInterface {

  public function __construct(protected AccountInterface $currentUser) {}

  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 31],
      KernelEvents::EXCEPTION => ['onException', 50],
    ];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || !gg_members_attendance_is_door($this->currentUser)) {
      return;
    }
    $request = $event->getRequest();
    $route = $request->attributes->get(RouteObjectInterface::ROUTE_NAME);
    if ($request->getPathInfo() === '/' || in_array($route, ['user.page', 'entity.user.canonical'], TRUE)) {
      $this->home($event);
    }
  }

  public function onException(ExceptionEvent $event): void {
    $exception = $event->getThrowable();
    if ($event->isMainRequest() && $exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 403 && gg_members_attendance_is_door($this->currentUser)) {
      $this->home($event);
    }
  }

  protected function home(RequestEvent $event): void {
    // Only a page being opened: a refused call from the scanner (no CSRF
    // token, …) must stay a refusal, not become the home page.
    $request = $event->getRequest();
    if (!$request->isMethod('GET') || $request->isXmlHttpRequest() || $request->getRequestFormat() !== 'html') {
      return;
    }
    $response = new RedirectResponse(Url::fromRoute('gg_members_attendance.door')->toString());
    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
    $event->setResponse($response);
  }

}
