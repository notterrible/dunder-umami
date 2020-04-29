<?php

namespace Drupal\autoban_advban;

use Drupal\autoban\AutobanProviderInterface;
use Drupal\advban\AdvbanIpManager;
use Drupal\Core\Database\Connection;

/**
 * IP manager class for Advanced Ban (range) module.
 */
class AdvbanRangeProvider implements AutobanProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function getId() {
    return 'advban_range';
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return 'Advanced Ban (range)';
  }

  /**
   * {@inheritdoc}
   */
  public function getBanType() {
    return 'range';
  }

  /**
   * {@inheritdoc}
   */
  public function getBanIpManager(Connection $connection) {
    return new AdvbanIpManager($connection);
  }

}
