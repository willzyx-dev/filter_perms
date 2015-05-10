<?php

/**
 * @file
 * Contains \Drupal\filter_perms\Form\PermissionsForm.
 */

namespace Drupal\filter_perms\Form;

use Drupal\Component\Utility\SafeMarkup;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\user\Form\UserPermissionsForm;
use Drupal\user\PermissionHandlerInterface;
use Drupal\user\RoleStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an enhanced user permissions administration form.
 */
class PermissionsForm extends UserPermissionsForm {

  /**
   * Indicates that all options should be user for filter.
   */
  const ALL_OPTIONS = '-1';

  /**
   * The expirable key value store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface
   */
  protected $keyValueExpirable;

  /**
   * Constructs a new PermissionsForm.
   *
   * @param \Drupal\user\PermissionHandlerInterface $permission_handler
   *   The permission handler.
   * @param \Drupal\user\RoleStorageInterface $role_storage
   *   The role storage.
   * @param \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface $key_value_expirable
   *   The key value expirable factory.
   */
  public function __construct(PermissionHandlerInterface $permission_handler, RoleStorageInterface $role_storage, KeyValueStoreExpirableInterface $key_value_expirable) {
    parent::__construct($permission_handler, $role_storage);

    $this->keyValueExpirable = $key_value_expirable;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('user.permissions'),
      $container->get('entity.manager')->getStorage('user_role'),
      $container->get('keyvalue.expirable')->get('filter_perms_list')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Render role/permission overview:
    $module_info = system_rebuild_module_data();
    $hide_descriptions = system_admin_compact_mode();

    $form['system_compact_link'] = array(
      '#id' => FALSE,
      '#type' => 'system_compact_link',
    );

    $permissions = $this->permissionHandler->getPermissions();

    $providers = array();
    foreach ($permissions as $permission) {
      $providers[$permission['provider']] = $permission['provider'];
    }

    $roles = $this->getRoles();

    $defined_roles = array();
    foreach ($roles as $role_name => $role) {
      $defined_roles[$role_name] = SafeMarkup::checkPlain($role->label());
    }

    $filter = $this->getFilterSettings();

    $form['filters'] = array(
      '#type' => 'details',
      '#title' => $this->t('Permission Filters'),
      '#open' => TRUE,
    );
    $form['filters']['container'] = array(
      '#type' => 'container',
      '#attributes' => array('class' => array('form--inline', 'clearfix')),
    );
    // Displays all user roles.
    $form['filters']['container']['roles'] = array(
      '#title' => $this->t('Roles to display'),
      '#type' => 'select',
      '#options' => array(self::ALL_OPTIONS => '--All Roles') + $defined_roles,
      '#default_value' => $filter['roles'],
      '#size' => 8,
      '#multiple' => TRUE,
    );
    // Displays all modules which define permissions.
    $form['filters']['container']['modules'] = array(
      '#title' => $this->t('Modules to display'),
      '#type' => 'select',
      '#options' => array(self::ALL_OPTIONS => '--All Modules') + $providers,
      '#default_value' => $filter['modules'],
      '#size' => 8,
      '#multiple' => TRUE,
    );
    $form['filters']['action'] = array('#type' => 'actions');
    $form['filters']['action']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Filter Permissions'),
      '#submit' => array('::submitFormFilter'),
    );

    $role_names = $role_permissions = $admin_roles = array();

    foreach ($roles as $role_name => $role) {
      if (in_array(self::ALL_OPTIONS, $filter['roles']) || in_array($role_name, $filter['roles'])) {
        // Retrieve role names for columns.
        $role_names[$role_name] = SafeMarkup::checkPlain($role->label());
        // Fetch permissions for the roles.
        $role_permissions[$role_name] = $role->getPermissions();
        $admin_roles[$role_name] = $role->isAdmin();
      }
    }

    // Store $role_names for use when saving the data.
    $form['role_names'] = array(
      '#type' => 'value',
      '#value' => $role_names,
    );

    $permissions_by_provider = array();
    foreach ($permissions as $permission_name => $permission) {
      if (in_array(self::ALL_OPTIONS, $filter['modules']) || in_array($permission['provider'], $filter['modules'])) {
        $permissions_by_provider[$permission['provider']][$permission_name] = $permission;
      }
    }

    $form['permissions'] = array(
      '#type' => 'table',
      '#header' => array($this->t('Permission')),
      '#id' => 'permissions',
      '#attributes' => ['class' => ['permissions']],
      '#sticky' => TRUE,
      '#empty' => $this->t('Please select at least one value from both the Roles and Modules select boxes above and then click the "Filter Permissions" button.'),
    );

    // Only build the rest of the form if there are any filter settings.
    if (empty($role_names) || empty($permissions_by_provider)) {
      return $form;
    }

    foreach ($role_names as $name) {
      $form['permissions']['#header'][] = array(
        'data' => $name,
        'class' => array('checkbox'),
      );
    }

    foreach ($permissions_by_provider as $provider => $permissions) {
      // Module name.
      $form['permissions'][$provider] = array(array(
        '#wrapper_attributes' => array(
          'colspan' => count($role_names) + 1,
          'class' => array('module'),
          'id' => 'module-' . $provider,
        ),
        '#markup' => $module_info[$provider]->info['name'],
      ));
      foreach ($permissions as $perm => $perm_item) {
        // Fill in default values for the permission.
        $perm_item += array(
          'description' => '',
          'restrict access' => FALSE,
          'warning' => !empty($perm_item['restrict access']) ? $this->t('Warning: Give to trusted roles only; this permission has security implications.') : '',
        );
        $form['permissions'][$perm]['description'] = array(
          '#type' => 'inline_template',
          '#template' => '<div class="permission"><span class="title">{{ title }}</span>{% if description or warning %}<div class="description">{% if warning %}<em class="permission-warning">{{ warning }}</em> {% endif %}{{ description }}</div>{% endif %}</div>',
          '#context' => array(
            'title' => $perm_item['title'],
          ),
        );
        // Show the permission description.
        if (!$hide_descriptions) {
          $form['permissions'][$perm]['description']['#context']['description'] = $perm_item['description'];
          $form['permissions'][$perm]['description']['#context']['warning'] = $perm_item['warning'];
        }
        foreach ($role_names as $rid => $name) {
          $form['permissions'][$perm][$rid] = array(
            '#title' => $name . ': ' . $perm_item['title'],
            '#title_display' => 'invisible',
            '#wrapper_attributes' => array(
              'class' => array('checkbox'),
            ),
            '#type' => 'checkbox',
            '#default_value' => in_array($perm, $role_permissions[$rid]) ? 1 : 0,
            '#attributes' => array('class' => array('rid-' . $rid)),
            '#parents' => array($rid, $perm),
          );
          // Show a column of disabled but checked checkboxes.
          if ($admin_roles[$rid]) {
            $form['permissions'][$perm][$rid]['#disabled'] = TRUE;
            $form['permissions'][$perm][$rid]['#default_value'] = TRUE;
          }
        }
      }
    }

    $form['actions'] = array('#type' => 'actions');
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Save permissions'),
      '#button_type' => 'primary',
    );

    $form['#attached']['library'][] = 'user/drupal.user.permissions';

    return $form;
  }

  /**
   * Saves the roles and modules selection.
   */
  public function submitFormFilter(array &$form, FormStateInterface $form_state) {
    $this->saveFilterSettings($form_state->getValue('roles'), $form_state->getValue('modules'));
  }

  /**
   * Saves the filter settings for the current user.
   *
   * @param array $roles
   *   The roles to filter by.
   * @param array $modules
   *   The modules to filter by.
   */
  protected function saveFilterSettings(array $roles, array $modules) {
    $values = array('roles' => $roles, 'modules' => $modules);
    $this->keyValueExpirable->setWithExpire($this->currentUser()->id(), $values, 3600);
  }

  /**
   * Retrieve the filter settings for the current user.
   *
   * @return array
   *   The filter setting for the current user.
   */
  protected function getFilterSettings() {
    return $this->keyValueExpirable->get($this->currentUser()->id());
  }

} 