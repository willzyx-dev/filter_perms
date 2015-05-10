<?php

/**
 * @file
 * Contains \Drupal\filter_perms\Routing\RouteSubscriber.
 */

namespace Drupal\filter_perms\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  public function alterRoutes(RouteCollection $collection) {
    if ($route = $collection->get('user.admin_permissions')) {
      $route->setDefault('_form', '\Drupal\filter_perms\Form\PermissionsForm');
    }
    if ($route = $collection->get('entity.user_role.edit_permissions_form')) {
      $route->setDefault('_form', '\Drupal\filter_perms\Form\PermissionsRoleSpecificForm');
    }
  }

}
