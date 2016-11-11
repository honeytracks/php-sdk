<?php
  /**
   *
   *
   * User: degola
   * Date: 11.11.16
   * Time: 10:34
   */

namespace HoneyTracks;

/**
 * HoneyTracks Customer Tracking Library for PHP 5.2 - 5.3
 *
 * Using the tracking needs a valid account at https://panel.honeytracks.com and the corresponding
 * product Api- and SecretKey
 *
 * @author Sebastian Lagemann <sl@honeytracks.com>
 * @version $Id: HoneyTracks_Tracker_Library.php 2493 2011-09-05 15:05:23Z slagemann $
 */
class TrackerLibrary {
  protected $ApiKey = null;
  protected $SecretKey = null;
  protected $DefaultData = array(
    'Language' => null,
    'ClientIP' => null,
    'Space' => null,
    'Version' => null,
    'UniqueCustomerIdentifier' => null,
    'MarketingIdentifier' => null,
    'Timestamp' => null
  );
  protected $RequiredValues = array(
    'Action',
    'Language',
    'Space',
    'UniqueCustomerIdentifier'
  );
  protected $Configuration = array(
    'TRACKING_URL' => 'http://tracker.honeytracks.com/?ApiKey=%1$s&s=%2$s',
    'NUMBER_OF_CALL_RETRIES' => 3,
    'USE_CURL' => false,
    'CURL_CURLOPT_TIMEOUT_MS' => 1000,
    'SEND_IMMEDIATELY' => true,
    'PACKETS_STORAGE_PATH' => null
  );

  protected static $SingletonInstance = null;

  protected static $IgnoredCustomers = array();
  protected $__HTTP_POST_QUEUE = array();
  protected $LastPOSTContent = null;

  private function __construct($DefaultData = array()) {
    if(is_array($DefaultData)) {
      foreach(array('ApiKey', 'SecretKey') AS $varname) {
        if(!key_exists($varname, $DefaultData))
          throw new TrackerLibraryException(1, $varname);
        $this->{$varname} = $DefaultData[$varname];
      }
      unset($DefaultData['SecretKey']);
      foreach(array_keys($this->DefaultData) AS $key) {
        if(isset($DefaultData[$key]))
          $this->DefaultData[$key] = $DefaultData[$key];
      }
    }

    $this->setClientIP();
  }

  /**
   * the destructor handle calls the HTTPQueue to send the collected data to the tracking servers
   *
   */
  public function __destruct() {
    $this->ExecuteHTTPQueue();
  }

  /**
   * avoid cloning objects to ensure that we've got really a singleton
   *
   */
  private function __clone() {}

  /**
   * returns an singleton instance of GameOptimizer_Library based on given configuration values
   *
   * @param array $config
   * @return TrackerLibrary
   * @throws TrackerLibraryException
   */
  public static function Factory($DefaultData = null, array $ConfigurationData = null) {
    if(is_null(self::$SingletonInstance)) {
      if(is_null($DefaultData))
        throw new TrackerLibraryException(2);

      self::$SingletonInstance = new self($DefaultData);
    }
    if(!is_null($ConfigurationData))
      self::$SingletonInstance->setConfigurationByArray($ConfigurationData);

    return self::$SingletonInstance;
  }

  /**
   * calls the factory method without returning the library
   *
   * @param array $DefaultData
   */
  public static function Setup(array $DefaultData, array $ConfigurationData = null) {
    $obj = self::Factory($DefaultData);

    if(!is_null($ConfigurationData))
      $obj->setConfigurationByArray($ConfigurationData);
  }

  /**
   * sets a default data value
   *
   * @param string $key
   * @param misc $value
   */
  public function setOption($key, $value) {
    $this->setOptions(array($key => $value));
  }
  /**
   * sets default data values by array
   *
   * @param $Options
   * @throws TrackerLibraryException
   */
  public function setOptions($Options) {
    if(!is_array($Options)) throw new TrackerLibraryException(4);
    foreach($Options AS $varName => $varValue) {
      if(substr($varName, 2) != '__' && key_exists($varName, $this->DefaultData))
        $this->DefaultData[$varName] = $varValue;
    }
  }

  /**
   * add a block for all further events for the specified Space and UniqueCustomerIdentifier
   * Events will be not added to the execution queue anymore
   *
   * @param $Space if null the currently configured value will be used
   * @param $UniqueCustomerIdentifier if null the currently configured value will be used
   * @return bool
   */
  public function addCustomerEventBlock($Space = null, $UniqueCustomerIdentifier = null) {
    if(is_null($Space)) $Space = $this->DefaultData['Space'];
    if(is_null($UniqueCustomerIdentifier)) $UniqueCustomerIdentifier = $this->DefaultData['UniqueCustomerIdentifier'];

    static::$IgnoredCustomers[sha1($Space.'::'.$UniqueCustomerIdentifier)] = true;
    return true;
  }

  /**
   * remove block for events for the specified Space and UniqueCustomerIdentifier
   *
   * @param $Space if null the currently configured value will be used
   * @param $UniqueCustomerIdentifier if null the currently configured value will be used
   * @return bool
   */
  public function deleteCustomerEventBlock($Space = null, $UniqueCustomerIdentifier = null) {
    if(is_null($Space)) $Space = $this->DefaultData['Space'];
    if(is_null($UniqueCustomerIdentifier)) $UniqueCustomerIdentifier = $this->DefaultData['UniqueCustomerIdentifier'];

    $key = sha1($Space.'::'.$UniqueCustomerIdentifier);
    if(isset(static::$IgnoredCustomers[$key])) {
      unset(static::$IgnoredCustomers[$key]);
      unset($key);
      return true;
    }
    unset($key);
    return false;
  }

  /**
   * start executing the http queue manually
   *
   * @return bool
   */
  public function Commit() {
    return $this->ExecuteHTTPQueue();
  }

  /**
   * define a path for storing packets if the transport to the tracking servers failed
   * the expected path have to be a writable directory
   *
   * @param string $path
   */
  public function setFailedTransportStoragePath($path) {
    $this->setConfiguration('PACKETS_STORAGE_PATH', $path);
  }

  /**
   * sets a configuration value
   * following configuration values are available:
   *
   * TRACKING_URL => http://tracker.honeytracks.com/?ApiKey=%1$s&s=%2$s,
   * NUMBER_OF_CALL_RETRIES => 3
   * USE_CURL => false
   * SEND_IMMEDIATELY => true
   * PACKETS_STORAGE_PATH => null
   *
   * @param $name
   * @param $value
   * @return bool
   */
  public function setConfiguration($name, $value) {
    if(key_exists($name, $this->Configuration)) {
      $this->Configuration[$name] = $value;
      return true;
    }
    return false;
  }

  public function setConfigurationByArray($ConfigurationData) {
    foreach($ConfigurationData AS $k => $v) {
      if(!$this->setConfiguration($k, $v))
        throw new TrackerLibraryException(6, $k);
    }
  }


  /**
   * create a tracking packet which have to send to the tracking server
   *
   * @param string $Action
   * @param array $Data
   */
  public function Track($Action, $Data = array()) {
    if(!is_array($Data))
      throw new TrackerLibraryException(3);

    $this->AddHTTPTrackingCall(array_merge(
      $this->DefaultData,
      array('Action' => $Action),
      $Data
    ));

    return true;
  }

  /**
   * tracks a user login
   *
   * @param array $Data for overwriting DefaultData values
   */
  public function TrackLogin($Data = array()) {
    return $this->Track('User::Login', $Data);
  }

  /**
   * tracks a user logout
   *
   * @param array $Data for overwriting DefaultData values
   */
  public function TrackLogout($Data = array()) {
    return $this->Track('User::Logout', $Data);
  }

  /**
   * tracks a user signup
   *
   * @param string $MarketingIdentifier defines the campaign from which the user came from, if array given the elements MarketingIdentifier and Keyword are expected
   * @param array $Data for overwriting DefaultData values
   */
  public function TrackSignup($MarketingIdentifier = '', $LandingPage = 'default', $Data = array()) {
    $Keyword = '';
    $AdInformations = array();
    if(is_array($MarketingIdentifier)) {
      if(isset($MarketingIdentifier['Keyword']))
        $Keyword = $MarketingIdentifier['Keyword'];

      foreach(array('PartnerName', 'CampaignName', 'AdName') AS $varName) {
        if(key_exists($varName, $MarketingIdentifier) && !empty($MarketingIdentifier[$varName]))
          $AdInformations[$varName] = $MarketingIdentifier[$varName];
      }

      $MarketingIdentifier = $MarketingIdentifier['MarketingIdentifier'];
    }

    if(!isset($Data['UniqueCustomerClickToken']) && ($cuct = $this->getUniqueCustomerClickToken()) !== false) {
      $Data['UniqueCustomerClickToken'] = $cuct;
    }
    return $this->Track('User::Signup', array_merge(array(
      'MarketingIdentifier' => $MarketingIdentifier,
      'Keyword' => $Keyword,
      'LandingPage' => $LandingPage,
      'IsFreeAction' => 'true'
    ), $AdInformations, $Data));
  }

  /**
   * tracks a user click and sets a cookie to the user to track only unique clicks
   *
   * @param string $MarketingIdentifier if array the elements MarketingIdentifier and Keyword are expected
   * @param bool $TrackOnlyUnique specify if the click should only tracked if the $MarketingIdentifier changes
   * @param int $ExpireDays number of days within the click tracking cookie will be available, used for make the TrackOnlyUnique-functionality available
   * @param array $Data
   */
  public function TrackClick($MarketingIdentifier, $LandingPage = 'default', $TrackOnlyUnique = true, $ExpireDays = 14, $CookieDomain = null, $Data = array()) {
    $Keyword = '';
    $AdInformations = array();
    if(is_array($MarketingIdentifier)) {
      if(isset($MarketingIdentifier['Keyword']))
        $Keyword = $MarketingIdentifier['Keyword'];

      foreach(array('PartnerName', 'CampaignName', 'AdName') AS $varName) {
        if(key_exists($varName, $MarketingIdentifier) && !empty($MarketingIdentifier[$varName]))
          $AdInformations[$varName] = $MarketingIdentifier[$varName];
      }

      $MarketingIdentifier = $MarketingIdentifier['MarketingIdentifier'];
    }

    $SetCookie = false;
    $CustomerToken = $this->getUniqueCustomerClickToken();
    if(!$CustomerToken) {
      $CustomerToken = $this->setUniqueCustomerClickToken($MarketingIdentifier, $ExpireDays, $CookieDomain);
      $SetCookie = true;
    }

    if($SetCookie === true && !headers_sent()) {
      $Salt = crc32(uniqid(mt_rand(), true));
      $CustomerToken = sha1(sha1($Salt).$MarketingIdentifier.str_rot13(md5($Salt)));
      setcookie(
        'HTCTR',
        base64_encode(serialize(array('T' => $MarketingIdentifier, 'S' => $Salt, 'C' => $CustomerToken))),
        time() + (86400 * $ExpireDays),
        '/',
        !is_null($CookieDomain)?$CookieDomain:null
      );
    }

    if($SetCookie === true || $TrackOnlyUnique === false)
      return $this->Track('User::Click', array_merge(array(
        'MarketingIdentifier' => $MarketingIdentifier,
        'Keyword' => $Keyword,
        'LandingPage' => $LandingPage,
        'UniqueCustomerClickToken' => $CustomerToken
      ), $AdInformations, $Data));
    return false;
  }

  /**
   * tracks a user click without setting a cookie and an available unique customer click token which is not an unique customer id after an user signup

   * @param string $UniqueCustomerClickToken
   * @param string/array $MarketingIdentifier if array the elements MarketingIdentifier and Keyword are expected
   * @param string $LandingPage
   * @param array $Data
   */
  public function TrackClickWithoutCookie($UniqueCustomerClickToken, $MarketingIdentifier, $LandingPage, $Data = array()) {
    $Keyword = '';
    if(is_array($MarketingIdentifier)) {
      if(isset($MarketingIdentifier['Keyword']))
        $Keyword = $MarketingIdentifier['Keyword'];

      $MarketingIdentifier = $MarketingIdentifier['MarketingIdentifier'];
    }
    return $this->Track('User::Click', array_merge(array(
        'MarketingIdentifier' => $MarketingIdentifier,
        'Keyword' => $Keyword,
        'LandingPage' => $LandingPage,
        'UniqueCustomerClickToken' => $UniqueCustomerClickToken)
      , $Data));
  }

  /**
   * tracks the purchase of virtual currency for the amount of virtual currency, revenue and payout
   *
   * @param float|array $VirtualCurrencyAmount (array must have Amount and Name as k/v-pair, e.g. array('Name' => 'Gold', 'Amount' => 100)
   * @param string $PaymentType
   * @param float $Revenue
   * @param string $RevenueCurrency
   * @param float $Payout
   * @param string $PayoutCurrency
   * @param bool $IsFreeAction
   * @param array $Data for overwriting DefaultData values
   */
  public function TrackVirtualCurrencyPurchase($VirtualCurrencyAmount, $PaymentType, $Revenue, $RevenueCurrency, $Payout, $PayoutCurrency = null, $IsFreeAction = false, $Data = array()) {
    if(is_array($VirtualCurrencyAmount) && !isset($Data['VirtualCurrencyName']) && isset($VirtualCurrencyAmount['Name'])) {
      $Data['VirtualCurrencyName'] = $VirtualCurrencyAmount['Name'];
      $VirtualCurrencyAmount = $VirtualCurrencyAmount['Amount'];
    } elseif(is_array($VirtualCurrencyAmount))
      $VirtualCurrencyAmount = $VirtualCurrencyAmount['Amount'];

    return $this->Track('VirtualCurrencies::Buy', array_merge(array(
      'VirtualCurrencyAmount' => floatval($VirtualCurrencyAmount),
      'Revenue' => floatval($Revenue),
      'RevenueCurrency' => $RevenueCurrency,
      'Payout' => floatval($Payout),
      'PayoutCurrency' => !is_null($PayoutCurrency)?$PayoutCurrency:$RevenueCurrency,
      'PaymentType' => $PaymentType,
      'IsFreeAction' => $IsFreeAction?'true':'false'
    ), $Data));
  }

  /**
   * tracks the chargebacks for virtual currency purchases, e.g. a creditcard chargeback
   *
   * @param float|array $VirtualCurrencyAmount (array must have Amount and Name as k/v-pair, e.g. array('Name' => 'Gold', 'Amount' => 100)
   * @param string $PaymentType
   * @param float $Revenue
   * @param string $RevenueCurrency
   * @param float $Payout
   * @param string $PayoutCurrency
   * @param array $Data for overwriting DefaultData values
   */
  public function TrackVirtualCurrencyChargeback($VirtualCurrencyAmount, $PaymentType, $Revenue, $RevenueCurrency, $Payout, $PayoutCurrency = null, $Data = array()) {
    if(is_array($VirtualCurrencyAmount) && !isset($Data['VirtualCurrencyName']) && isset($VirtualCurrencyAmount['Name'])) {
      $Data['VirtualCurrencyName'] = $VirtualCurrencyAmount['Name'];
      $VirtualCurrencyAmount = $VirtualCurrencyAmount['Amount'];
    } elseif(is_array($VirtualCurrencyAmount))
      $VirtualCurrencyAmount = $VirtualCurrencyAmount['Amount'];

    return $this->Track('VirtualCurrencies::Chargeback', array_merge(array(
      'Quantity' => floatval($VirtualCurrencyAmount),
      'Value' => floatval($Revenue),
      'Currency' => $RevenueCurrency,
      'Payout' => floatval($Payout),
      'PayoutCurrency' => !is_null($PayoutCurrency)?$PayoutCurrency:$RevenueCurrency,
      'PaymentType' => $PaymentType
    ), $Data));
  }

  /**
   * tracks the purchase of virtual good features for feature type, a possible feature sub type and the virtual currency amount
   *
   * @param string $FeatureType, e.g. Premium
   * @param string $FeatureSubType e.g. Package1, can be null if no sub type available
   * @param float|array $VirtualCurrencyAmount the virtual currency amount spent for the feature (array must have Amount and Name as k/v-pair, e.g. array('Name' => 'Gold', 'Amount' => 100)
   * @param misc $GameCurrency the game currency spent, can be an array if there are more than one game currency (e.g. resources in a strategy game)
   * @param int $Quantity the quantity
   * @param bool $IsFreeAction if the transaction is a decoy offer
   * @param array $Data for overwriting DefaultData values
   */
  public function TrackVirtualGoodsFeaturePurchase($FeatureType, $FeatureSubType, $VirtualCurrencyAmount, $GameCurrency = null, $Quantity = 1, $IsFreeAction = false, $Data = array()) {
    if(is_array($VirtualCurrencyAmount) && !isset($Data['VirtualCurrencyName']) && isset($VirtualCurrencyAmount['Name'])) {
      $Data['VirtualCurrencyName'] = $VirtualCurrencyAmount['Name'];
      $VirtualCurrencyAmount = $VirtualCurrencyAmount['Amount'];
    } elseif(is_array($VirtualCurrencyAmount))
      $VirtualCurrencyAmount = $VirtualCurrencyAmount['Amount'];

    return $this->Track('VirtualGoods::'.$FeatureType.(!is_null($FeatureSubType)?'::'.$FeatureSubType:''), array_merge(array(
      'Value' => $VirtualCurrencyAmount,
      'Quantity' => $Quantity,
      'GameCurrency' => json_encode($GameCurrency),
      'FeatureType' => $FeatureType,
      'FeatureSubType' => $FeatureSubType,
      'IsFreeAction' => $IsFreeAction?'true':'false'
    ), $Data));
  }

  /**
   * tracks the purchase of an item, e.g. for a sword, a pant but not limited to this kind of items
   *
   * @param string $ItemType
   * @param array $Item the item can be specified further:
   * 						- misc UniqueId contains the id of the item, e.g. Item1: sword of fear, Item2: pant of..., Item3. etc.
   * 						- string ImageUrl contains the url to the item image
   * 						- string Name contains the name or a localisation text key for the item
   * @param float|array $VirtualCurrencyAmount (array must have Amount and Name as k/v-pair, e.g. array('Name' => 'Gold', 'Amount' => 100)
   * @param misc $GameCurrency the game currency spent, can be an array if there are more than one game currency (e.g. resources in a strategy game)
   * @param int $Quantity
   * @param bool $IsFreeAction
   * @param array $Data for overwriting DefaultData values
   */
  public function TrackVirtualGoodsItemPurchase($ItemType, $Item, $VirtualCurrencyAmount, $GameCurrency = null, $Quantity = 1, $IsFreeAction = false, $Data = array()) {
    if(is_array($VirtualCurrencyAmount) && !isset($Data['VirtualCurrencyName']) && isset($VirtualCurrencyAmount['Name'])) {
      $Data['VirtualCurrencyName'] = $VirtualCurrencyAmount['Name'];
      $VirtualCurrencyAmount = $VirtualCurrencyAmount['Amount'];
    } elseif(is_array($VirtualCurrencyAmount))
      $VirtualCurrencyAmount = $VirtualCurrencyAmount['Amount'];

    return $this->Track('VirtualGoods::Item::Buy::'.$ItemType, array_merge(array(
      'Value' => $VirtualCurrencyAmount,
      'Quantity' => $Quantity,
      'GameCurrency' => json_encode($GameCurrency),
      'IsFreeAction' => $IsFreeAction?'true':'false',
      'ItemType' => $ItemType,
      'Item' => json_encode($Item)
    ), $Data));
  }

  /**
   * tracks the level up of an user
   * if your game has no user levels please try to find a similar value for this, levels should represents the progress of an user and is
   * very important for analytics purposes of different time based game states
   * a possible solution for a soccer game could be the league of the user, for strategic build & raid games could be the number of bases or tech tree activations
   * at least, if there is no such level available for your game, we'll auto create levels by using an exponential diff between signup time and last login
   *
   * @param int $Level starts with 1 and is infite
   * @param array $Data for overwriting DefaultData values
   */
  public function TrackLevelup($Level, $Data = array()) {
    return $this->Track('User::Levelup', array_merge(array(
      'Value' => $Level
    ), $Data));
  }

  /**
   * tracks the usage of a single game feature, e.g. make a game, fight a battle, start a construction, skill your character, etc.
   *
   * @param string $FeatureType e.g. Training
   * @param string $FeatureSubType e.g. Strength
   * @param misc $GameCurrency the game currency spent, can be an array if there are more than one game currency (e.g. resources in a strategy game)
   * @param int $Quantity
   * @param string $FeatureThirdType whatever you want, the tree order is $FeatureType->$FeatureSubType->$FeatureThirdType
   * @param array $Data for overwriting DefaultData values
   */
  public function TrackFeatureUsage($FeatureType, $FeatureSubType, $FeatureSubSubType = null, $GameCurrency = null, $Quantity = 1, $Data = array()) {
    return $this->Track('Feature::Usage::'.$FeatureType.'::'.$FeatureSubType, array_merge(array(
      'FeatureType' => $FeatureType,
      'FeatureSubType' => $FeatureSubType,
      'FeatureThirdType' => $FeatureSubSubType,
      'GameCurrency' => json_encode($GameCurrency),
      'Quantity' => $Quantity
    ), $Data));
  }

  /**
   * tracks the invitation of a friend / friends, e.g. useful for facebook wall message posts
   *
   * @param string $InviteType defines the type of invitation in your game, e.g. Neighbor Invitation
   * @param string $InviteMessageToken defines the message id which was sent
   * @param int $Quantity defines the number of invitations send at once
   * @param array $Data for overwriting DefaultData values
   */
  public function TrackViralityInvitation($InviteType, $InviteMessageToken, $Quantity = 1, $Data = array()) {
    return $this->Track('Virality::Invitation::'.$InviteType, array_merge(array(
      'InviteType' => $InviteType,
      'InviteMessageToken' => $InviteMessageToken,
      'Quantity' => $Quantity
    ), $Data));
  }

  /**
   * tracks the invitation acceptance of a new user
   * you to have add the invite type, message token and the inviting unique customer token to the invitation message and use these values in this tracking call
   *
   * @param string $InviteType defines the type of invitation in your game, e.g. Neighbor Invitation
   * @param string $InviteMessageToken defines the message id which was sent
   * @param string $SourceUniqueCustomerIdentifier defines the unique customer token which sent the invitation
   * @param array $Data for overwriting DefaultData values
   */
  public function TrackViralityInviteAcceptance($InviteType, $InviteMessageToken, $SourceUniqueCustomerIdentifier, $Data = array()) {
    return $this->Track('Virality::Invitation::Acceptance::'.$InviteType, array_merge(array(
      'InviteType' => $InviteType,
      'InviteMessageToken' => $InviteMessageToken,
      'SourceUniqueCustomerIdentifier' => $SourceUniqueCustomerIdentifier,
    ), $Data));
  }

  /**
   * tracks the gender of a user
   *
   * @param string $Gender should only contain male or female
   * @param array $Data for overwriting DefaultData values
   */
  public function TrackUserGender($Gender, $Data = array()) {
    return $this->Track('User::Profile', array_merge(array(
      'Type' => 'Gender',
      'Value' => $Gender
    ), $Data));
  }

  /**
   * tracks the birthyear of a user
   *
   * @param int $Birthyear e.g. 1975, 1980, 1991
   * @param array $Data for overwriting DefaultData values
   */
  public function TrackUserBirthyear($Birthyear, $Data = array()) {
    return $this->Track('User::Profile', array_merge(array(
      'Type' => 'Birthyear',
      'Value' => $Birthyear
    ), $Data));
  }

  /**
   * tracks a custom classification of the user
   *
   * @param string $CustomStaticClassification e.g. 'group B', 'users 001'...
   * @param array $Data for overwriting DefaultData values
   */
  public function TrackUserCustomStaticClassification($CustomStaticClassification, $Data = array()) {
    return $this->Track('User::Profile', array_merge(array(
      'Type' => 'CustomStaticClassification',
      'Value' => $CustomStaticClassification
    ), $Data));
  }


  /***********************************************************************************/
  /* protected methods
  /***********************************************************************************/

  /**
   * returns a customer click token saved in cookies, if no cookie available it returns false
   *
   * @param misc $CookieValue
   */
  public function getUniqueCustomerClickToken($CookieValue = null) {
    $CookieValue = is_null($CookieValue)?(isset($_COOKIE['HTCTR'])?$_COOKIE['HTCTR']:null):$CookieValue;

    if($CookieValue) {
      $CookieValue = unserialize(base64_decode($CookieValue));
      if(!is_array($CookieValue) || sha1(sha1($CookieValue['S']).$CookieValue['T'].str_rot13(md5($CookieValue['S']))) != $CookieValue['C'])
        return false;
      else
        return $CookieValue['C'];
    }
    return false;
  }

  /**
   * sets a random generated customer click token as cookie to use it for trackSignup() later
   *
   * @param $MarketingIdentifier
   * @param int $ExpireDays
   * @param null $CookieDomain
   * @return string
   */
  public function setUniqueCustomerClickToken($MarketingIdentifier, $ExpireDays = 14, $CookieDomain = null) {
    $Salt = crc32(uniqid(mt_rand(), true));
    $CustomerToken = sha1(sha1($Salt).$MarketingIdentifier.str_rot13(md5($Salt)));
    setcookie(
      'HTCTR',
      base64_encode(serialize(array('T' => $MarketingIdentifier, 'S' => $Salt, 'C' => $CustomerToken))),
      time() + (86400 * $ExpireDays),
      '/',
      !is_null($CookieDomain)?$CookieDomain:null
    );
    return $CustomerToken;
  }

  /**
   * returns the tracking url
   */
  protected function getTrackingUrl() {
    return sprintf(defined('HONEYTRACKS_TRACKER_URL')?HONEYTRACKS_TRACKER_URL:$this->Configuration['TRACKING_URL'], $this->ApiKey);
  }

  /**
   * determine the client ip from several headers
   *
   * regarding privacy concerns the last octet will not transfered to tracking servers
   * this method accepts IPv4 and IPv6 addresses, but country detection based on ip will work only for IPv4 addresses correctly
   */
  protected function setClientIP() {
    if(is_null($this->DefaultData['ClientIP'])) {
      foreach(array('REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_FORWARDED', 'HTTP_VIA', 'HTTP_X_COMING_FROM') AS $key) {
        if(
          key_exists($key, $_SERVER) &&
          (
          $tmp = $_SERVER[$key]
          ) &&
          preg_match('/^(?:(?>(?>([a-f0-9]{1,4})(?>:(?1)){7})|(?>(?!(?:.*[a-f0-9](?>:|$)){8,})((?1)(?>:(?1)){0,6})?::(?2)?))|(?>(?>(?>(?1)(?>:(?1)){5}:)|(?>(?!(?:.*[a-f0-9]:){6,})((?1)(?>:(?1)){0,4})?::(?>(?3):)?))?(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])(?>\.(?4)){3}))$/iD', $tmp)
        ) $this->DefaultData['ClientIP'] = $tmp;
      }
    }

    if(!is_null($this->DefaultData['ClientIP'])) {
      if(!isset($this->DefaultData['ClientIP']))
        $this->DefaultData['ClientIP'] = null;

      // remove the last octet regarding privacy concerns
      if(strpos($this->DefaultData['ClientIP'], ':') === false) // IPv4
        $this->DefaultData['ClientIP'] = preg_replace('/\.([0-9]{1,3})$/', '.xxx', $this->DefaultData['ClientIP']);
      else // IPv6
        $this->DefaultData['ClientIP'] = preg_replace('/:([0-9a-f]{1,4})$/', ':xxxx', $this->DefaultData['ClientIP']);
    }
  }

  /**
   * adds a packet to the queue
   *
   * @param array $data
   * @return bool
   */
  protected function AddHTTPTrackingCall($data) {
    foreach($this->RequiredValues AS $key) {
      if(
        (!isset($data[$key]) || strlen($data[$key]) == 0) &&
        !(
          $key == 'UniqueCustomerIdentifier' &&
          isset($data['Action']) &&
          ($data['Action'] == 'User::Click' || strpos($data['Action'], 'Feature::Usage') !== false) &&
          isset($data['UniqueCustomerClickToken'])
        )
      )
        throw new TrackerLibraryException(5, $key, print_r($data, true));
    }

    /**
     * avoid adding events to the http queue if the event user is blocked for events
     */
    if(sizeof(static::$IgnoredCustomers) > 0 && isset(static::$IgnoredCustomers[sha1($data['Space'].'::'.$data['UniqueCustomerIdentifier'])]))
      return false;

    $this->__HTTP_POST_QUEUE[] = $data;

    if(sizeof($this->__HTTP_POST_QUEUE) >= 9)
      $this->ExecuteHTTPQueue();

    return true;
  }

  /**
   * runs through the packets queue and send the packets to the tracking server within one http call
   */
  protected function ExecuteHTTPQueue() {
    if(is_array($this->__HTTP_POST_QUEUE) && sizeof($this->__HTTP_POST_QUEUE) > 0) {
      $keepSending = true;
      $transportOk = false;
      $callTry = 0;;
      do {
        try {
          $callTry++;
          $response = $this->HTTPTrackingCall(array('Packets' => $this->__HTTP_POST_QUEUE));

          if(preg_match('/ok/', $response)) {
            $keepSending = false;
            $transportOk = true;
          } elseif($callTry >= $this->Configuration['NUMBER_OF_CALL_RETRIES']) {
            $keepSending = false;
          }
        } catch(TrackerLibraryTransportException $Exception) {
          if($callTry == $this->Configuration['NUMBER_OF_CALL_RETRIES'])
            $keepSending = false;
        }
      } while($keepSending === true);

      if($transportOk === false)
        $this->SavePackets($this->LastPOSTContent);

      $this->__HTTP_POST_QUEUE = array();
      return $transportOk;
    }
    return false;
  }

  /**
   * store packets which were not sent on filesystem
   * this only happens if PACKETS_STORAGE_PATH configuration value was set a package sending
   * failed or the SEND_IMMEDIATELY configuration value was set to (bool)false
   *
   * @string $Data
   * @return misc
   */
  protected function SavePackets($Data = null) {
    if(is_null($Data))
      $Data = $this->LastPOSTContent;
    if(
      !is_null($this->Configuration['PACKETS_STORAGE_PATH']) &&
      is_string($this->Configuration['PACKETS_STORAGE_PATH']) &&
      @file_exists($this->Configuration['PACKETS_STORAGE_PATH']) &&
      @is_dir($this->Configuration['PACKETS_STORAGE_PATH']) &&
      @is_writable($this->Configuration['PACKETS_STORAGE_PATH'])
    ) {
      $date = gmdate('Y-m-d');
      $hour = gmdate('H');
      $minute = gmdate('i');
      $second = gmdate('s');
      $path = 'tracking_data_'.$date.'_'.$hour.'-'.$minute.'-'.$second.'-'.getmypid();
      $path = $this->Configuration['PACKETS_STORAGE_PATH'].(substr($this->Configuration['PACKETS_STORAGE_PATH'], -1)!='/'?'/':'').$path;

      $fp = @fopen($path, 'a');
      if($fp) {
        @flock($fp, LOCK_EX);
        @fputs($fp, time().': '.$Data."\n");
        @flock($fp, LOCK_UN);
        @fflush($fp);
        @fclose($fp);
        return true;
      }
    }
    return false;
  }

  protected function HTTPTrackingCall($data) {
    return $this->HTTPPost(
      sprintf(
        defined('HONEYTRACKS_TRACKER_URL')?HONEYTRACKS_TRACKER_URL:$this->Configuration['TRACKING_URL'],
        $this->ApiKey,
        @$this->createTrackingCallToken($data['Packets'])
      ),
      $data
    );
  }

  /**
   * executes the http call to tracking server
   *
   * @param string $url
   * @param array $data
   * @throws TrackerLibraryTransportException
   */
  protected function HTTPPost($url, $data) {
    $postContent = array();

    // no recursion, the expected format is static
    foreach($data AS $varName => $varValue) {
      if(is_array($varValue)) {
        foreach($varValue AS $subVarName => $subVarValue) {
          if(is_array($subVarValue)) {
            foreach($subVarValue AS $sub2VarName => $sub2VarValue) {
              if(is_array($sub2VarValue)) {
                foreach($sub2VarValue AS $sub3VarName => $sub3VarValue) {
                  $postContent[] = urlencode($varName).'['.urlencode($subVarName).']['.urlencode($sub2VarName).']['.urlencode($sub3VarName).']='.urlencode($sub3VarValue);
                }
              } else
                $postContent[] = urlencode($varName).'['.urlencode($subVarName).']['.urlencode($sub2VarName).']='.urlencode($sub2VarValue);
            }
          } else
            $postContent[] = urlencode($varName).'['.urlencode($subVarName).']='.urlencode($subVarValue);
        }
      } else
        $postContent[] = urlencode($varName).'='.urlencode($varValue);
    }

    $this->LastPOSTContent = implode('&', $postContent);
    if($this->Configuration['SEND_IMMEDIATELY'] !== true && !is_null($this->Configuration['PACKETS_STORAGE_PATH'])) {
      return $this->SavePackets($this->LastPOSTContent)?'ok':'failed';
    } else {
      if($this->Configuration['USE_CURL'] !== true) {
        $params = array(
          'http' => array(
            'method' => 'POST',
            'content' => $this->LastPOSTContent,
            'timeout' => 1,
            'header' => 'Content-Type: application/x-www-form-urlencoded'."\r\n"
          )
        );

        $ctx = stream_context_create($params);
        $fp = @fopen($url, 'rb', false, $ctx);
        if(!$fp) {
          $errorMessage = error_get_last();
          throw new TrackerLibraryTransportException(1, $url, $errorMessage['message']);
        }
        $response = @stream_get_contents($fp);
        @fclose($fp);
        if($response === false) {
          $errorMessage = error_get_last();
          throw new TrackerLibraryTransportException(2, $url, $errorMessage['message']);
        }
      } else {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POSTFIELDS,  $this->LastPOSTContent);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));
        // some php implementations have a libcurl linked which has not the possibility to track timeouts lower than one second
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $this->Configuration['CURL_CURLOPT_TIMEOUT_MS']);
        $response = curl_exec($ch);
        if(!$response) {
          $error = curl_error($ch);
          @curl_close($ch);
          throw new TrackerLibraryTransportException(2, $url, $error);
        }
        curl_close($ch);
      }
      return $response;
    }
  }

  /**
   * create a seal token for the given data
   *
   * @param array $data
   * @return string
   */
  private function createTrackingCallToken(array $data) {
    $time = time();
    $chk = json_encode($data);
    return '$sha1-htv2$'.$time.'$'.sha1(
      $this->ApiKey.'::'.$this->SecretKey.'::'.$chk.'::'.$time
    );
  }
}
