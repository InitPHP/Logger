<?php
/**
 * Logger.php
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

use \Psr\Log\LoggerInterface;

/**
 * @method void emergency(string $message, array $context = array())
 * @method void alert(string $message, array $context = array())
 * @method void critical(string $message, array $context = array())
 * @method void error(string $message, array $context = array())
 * @method void warning(string $message, array $context = array())
 * @method void notice(string $message, array $context = array())
 * @method void info(string $message, array $context = array())
 * @method void debug(string $message, array $context = array())
 * @method void log(string $level, string $message, array $context = array())
 */
class Logger
{
    /** @var LoggerInterface[] */
    protected $loggers = [];

    public function __construct(...$loggers)
    {
        foreach ($loggers as $log) {
            if($log instanceof LoggerInterface){
                $this->loggers[] = $log;
            }
        }
    }

    public function __call($name, $arguments)
    {
        foreach ($this->loggers as $logger) {
            $logger->{$name}(...$arguments);
        }
    }

}
