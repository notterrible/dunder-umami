<?php

namespace Drupal\autoban_advban;

use Drupal\autoban\AutobanProviderInterface;
use Drupal\advban\AdvbanIpManager;
use Drupal\Core\Database\Connection;

/**
 * IP manager class for core Ban module.
 */
class AdvbanProvider implements AutobanProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function getId() {
    return 'advban';
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return 'Advanced Ban';
  }

  /**
   * {@inheritdoc}
   */
  public function getBanType() {
    return 'single';
  }

  /**
   * {@inheritdoc}
   */
  public function getBanIpManager(Connection $connection) {
    return new AdvbanIpManager($connection);
  }

}
