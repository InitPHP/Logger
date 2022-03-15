<?php
/**
 * HelperTrait.php
 *
 * This file is part of InitPHP.
 *
 * @author     Muhammet ŞAFAK <info@muhammetsafak.com.tr>
 * @copyright  Copyright © 2022 InitPHP
 * @license    http://initphp.github.io/license.txt  MIT
 * @version    1.0
 * @link       https://www.muhammetsafak.com.tr
 */

namespace InitPHP\Logger;

use \DateTime;
use \Psr\Log\InvalidArgumentException;
use \Psr\Log\LogLevel;

use function strtolower;
use function in_array;
use function implode;
use function is_array;
use function is_object;
use function method_exists;
use function strtr;

trait HelperTrait
{
    /** @var array */
    private $levels = array(
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        LogLevel::INFO,
        LogLevel::DEBUG
    );

    /**
     * @param string $msg
     * @param array $context
     * @return string
     */
    protected function interpolate($msg, array $context = array())
    {
        if(empty($context)){
            return $msg;
        }else{
            $replace = array();
            foreach ($context as $key => $val) {
                if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                    $replace['{' . $key . '}'] = $val;
                }
            }
            return strtr($msg, $replace);
        }
    }

    /**
     * @param string $format
     * @return string
     */
    protected function getDate($format = 'c')
    {
        return (new DateTime('now'))->format($format);
    }

    /**
     * @param $level
     * @return void
     */
    protected function logLevelVerify($level)
    {
        if(in_array(strtolower($level), $this->levels, true) === FALSE){
            throw new InvalidArgumentException('Only ' . implode(', ', $this->levels) . ' levels can be logged.');
        }
    }
}
