<?php

namespace Drupal\homepage\Controller;

use Drupal\Core\Controller\ControllerBase;

class HomepageController extends ControllerBase {

  public function index() {
    return [
      '#theme' => 'homepage',
    ];
  }

}
