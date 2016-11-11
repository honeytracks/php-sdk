<?php
  /**
   *
   *
   * User: degola
   * Date: 11.11.16
   * Time: 10:35
   */

namespace HoneyTracks;

class TrackerLibraryTransportException extends TrackerLibraryException {
  protected $EXCEPTION_BASE_CODE = 100001000;
  protected $EXCEPTIONS = array(
    1 => 'can\'t open the url %1$s - %2$s',
    2 => 'can\'t read data from url %1$s - %2$s'
  );
}
