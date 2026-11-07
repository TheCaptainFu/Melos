<?php

namespace Drupal\gg_members_app\EventSubscriber;

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
 * No public website: a visitor who isn't logged in is sent to the login
 * page from any page, except the few that must stay open.
 */
class LoginWall implements EventSubscriberInterface {

  /**
   * Routes anyone may open: logging in, a forgotten password, the files
   * Drupal builds for the pages, cron, and the member's QR card from the
   * email plus the link inside it.
   */
  const OPEN_ROUTES = [
    'user.login',
    'user.pass',
    'user.reset',
    'user.reset.login',
    'user.reset.form',
    'user.logout',
    'system.cron',
    'system.css_asset',
    'system.js_asset',
    'system.files',
    'image.style_public',
    'gg_members_attendance.card',
    'gg_members_attendance.card_qr',
    'gg_members_attendance.checkin',
  ];

  public function __construct(protected AccountInterface $currentUser) {}

  public static function getSubscribedEvents(): array {
    return [
      // Pages anyone could open (the website's own).
      KernelEvents::REQUEST => ['onRequest', 31],
      // Pages Drupal already refused or doesn't have: routing checks
      // access itself, before the request listener above. After Drupal
      // turns a missing node etc. into a 404 (75).
      KernelEvents::EXCEPTION => ['onException', 50],
    ];
  }

  public function onException(ExceptionEvent $event): void {
    $exception = $event->getThrowable();
    if ($exception instanceof HttpExceptionInterface && in_array($exception->getStatusCode(), [403, 404], TRUE)) {
      $this->wall($event);
    }
  }

  public function onRequest(RequestEvent $event): void {
    $this->wall($event);
  }

  /**
   * Sends a visitor who isn't logged in to the login page.
   */
  protected function wall(RequestEvent $event): void {
    if (!$event->isMainRequest() || $this->currentUser->isAuthenticated()) {
      return;
    }
    $request = $event->getRequest();
    if (in_array($request->attributes->get(RouteObjectInterface::ROUTE_NAME), self::OPEN_ROUTES, TRUE)) {
      return;
    }
    // Back to the page asked for after logging in, unless it was the
    // front page (then the members, see gg_members_app_user_login()).
    $options = ['absolute' => TRUE];
    $path = $request->getPathInfo();
    if ($path !== '/' && $request->isMethod('GET')) {
      $options['query']['destination'] = $path . ($request->getQueryString() ? '?' . $request->getQueryString() : '');
    }
    $response = new RedirectResponse(Url::fromRoute('user.login', [], $options)->toString());
    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
    $event->setResponse($response);
  }

}
