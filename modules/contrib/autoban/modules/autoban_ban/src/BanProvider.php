<?php

namespace Drupal\autoban_ban;

use Drupal\autoban\AutobanProviderInterface;
use Drupal\ban\BanIpManager;
use Drupal\Core\Database\Connection;

/**
 * IP manager class for core Ban module.
 */
class BanProvider implements AutobanProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function getId() {
    return 'ban';
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return 'Core Ban';
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
    return new BanIpManager($connection);
  }

}
