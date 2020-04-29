<?php

namespace Drupal\autoban\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\autoban\Controller\AutobanController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Configure autoban settings for this site.
 */
class AutobanSettingsForm extends ConfigFormBase {

  /**
   * The autoban object.
   *
   * @var \Drupal\autoban\Controller\AutobanController
   */
  protected $autoban;

  /**
   * Construct the AutobanSettingsForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\autoban\Controller\AutobanController $autoban
   *   Autoban object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, AutobanController $autoban) {
    parent::__construct($config_factory);
    $this->autoban = $autoban;
  }

  /**
   * Factory method for AutobanSettingsForm.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('autoban')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'autoban_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'autoban.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('autoban.settings');

    // Retrieve Ban manager list.
    $providers = [];
    $controller = $this->autoban;
    $banManagerList = $controller->getBanProvidersList();
    if (!empty($banManagerList)) {
      foreach ($banManagerList as $id => $item) {
        $providers[$id] = $item['name'];
      }
      $form['providers'] = [
        '#markup' => '<label>' . $this->t('Ban providers') . '</label> ' . implode(', ', $providers),
        '#allowed_tags' => ['label'],
      ];
    }
    else {
      $this->messenger()->addMessage(
        $this->t('List ban providers is empty. You have to enable at least one Autoban providers module.'),
        'warning'
      );
    }

    $thresholds = $config->get('autoban_thresholds') ?: "1\n2\n3\n5\n10\n20\n50\n100";
    $form['autoban_thresholds'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Thresholds'),
      '#default_value' => $thresholds,
      '#required' => TRUE,
      '#description' => $this->t('Thresholds set for Autoban rules threshold field.'),
    ];

    $query_mode = $config->get('autoban_query_mode') ?: 'like';
    $form['autoban_query_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Query mode'),
      '#options' => ['like' => 'LIKE', 'regexp' => 'REGEXP'],
      '#default_value' => $query_mode,
      '#description' => $this->t('Use REGEXP option if your SQL engine supports REGEXP syntax.'),
    ];

    $use_wildcards = $config->get('autoban_use_wildcards') ?: FALSE;
    $form['autoban_use_wildcards'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use wildcards'),
      '#default_value' => $use_wildcards,
      '#description' => $this->t('If not checked, Autoban will add % to begin and end of message patterns.'),
    ];

    $form['autoban_whitelist'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Whitelist'),
      '#default_value' => $config->get('autoban_whitelist'),
      '#description' => $this->t('Enter a list of IP addresses or domain. Format: CIDR "aa.bb.cc.dd/ee" or "aa.bb.cc.dd" or "googlebot.com". # symbol use as a comment.
        The rows beginning with # are comments and are ignored.
        For example: <a href="http://www.iplists.com/google.txt" rel="nofollow" target="_new">robot-whitelist site</a>.'),
      '#rows' => 10,
      '#cols' => 30,
    ];

    $dblog_type_exclude = $config->get('autoban_dblog_type_exclude') ?: "autoban\ncron\nphp\nsystem\nuser";
    $form['autoban_dblog_type_exclude'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Exclude dblog types'),
      '#default_value' => $dblog_type_exclude,
      '#description' => $this->t('Exclude dblog types events for log analyze, autoban rules.'),
    ];

    $form['autoban_threshold_analyze'] = [
      '#type' => 'number',
      '#title' => $this->t("Analyze's form threshold"),
      '#default_value' => $config->get('autoban_threshold_analyze') ?: 5,
      '#description' => $this->t('Threshold for log analyze.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $thresholds = explode("\n", $form_state->getValue('autoban_thresholds'));
    foreach ($thresholds as $threshold) {
      $threshold = intval(trim($threshold));
      if (empty($threshold) || $threshold <= 0) {
        $form_state->setErrorByName('autoban_thresholds', $this->t('Threshold values must be a positive integer.'));
      }
    }

    $dblog_type_exclude = explode("\n", $form_state->getValue('autoban_dblog_type_exclude'));
    foreach ($dblog_type_exclude as $item) {
      $item = trim($item);
      if (empty($item)) {
        $form_state->setErrorByName('autoban_dblog_type_exclude', $this->t('Dblog type exclude item cannot be empty.'));
      }
    }

    if ($form_state->getValue('autoban_threshold_analyze') <= 0) {
      $form_state->setErrorByName('autoban_threshold_analyze', $this->t("Analyze's form threshold must be a positive integer."));
    }

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // To ensure that the threshold values are integers.
    $thresholds = explode("\n", $form_state->getValue('autoban_thresholds'));
    array_walk($thresholds, function (&$item, $key) {
      $item = (int) $item;
    });

    // To ensure that the dblog_type_exclude values was trimmed.
    $dblog_type_exclude = explode("\n", $form_state->getValue('autoban_dblog_type_exclude'));
    array_walk($dblog_type_exclude, function (&$item, $key) {
      $item = trim($item);
    });

    $this->configFactory->getEditable('autoban.settings')
      ->set('autoban_thresholds', implode("\n", $thresholds))
      ->set('autoban_query_mode', $form_state->getValue('autoban_query_mode'))
      ->set('autoban_use_wildcards', $form_state->getValue('autoban_use_wildcards'))
      ->set('autoban_whitelist', $form_state->getValue('autoban_whitelist'))
      ->set('autoban_dblog_type_exclude', implode("\n", $dblog_type_exclude))
      ->set('autoban_threshold_analyze', $form_state->getValue('autoban_threshold_analyze'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
