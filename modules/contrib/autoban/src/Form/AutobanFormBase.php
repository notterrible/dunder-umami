<?php

namespace Drupal\autoban\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Database;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\autoban\Controller\AutobanController;
use Drupal\autoban\AutobanUtils;
use Drupal\Component\Utility\Html;

/**
 * @file
 * Class AutobanFormBase.
 *
 * @package Drupal\autoban\Form
 *
 * @ingroup autoban
 */

/**
 * Autoban base form for add and edit.
 */
class AutobanFormBase extends EntityForm {

  /**
   * Define AutobanFormBase class.
   */

  /**
   * Query factory variable.
   *
   * @var \Drupal\Core\Entity\Query\QueryFactory
   *   Define QueryFactory variable.
   */
  protected $entityQueryFactory;

  /**
   * Config factory variable.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * The autoban object.
   *
   * @var \Drupal\autoban\Controller\AutobanController
   */
  protected $autoban;

  /**
   * Construct the AutobanFormBase.
   *
   * @param \Drupal\Core\Entity\Query\QueryFactory $query_factory
   *   An entity query factory for the autoban entity type.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   Store ConfigFactoryInterface manager.
   * @param \Drupal\autoban\Controller\AutobanController $autoban
   *   Autoban object.
   */
  public function __construct(QueryFactory $query_factory, ConfigFactoryInterface $config, AutobanController $autoban) {
    $this->entityQueryFactory = $query_factory;
    $this->config = $config;
    $this->autoban = $autoban;
  }

  /**
   * Factory method for AutobanFormBase.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.query'),
      $container->get('config.factory'),
      $container->get('autoban')
    );
  }

  /**
   * Overrides Drupal\Core\Entity\EntityFormController::form().
   *
   * Builds the entity add/edit form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   An associative array containing the current state of the form.
   * @param string $rule
   *   Rule ID (optional).
   *
   * @return array
   *   An associative array containing the autoban add/edit form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $rule = NULL) {
    $form = parent::buildForm($form, $form_state);
    $params = $this->getRequest()->query->all();
    if ($rule) {
      $autoban = $this->entityTypeManager->getStorage('autoban')->load($rule);
      if (!$autoban) {
        throw new NotFoundHttpException();
      }
    }
    else {
      $autoban = $this->entity;
    }
    $controller = $this->autoban;
    $is_new = $autoban->isNew() || $rule;

    $rule_type_value = $is_new ? AutobanUtils::AUTOBAN_RULE_MANUAL : $autoban->rule_type;
    $form['rule_type'] = [
      '#type' => 'hidden',
      '#value' => $rule_type_value,
    ];

    if (!empty($rule_type_value)) {
      $form['rule_type_array'] = [
        '#markup' => $this->t('Rule type: @type', ['@type' => $controller->ruleTypeList($rule_type_value)]),
      ];
    }

    $form['id'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Id'),
      '#description' => $this->t('Unique rules name'),
      '#default_value' => $is_new ? $this->newDefaultId() : $autoban->id(),
      '#maxlength' => 64,
      '#machine_name' => [
        'exists' => [$this, 'exists'],
        'replace_pattern' => '([^a-z0-9_]+)|(^custom$)',
        'error' => $this->t('Must be unique, and can only contain lowercase letters, numbers, and underscores. Additionally, it can not be the reserved word "custom".'),
      ],
      '#disabled' => !$is_new,
    ];

    // Type current groups.
    $dblog_type_exclude = $this->config('autoban.settings')->get('autoban_dblog_type_exclude') ?: "autoban\ncron\nphp\nsystem\nuser";
    $query = Database::getConnection()->select('watchdog', 'log');
    $query->addField('log', 'type');
    $query->condition('type', explode("\n", $dblog_type_exclude), 'NOT IN');
    $query->orderBy('type');
    $all_groups = $query->execute()->fetchAllKeyed(0, 0);

    array_walk($all_groups, function (&$item, $key) {
      $item = "<span>$item</span>";
    });

    $default_type = $autoban->type;
    if (empty($default_type) && !empty($params['type'])) {
      $default_type = $params['type'];
    }

    $form['type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Type'),
      '#description' => implode(', ', $all_groups),
      '#maxlength' => 255,
      '#default_value' => $default_type,
      '#required' => TRUE,
    ];

    $default_message = $autoban->message;
    if (empty($default_message) && !empty($params['message'])) {
      $default_message = Html::decodeEntities($params['message']);
    }

    $query_mode = $this->config('autoban.settings')->get('autoban_query_mode') ?: 'like';
    $query_mode_message = strtoupper($query_mode);
    $use_wildcards = $this->config('autoban.settings')->get('autoban_use_wildcards') ?: FALSE;
    $use_wildcards_message = $use_wildcards ? $this->t('yes') : $this->t('no');

    $form['message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Message pattern'),
      '#description' => $this->t('Dblog message @query_mode pattern. Use delimiter "|" for multiple values. Use wildcards: @use_wildcards_message.', [
        '@query_mode' => $query_mode_message,
        '@use_wildcards_message' => $use_wildcards_message,
      ]),
      '#maxlength' => 255,
      '#default_value' => $default_message,
      '#required' => TRUE,
    ];

    $form['referer'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Referrer pattern'),
      '#description' => $this->t('URL referrer pattern.'),
      '#maxlength' => 255,
      '#default_value' => $autoban->referer,
      '#required' => FALSE,
    ];

    $thresholds_config = $this->config('autoban.settings')->get('autoban_thresholds');
    $thresholds = !empty($thresholds_config) ?
    explode("\n", $thresholds_config)
    : [1, 2, 3, 5, 10, 20, 50, 100];
    if (empty($autoban->threshold)) {
      $last_threshold = $this->config('autoban.settings')->get('autoban_threshold_last');
      if ($last_threshold && in_array($last_threshold, $thresholds)) {
        $autoban->threshold = $last_threshold;
      }
    }

    $form['threshold'] = [
      '#type' => 'select',
      '#title' => $this->t('Threshold'),
      '#description' => $this->t('The threshold number of the log entries.'),
      '#default_value' => $autoban->threshold,
      '#options' => array_combine($thresholds, $thresholds),
      '#required' => TRUE,
    ];

    $form['user_type'] = [
      '#type' => 'select',
      '#title' => $this->t('User type'),
      '#description' => $this->t('User type: anonymous, authenticated or any.'),
      '#default_value' => $autoban->user_type ?: 0,
      '#options' => $controller->userTypeList(),
      '#required' => TRUE,
    ];

    // Retrieve Ban manager list.
    $providers = [];
    $banManagerList = $controller->getBanProvidersList();
    if (!empty($banManagerList)) {
      foreach ($banManagerList as $id => $item) {
        $providers[$id] = $item['name'];
      }
    }
    else {
      $this->messenger()->addMessage(
        $this->t('List ban providers is empty. You have to enable at least one Autoban providers module.'),
        'warning'
      );
    }

    // For a single provider.
    if ($autoban->isNew() && count($providers) > 0) {
      if (count($providers) == 1) {
        $autoban->provider = array_keys($providers)[0];
      }
      else {
        $last_provider = $this->config('autoban.settings')->get('autoban_provider_last');
        if ($last_provider && isset($providers[$last_provider])) {
          $autoban->provider = $last_provider;
        }
      }
    }

    $form['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('IP ban provider'),
      '#default_value' => $autoban->provider,
      '#options' => $providers,
      '#required' => TRUE,
    ];

    $destination = $this->getDestinationArray();
    $cancel_url = !empty($destination['destination']) && Url::fromRoute('<current>')->toString() != $destination['destination'] ?
      Url::fromUserInput($destination['destination']) : Url::fromRoute('entity.autoban.list');
    $cancel_link = Link::fromTextAndUrl($this->t('Cancel'), $cancel_url)->toString();
    $form['actions']['cancel'] = [
      '#markup' => $cancel_link,
      '#weight' => 100,
    ];

    $form['#attached']['library'][] = 'autoban/form';

    return $form;
  }

  /**
   * Checks for an existing autoban rule.
   *
   * @param string|int $entity_id
   *   The entity ID.
   * @param array $element
   *   The form element.
   * @param Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return bool
   *   TRUE if this format already exists, FALSE otherwise.
   */
  public function exists($entity_id, array $element, FormStateInterface $form_state) {
    $query = $this->entityQueryFactory->get('autoban');
    $result = $query->condition('id', $element['#field_prefix'] . $entity_id)
      ->execute();

    return (bool) $result;
  }

  /**
   * Generate default ID for new item.
   *
   * @return string
   *   Default ID.
   */
  public function newDefaultId() {
    $query = $this->entityQueryFactory->get('autoban');
    $query->condition('rule_type', AutobanUtils::AUTOBAN_RULE_AUTO, '=');
    $cnt_auto = $query->count()->execute();
    $query = $this->entityQueryFactory->get('autoban');
    $cnt_total = $query->count()->execute();

    $next = $cnt_total - $cnt_auto + 1;
    $entity_id = "rule$next";
    $result = $query->condition('id', $entity_id)
      ->execute();
    $exists = (bool) $result;

    return $exists ? '' : $entity_id;
  }

  /**
   * Overrides Drupal\Core\Entity\EntityFormController::actions().
   *
   * To set the submit button text, we need to override actions().
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   An associative array containing the current state of the form.
   *
   * @return array
   *   An array of supported actions for the current entity form.
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);
    $actions['submit']['#value'] = $this->t('Save');
    return $actions;
  }

  /**
   * Overrides Drupal\Core\Entity\EntityFormController::save().
   *
   * Saves the entity. This is called after submit() has built the entity from
   * the form values. Do not override submit() as save() is the preferred
   * method for entity form controllers.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   An associative array containing the current state of the form.
   */
  public function save(array $form, FormStateInterface $form_state) {
    $last_threshold = $form_state->getValue('threshold');
    $last_provider = $form_state->getValue('provider');
    $this->config->getEditable('autoban.settings')
      ->set('autoban_threshold_last', $last_threshold)
      ->set('autoban_provider_last', $last_provider)
      ->save();

    $autoban = $this->getEntity();
    $status = $autoban->save();
    $url = $autoban->toUrl();
    $edit_link = Link::fromTextAndUrl($this->t('Edit'), $url)->toString();

    if ($status == SAVED_UPDATED) {
      // If we edited an existing entity...
      $this->messenger()->addMessage($this->t('Autoban rule %label has been updated.', ['%label' => $autoban->id()]));
      $this->logger('autoban')->notice('Autoban rule %label has been updated.', ['%label' => $autoban->id(), 'link' => $edit_link]);
    }
    else {
      // If we created a new entity...
      $this->messenger()->addMessage($this->t('Autoban rule %label has been added.', ['%label' => $autoban->id()]));
      $this->logger('autoban')->notice('Autoban rule %label has been added.', ['%label' => $autoban->id(), 'link' => $edit_link]);
    }

    // Redirect the user back to the listing route after the save operation.
    $form_state->setRedirect('entity.autoban.list');
  }

}
