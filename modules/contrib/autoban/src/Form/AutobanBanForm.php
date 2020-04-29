<?php

namespace Drupal\autoban\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Entity\EntityTypeManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\autoban\Controller\AutobanController;

/**
 * Displays banned IP addresses.
 */
class AutobanBanForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The autoban object.
   *
   * @var \Drupal\autoban\Controller\AutobanController
   */
  protected $autoban;

  /**
   * Construct the AutobanFormBase.
   *
   * @param \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
   *   Entity type manager.
   * @param \Drupal\autoban\Controller\AutobanController $autoban
   *   Autoban object.
   */
  public function __construct(EntityTypeManager $entity_type_manager, AutobanController $autoban) {
    $this->entityTypeManager = $entity_type_manager;
    $this->autoban = $autoban;
  }

  /**
   * Factory method for AutobanFormBase.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('autoban')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'autoban_ban_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $rule = '') {
    if (!$rule) {
      $rules = $this->entityTypeManager->getStorage('autoban')->loadMultiple();
      if (empty($rules)) {
        $this->messenger()->addMessage($this->t('No rules for ban'), 'warning');
        return new RedirectResponse(Url::fromRoute('entity.autoban.list')->toString());
      }
    }

    $form['message'] = [
      '#markup' => $rule ?
      $this->t('The IP addresses for rule %rule will be banned.', ['%rule' => $rule])
      : $this->t('The IP addresses for all rules will be banned.'),
    ];
    $form['rule'] = [
      '#type' => 'hidden',
      '#value' => $rule,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $rule ? $this->t('Ban') : $this->t('Ban all'),
    ];

    $destination = $this->getDestinationArray();
    $cancel_url = !empty($destination['destination']) && Url::fromRoute('<current>')->toString() != $destination['destination'] ?
      Url::fromUserInput($destination['destination']) : Url::fromRoute('entity.autoban.list');
    $cancel_link = Link::fromTextAndUrl($this->t('Cancel'), $cancel_url)->toString();

    $form['actions']['cancel'] = [
      '#markup' => $cancel_link,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $rule = trim($form_state->getValue('rule'));
    if (empty($rule)) {
      $rules = $this->entityTypeManager->getStorage('autoban')->loadMultiple();
      if (empty($rules)) {
        return;
      }
      foreach (array_keys($rules) as $rule_id) {
        $operations[] = [
          '\Drupal\autoban\AutobanBatch::ipBan', [$rule_id],
        ];
      }

      $batch = [
        'title' => $this->t('IP ban'),
        'operations' => $operations,
        'finished' => '\Drupal\autoban\AutobanBatch::ipBanFinished',
        'file' => drupal_get_path('module', 'autoban') . '/AutobanBatch.php',
      ];

      batch_set($batch);
      $form_state->setRedirect('entity.autoban.list');
    }
    else {
      $controller = $this->autoban;
      $banned_ip = $controller->getBannedIp($rule);
      if (empty($banned_ip)) {
        $this->messenger()->addMessage($this->t('No banned IP addresses for rule %rule.', ['%rule' => $rule]), 'warning');
        return;
      }

      $banned = $controller->banIpList($banned_ip, $rule);
      if ($banned > 0) {
        $message = $this->t('The IP addresses for rule %rule has been banned. Count: %count', ['%rule' => $rule, '%count' => $banned]);
      }
      else {
        $message = $this->t('No banned IP addresses for rule %rule', ['%rule' => $rule]);
      }
      $this->messenger()->addMessage($message);
      $this->getLogger('autoban')->notice($message);
    }
  }

}
