<?php
  /**
   *
   *
   * User: degola
   * Date: 11.11.16
   * Time: 10:34
   */

namespace HoneyTracks;

class TrackerLibraryException extends \Exception {
  const NO_VALID_EXCEPTION_CODE = '%s is not a valid exception code';
  protected $EXCEPTION_BASE_CODE = 100000000;
  protected $EXCEPTIONS = array(
    1 => 'default data needs a valid value for key %2$s',
    2 => 'can\'t return a singleton instance of tracker library class, no default data was given and no instance exists',
    3 => 'tracking data have to be an array',
    4 => 'setOptions parameter have to be an array',
    5 => 'missing value for %2$s, %3$s',
    6 => '%2$s is not a valid configuration option'
  );

  /**
   * exception constructor
   * expects at least the exception code, further arguments will be used for replacing printf-variables within exception message
   * @param int $code
   */
  public function __construct($code) {
    $argc = func_num_args();
    $argv = func_get_args();
    $this->arguments = $argv;
    $message = null;

    if(!key_exists($code, $this->EXCEPTIONS)) $message = TrackerLibraryException::NO_VALID_EXCEPTION_CODE;
    else $message = $this->EXCEPTIONS[$code];

    if($argc > 1 || $message == TrackerLibraryException::NO_VALID_EXCEPTION_CODE) {
      if(!is_array($this->getData(1))) {
        $message = vsprintf($message, $argv);
      } elseif(sizeof($tmp = $this->getData(1)) > 0) {
        array_unshift($tmp, $argv[0]);
        $message = vsprintf($message, $tmp);
        unset($tmp);
      }
      parent::__construct($message, intval($code) + intval($this->EXCEPTION_BASE_CODE));
    }
  }

  /**
   * returns a supplied additional exception argument
   *
   * @param int $id
   * @return misc
   */
  public function getData($id = 2) {
    if(is_array($this->arguments) && key_exists($id, $this->arguments))
      return $this->arguments[$id];
    return null;
  }
}
