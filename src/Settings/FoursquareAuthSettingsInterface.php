<?php

namespace Drupal\social_auth_foursquare\Settings;

/**
 * Defines an interface for Social Auth Foursquare settings.
 */
interface FoursquareAuthSettingsInterface {

  /**
   * Gets the client ID.
   *
   * @return string
   *   The client ID.
   */
  public function getClientId();

  /**
   * Gets the client secret.
   *
   * @return string
   *   The client secret.
   */
  public function getClientSecret();

  /**
   * Gets the restricted domain.
   *
   * @return string
   *   The restricted domain.
   */

}
