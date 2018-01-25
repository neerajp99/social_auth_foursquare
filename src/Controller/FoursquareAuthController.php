<?php

namespace Drupal\social_auth_foursquare\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\social_api\Plugin\NetworkManager;
use Drupal\social_auth\SocialAuthDataHandler;
use Drupal\social_auth\SocialAuthUserManager;
use Drupal\social_auth_foursquare\FoursquareAuthManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Returns responses for Simple Foursquare Connect module routes.
 */
class FoursquareAuthController extends ControllerBase {

  /**
   * The network plugin manager.
   *
   * @var \Drupal\social_api\Plugin\NetworkManager
   */
  private $networkManager;

  /**
   * The user manager.
   *
   * @var \Drupal\social_auth\SocialAuthUserManager
   */
  private $userManager;

  /**
   * The foursquare authentication manager.
   *
   * @var \Drupal\social_auth_foursquare\FoursquareAuthManager
   */
  private $foursquareManager;

  /**
   * Used to access GET parameters.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private $request;

  /**
   * The Social Auth Data Handler.
   *
   * @var \Drupal\social_auth\SocialAuthDataHandler
   */
  private $dataHandler;

  /**
   * FoursquareAuthController constructor.
   *
   * @param \Drupal\social_api\Plugin\NetworkManager $network_manager
   *   Used to get an instance of social_auth_foursquare network plugin.
   * @param \Drupal\social_auth\SocialAuthUserManager $user_manager
   *   Manages user login/registration.
   * @param \Drupal\social_auth_foursquare\FoursquareAuthManager $foursquare_manager
   *   Used to manage authentication methods.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request
   *   Used to access GET parameters.
   * @param \Drupal\social_auth\SocialAuthDataHandler $data_handler
   *   SocialAuthDataHandler object.
   */
  public function __construct(NetworkManager $network_manager,
                              SocialAuthUserManager $user_manager,
                              FoursquareAuthManager $foursquare_manager,
                              RequestStack $request,
                              SocialAuthDataHandler $data_handler) {

    $this->networkManager = $network_manager;
    $this->userManager = $user_manager;
    $this->foursquareManager = $foursquare_manager;
    $this->request = $request;
    $this->dataHandler = $data_handler;

    // Sets the plugin id.
    $this->userManager->setPluginId('social_auth_foursquare');

    // Sets the session keys to nullify if user could not logged in.
    $this->userManager->setSessionKeysToNullify(['access_token', 'oauth2state']);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.network.manager'),
      $container->get('social_auth.user_manager'),
      $container->get('social_auth_foursquare.manager'),
      $container->get('request_stack'),
      $container->get('social_auth.data_handler')
    );
  }

  /**
   * Response for path 'user/login/foursquare'.
   *
   * Redirects the user to Foursquare for authentication.
   */
  public function redirectToFoursquare() {
    /* @var \Stevenmaguire\OAuth2\Client\Provider\Foursquare false $foursquare */
    $foursquare = $this->networkManager->createInstance('social_auth_foursquare')->getSdk();

    // If foursquare client could not be obtained.
    if (!$foursquare) {
      drupal_set_message($this->t('Social Auth Foursquare not configured properly. Contact site administrator.'), 'error');
      return $this->redirect('user.login');
    }

    // Destination parameter specified in url.
    $destination = $this->request->getCurrentRequest()->get('destination');
    // If destination parameter is set, save it.
    if ($destination) {
      $this->userManager->setDestination($destination);
    }

    // Foursquare service was returned, inject it to $foursquareManager.
    $this->foursquareManager->setClient($foursquare);

    // Generates the URL where the user will be redirected for Foursquare login.
    // If the user did not have email permission granted on previous attempt,
    // we use the re-request URL requesting only the email address.
    $foursquare_login_url = $this->foursquareManager->getFoursquareLoginUrl();

    $state = $this->foursquareManager->getState();

    $this->dataHandler->set('oauth2state', $state);

    return new TrustedRedirectResponse($foursquare_login_url);
  }

  /**
   * Response for path 'user/login/foursquare/callback'.
   *
   * Foursquare returns the user here after user has authenticated in foursquare.
   */
  public function callback() {
    // Checks if user cancel login via Foursquare.
    $error = $this->request->getCurrentRequest()->get('error');
    if ($error == 'access_denied') {
      drupal_set_message($this->t('You could not be authenticated.'), 'error');
      return $this->redirect('user.login');
    }

    /* @var \Stevenmaguire\OAuth2\Client\Provider\Foursquare|false $foursquare */
    $foursquare = $this->networkManager->createInstance('social_auth_foursquare')->getSdk();

    // If Foursquare client could not be obtained.
    if (!$foursquare) {
      drupal_set_message($this->t('Social Auth Foursquare not configured properly. Contact site administrator.'), 'error');
      return $this->redirect('user.login');
    }

    $state = $this->dataHandler->get('oauth2state');

    // Retrieves $_GET['state'].
    $retrievedState = $this->request->getCurrentRequest()->query->get('state');
    if (empty($retrievedState) || ($retrievedState !== $state)) {
      $this->userManager->nullifySessionKeys();
      drupal_set_message($this->t('Foursquare login failed. Unvalid OAuth2 state.'), 'error');
      return $this->redirect('user.login');
    }

    // Saves access token to session.
    $this->dataHandler->set('access_token', $this->foursquareManager->getAccessToken());

    $this->foursquareManager->setClient($foursquare)->authenticate();

    // Gets user's info from Foursquare API.
    if (!$foursquare_profile = $this->foursquareManager->getUserInfo()) {
      drupal_set_message($this->t('Foursquare login failed, could not load Foursquare profile. Contact site administrator.'), 'error');
      return $this->redirect('user.login');
    }

    // Store the data mapped with data points define is
    // social_auth_foursquare settings.
    $data = [];

    if (!$this->userManager->checkIfUserExists($foursquare_profile->getId())) {
      $api_calls = explode(PHP_EOL, $this->foursquareManager->getApiCalls());

      // Iterate through api calls define in settings and try to retrieve them.
      foreach ($api_calls as $api_call) {

        $call = $this->foursquareManager->getExtraDetails($api_call);
        array_push($data, $call);
      }
    }
    // If user information could be retrieved.
    return $this->userManager->authenticateUser($foursquare_profile->getFirstName(), $foursquare_profile->getEmail(), $foursquare_profile->getId(), $this->foursquareManager->getAccessToken(), json_encode($data));
  }

}
